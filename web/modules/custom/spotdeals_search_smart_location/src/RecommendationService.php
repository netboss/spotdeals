<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\spotdeals_search_smart_location\Service\DealFreshnessScorer;

/**
 * Builds a recommendation result on top of near-me ranking.
 */
final class RecommendationService {

  /**
   * Default radius in kilometers.
   */
  private const DEFAULT_RADIUS_KM = 40.25;

  /**
   * Maximum number of near-me candidates to inspect.
   */
  private const CANDIDATE_LIMIT = 50;

  /**
   * Distance bands to exhaust before broader nearby-city choices.
   */
  private const DISTANCE_BAND_LIMITS_KM = [10.0, 15.0, 20.0, 25.0];

  /**
   * Maximum number of top ties to randomize across.
   */
  private const RANDOM_TIE_LIMIT = 7;

  /**
   * Candidate has at least one positive Worth it signal and no hard negative.
   */
  private const QUALITY_TIER_POSITIVE = 0;

  /**
   * Candidate has no Worth it signal yet.
   */
  private const QUALITY_TIER_UNRATED = 1;

  /**
   * Candidate has explicit negative Worth it feedback.
   */
  private const QUALITY_TIER_NEGATIVE = 2;

  /**
   * Constructs a recommendation service.
   */
  public function __construct(
    private readonly NearMeRanker $nearMeRanker,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DealFreshnessScorer $freshnessScorer,
  ) {}

  /**
   * Returns one recommended deal node ID.
   *
   * The recommendation logic:
   * - starts from near-me candidates inside the configured radius
   * - optionally requires overlaps with selected cuisines
   * - excludes previously shown venues for the current session
   * - keeps only the best deal per venue
   * - randomizes only inside the strongest top tier
   *
   * @param float $originLat
   *   Origin latitude.
   * @param float $originLon
   *   Origin longitude.
   * @param array<int,string> $cuisines
   *   Optional recommendation cuisines.
   * @param float $radiusKm
   *   Search radius.
   * @param array<int,string> $excludedCuisines
   *   Optional excluded cuisine tokens.
   * @param array<int,int> $excludedVenueNids
   *   Venue node IDs already shown in the current recommendation session.
   *
   * @return array<int,int>
   *   A single recommended deal node ID, or an empty array.
   */
  public function recommendDealNids(
    float $originLat,
    float $originLon,
    array $cuisines = [],
    float $radiusKm = self::DEFAULT_RADIUS_KM,
    array $excludedCuisines = [],
    array $excludedVenueNids = [],
    array $excludedDealNids = [],
    bool $allowRecycle = TRUE,
  ): array {
    $startedAt = microtime(TRUE);

    $cuisines = $this->normalizeCuisineTokens($cuisines);
    $excludedCuisines = $this->normalizeCuisineTokens($excludedCuisines);
    $excludedVenueNids = array_values(array_unique(array_filter(
      array_map('intval', $excludedVenueNids),
      static fn(int $nid): bool => $nid > 0
    )));
    $excludedDealNids = array_values(array_unique(array_filter(
      array_map('intval', $excludedDealNids),
      static fn(int $nid): bool => $nid > 0
    )));

    $preferredLocalities = $this->localitiesForVenueNids($excludedVenueNids);
    $radiusAttempts = $this->radiusAttempts($radiusKm);
    $candidateSets = [];
    $candidateAttemptMeta = [];

    $tryCandidateSet = function (array $candidateSet, array $attemptMeta) use (
      &$candidateSets,
      &$candidateAttemptMeta,
      $preferredLocalities,
      $startedAt,
      $cuisines,
      $excludedCuisines,
      $excludedVenueNids,
      $excludedDealNids,
      $radiusKm
    ): ?array {
      $candidateSets[] = $candidateSet;
      $candidateAttemptMeta[] = $attemptMeta;

      $picked = $this->pickFromCandidateSets([$candidateSet], $preferredLocalities);
      if ($picked === NULL) {
        return NULL;
      }

      $setSizes = array_map(static fn(array $set): int => count($set), $candidateSets);
      $attemptSizesJson = json_encode($setSizes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
      $selectedAttempt = count($candidateSets);

      $this->logTiming(sprintf(
        'SMART LOCATION recommendation timing: total_ms="%s" cuisines_count="%d" excluded_cuisines_count="%d" excluded_venues_count="%d" excluded_deals_count="%d" requested_radius_km="%s" selected_radius_km="%s" attempts_built="%d" attempt_sizes="%s" selected_attempt="%d" top_tier_count="%d" picked_deal_nid="%d" picked_venue_nid="%d" recycled="%s"',
        $this->formatMs($startedAt),
        count($cuisines),
        count($excludedCuisines),
        count($excludedVenueNids),
        count($excludedDealNids),
        (string) $radiusKm,
        (string) ($attemptMeta['radius_km'] ?? $radiusKm),
        count($candidateSets),
        $attemptSizesJson,
        $selectedAttempt,
        (int) $picked['top_tier_count'],
        (int) $picked['candidate']['deal_nid'],
        (int) $picked['candidate']['venue_nid'],
        !empty($attemptMeta['recycled']) ? '1' : '0',
      ));

      return [(int) $picked['candidate']['deal_nid']];
    };

    foreach ($radiusAttempts as $attemptRadiusKm) {
      $pickedDealNids = $tryCandidateSet(
        $this->buildCandidates(
          $originLat,
          $originLon,
          $cuisines,
          $attemptRadiusKm,
          $excludedCuisines,
          $excludedVenueNids,
          $excludedDealNids
        ),
        [
          'radius_km' => $attemptRadiusKm,
          'recycled' => FALSE,
        ]
      );
      if ($pickedDealNids !== NULL) {
        return $pickedDealNids;
      }

      if (!empty($excludedCuisines)) {
        $pickedDealNids = $tryCandidateSet(
          $this->buildCandidates(
            $originLat,
            $originLon,
            $cuisines,
            $attemptRadiusKm,
            [],
            $excludedVenueNids,
            $excludedDealNids
          ),
          [
            'radius_km' => $attemptRadiusKm,
            'recycled' => FALSE,
          ]
        );
        if ($pickedDealNids !== NULL) {
          return $pickedDealNids;
        }
      }
    }

    // If every strict relevant result has already been shown, start the strict
    // cycle over without the oldest exclusions, but keep the most recently shown
    // deal excluded so Try again does not immediately repeat the same card.
    // This preserves relevance for chips such as arepas/wine while still making
    // low-inventory searches usable after their result pool is exhausted.
    if ($allowRecycle && !empty($excludedDealNids)) {
      $recentDealNid = (int) end($excludedDealNids);
      $recycleExcludedDealNids = $recentDealNid > 0 ? [$recentDealNid] : [];
      foreach ($radiusAttempts as $attemptRadiusKm) {
        $pickedDealNids = $tryCandidateSet(
          $this->buildCandidates(
            $originLat,
            $originLon,
            $cuisines,
            $attemptRadiusKm,
            $excludedCuisines,
            [],
            $recycleExcludedDealNids
          ),
          [
            'radius_km' => $attemptRadiusKm,
            'recycled' => TRUE,
          ]
        );
        if ($pickedDealNids !== NULL) {
          return $pickedDealNids;
        }

        if (!empty($excludedCuisines)) {
          $pickedDealNids = $tryCandidateSet(
            $this->buildCandidates(
              $originLat,
              $originLon,
              $cuisines,
              $attemptRadiusKm,
              [],
              [],
              $recycleExcludedDealNids
            ),
            [
              'radius_km' => $attemptRadiusKm,
              'recycled' => TRUE,
            ]
          );
          if ($pickedDealNids !== NULL) {
            return $pickedDealNids;
          }
        }
      }
    }

    $setSizes = array_map(static fn(array $set): int => count($set), $candidateSets);
    $attemptSizesJson = json_encode($setSizes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';

    $this->logTiming(sprintf(
      'SMART LOCATION recommendation timing: total_ms="%s" cuisines_count="%d" excluded_cuisines_count="%d" excluded_venues_count="%d" excluded_deals_count="%d" requested_radius_km="%s" attempts_built="%d" attempt_sizes="%s" selected_attempt="0" top_tier_count="0" picked_deal_nid="" picked_venue_nid=""',
      $this->formatMs($startedAt),
      count($cuisines),
      count($excludedCuisines),
      count($excludedVenueNids),
      count($excludedDealNids),
      (string) $radiusKm,
      count($candidateSets),
      $attemptSizesJson,
    ));

    return [];
  }

  /**
   * Returns one recommended deal from an already-localized result set.
   *
   * This is used by SEO landing pages where city/category/search filtering has
   * already produced the eligible local deal pool. It reuses the same candidate
   * scoring, vote-quality filtering, and session retry behavior as the regular
   * recommendation engine, but it does not require browser geolocation.
   *
   * @param array<int,int> $dealNids
   *   Local eligible deal node IDs in display order.
   * @param array<int,string> $cuisines
   *   Optional preference tokens.
   * @param array<int,int> $excludedVenueNids
   *   Venue node IDs already shown in the current recommendation session.
   * @param array<int,int> $excludedDealNids
   *   Deal node IDs already shown in the current recommendation session.
   * @param bool $allowRecycle
   *   Whether to restart the cycle after every strict local candidate has been
   *   shown. The most recent deal stays excluded to avoid immediate repeats.
   *
   * @return array<int,int>
   *   A single recommended deal node ID, or an empty array.
   */
  public function recommendFromDealNids(
    array $dealNids,
    array $cuisines = [],
    array $excludedVenueNids = [],
    array $excludedDealNids = [],
    bool $allowRecycle = TRUE,
  ): array {
    $startedAt = microtime(TRUE);

    $dealNids = array_values(array_unique(array_filter(
      array_map('intval', $dealNids),
      static fn(int $nid): bool => $nid > 0
    )));
    $cuisines = $this->normalizeCuisineTokens($cuisines);
    $excludedVenueNids = array_values(array_unique(array_filter(
      array_map('intval', $excludedVenueNids),
      static fn(int $nid): bool => $nid > 0
    )));
    $excludedDealNids = array_values(array_unique(array_filter(
      array_map('intval', $excludedDealNids),
      static fn(int $nid): bool => $nid > 0
    )));

    if (empty($dealNids)) {
      return [];
    }

    $preferredLocalities = $this->localitiesForVenueNids($excludedVenueNids);
    $candidateSets = [
      $this->buildCandidatesFromDealNids($dealNids, $cuisines, $excludedVenueNids, $excludedDealNids),
    ];

    if ($allowRecycle && !empty($excludedDealNids)) {
      $recentDealNid = (int) end($excludedDealNids);
      $recycleExcludedDealNids = $recentDealNid > 0 ? [$recentDealNid] : [];
      $candidateSets[] = $this->buildCandidatesFromDealNids($dealNids, $cuisines, [], $recycleExcludedDealNids);
    }

    $setSizes = array_map(static fn(array $set): int => count($set), $candidateSets);
    $attemptSizesJson = json_encode($setSizes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';

    $picked = $this->pickFromCandidateSets($candidateSets, $preferredLocalities);
    if ($picked !== NULL) {
      $this->logTiming(sprintf(
        'SMART LOCATION local SEO recommendation timing: total_ms="%s" local_candidates="%d" cuisines_count="%d" excluded_venues_count="%d" excluded_deals_count="%d" attempts_built="%d" attempt_sizes="%s" selected_attempt="%d" top_tier_count="%d" picked_deal_nid="%d" picked_venue_nid="%d"',
        $this->formatMs($startedAt),
        count($dealNids),
        count($cuisines),
        count($excludedVenueNids),
        count($excludedDealNids),
        count($candidateSets),
        $attemptSizesJson,
        (int) $picked['attempt_index'],
        (int) $picked['top_tier_count'],
        (int) $picked['candidate']['deal_nid'],
        (int) $picked['candidate']['venue_nid'],
      ));

      return [(int) $picked['candidate']['deal_nid']];
    }

    $this->logTiming(sprintf(
      'SMART LOCATION local SEO recommendation timing: total_ms="%s" local_candidates="%d" cuisines_count="%d" excluded_venues_count="%d" excluded_deals_count="%d" attempts_built="%d" attempt_sizes="%s" selected_attempt="0" top_tier_count="0" picked_deal_nid="" picked_venue_nid=""',
      $this->formatMs($startedAt),
      count($dealNids),
      count($cuisines),
      count($excludedVenueNids),
      count($excludedDealNids),
      count($candidateSets),
      $attemptSizesJson,
    ));

    return [];
  }

  /**
   * Picks the best eligible candidate from ordered candidate attempts.
   *
   * @param array<int,array<int,array<string,int|float|string|bool>>> $candidateSets
   *   Candidate sets in attempt order.
   * @param array<int,string> $preferredLocalities
   *   Ordered normalized venue localities already shown in the current
   *   recommendation session. Recommendations keep using the earliest locality
   *   while candidates from that locality still exist, then expand outward.
   *
   * @return array{candidate:array<string,int|float|string|bool>,attempt_index:int,top_tier_count:int}|null
   *   Picked candidate metadata, or NULL when no eligible candidate exists.
   */
  private function pickFromCandidateSets(array $candidateSets, array $preferredLocalities = []): ?array {
    foreach ($candidateSets as $attemptIndex => $candidates) {
      if (empty($candidates)) {
        continue;
      }

      // Keep broad recommendation searches distance-band-first before applying
      // vote quality. Otherwise a farther positive-vote venue can remove
      // closer unrated candidates before distance preference gets a chance to
      // run.
      $candidates = $this->preferDistanceBandCandidatesByQuality($candidates);

      // Choose the nearest available locality before vote-quality filtering.
      // Without this, a positive-vote Port Orange deal can beat an unrated New
      // Smyrna Beach deal even when the user is physically in New Smyrna Beach.
      $localityPreferenceOrder = $this->localityPreferenceOrder($candidates, $preferredLocalities);
      $candidates = $this->preferLocalityCandidatesByQuality($candidates, $localityPreferenceOrder);

      $beforeQuality = $candidates;
      $candidates = $this->filterRecommendationCandidatesByVoteQuality($candidates);
      if (empty($candidates)) {
        continue;
      }

      usort($candidates, fn(array $a, array $b): int => $this->compareCandidates($a, $b));

      $top = $candidates[0];
      $topTier = array_values(array_filter(
        $candidates,
        static function (array $candidate) use ($top): bool {
          if (($candidate['quality_tier'] ?? self::QUALITY_TIER_UNRATED) !== ($top['quality_tier'] ?? self::QUALITY_TIER_UNRATED)) {
            return FALSE;
          }

          if (($candidate['preference_mode'] ?? FALSE) !== ($top['preference_mode'] ?? FALSE)) {
            return FALSE;
          }

          if (!empty($top['preference_mode']) && $candidate['overlap_count'] !== $top['overlap_count']) {
            return FALSE;
          }

          // Randomize only across the closest same-quality recommendations.
          // The previous score-window randomization could jump from nearby New
          // Smyrna Beach options to Port Orange/Daytona Beach options because
          // votes/freshness made far candidates look equivalent. rank_index is
          // produced by NearMeRanker and already includes the near-me ordering.
          $candidateRank = (int) ($candidate['rank_index'] ?? PHP_INT_MAX);
          $topRank = (int) ($top['rank_index'] ?? PHP_INT_MAX);

          return $candidateRank <= ($topRank + 3);
        }
      ));

      if (empty($topTier)) {
        $topTier = [$top];
      }

      $topTier = array_slice($topTier, 0, self::RANDOM_TIE_LIMIT);

      return [
        'candidate' => $topTier[0],
        'attempt_index' => ((int) $attemptIndex) + 1,
        'top_tier_count' => count($topTier),
      ];
    }

    return NULL;
  }

  /**
   * Returns strict radius attempts for recommendation retry.
   *
   * Recommendation mode must be local-first, but not local-only forever. Try
   * again exhausts strict relevant results inside the requested radius first,
   * then expands outward in controlled steps before recycling.
   *
   * @return array<int,float>
   *   Radius attempts in kilometers.
   */
  private function radiusAttempts(float $requestedRadiusKm): array {
    $baseRadiusKm = max(0.1, $requestedRadiusKm);
    $radii = [
      $baseRadiusKm,
      max($baseRadiusKm, 50.0),
      max($baseRadiusKm, 80.47),
      max($baseRadiusKm, 120.0),
      max($baseRadiusKm, 160.93),
      402.34,
      1000.0,
    ];

    return array_values(array_unique(array_map(
      static fn(float $radius): float => round($radius, 2),
      array_filter($radii, static fn(float $radius): bool => $radius >= $baseRadiusKm)
    )));
  }

  /**
   * Builds scored candidates from a fixed local set of deal node IDs.
   *
   * @param array<int,int> $dealNids
   *   Local eligible deal node IDs in display order.
   * @param array<int,string> $cuisines
   *   Normalized preference tokens.
   * @param array<int,int> $excludedVenueNids
   *   Venue node IDs already shown.
   * @param array<int,int> $excludedDealNids
   *   Deal node IDs already shown.
   *
   * @return array<int,array<string,int|float|string|bool>>
   *   Recommendation candidates.
   */
  private function buildCandidatesFromDealNids(
    array $dealNids,
    array $cuisines,
    array $excludedVenueNids = [],
    array $excludedDealNids = [],
  ): array {
    $dealNids = array_values(array_unique(array_filter(
      array_map('intval', $dealNids),
      static fn(int $nid): bool => $nid > 0
    )));
    $excludedVenueNids = array_values(array_unique(array_filter(
      array_map('intval', $excludedVenueNids),
      static fn(int $nid): bool => $nid > 0
    )));
    $excludedDealNids = array_values(array_unique(array_filter(
      array_map('intval', $excludedDealNids),
      static fn(int $nid): bool => $nid > 0
    )));

    if (empty($dealNids)) {
      return [];
    }

    $candidateNids = array_slice($dealNids, 0, self::CANDIDATE_LIMIT);
    $deals = $this->entityTypeManager->getStorage('node')->loadMultiple($candidateNids);
    if (empty($deals)) {
      return [];
    }

    $freshnessScores = $this->freshnessScorer->scoreDealNids($candidateNids);
    $candidateRows = [];
    $candidateVenueNids = [];

    foreach ($candidateNids as $rankIndex => $dealNid) {
      $deal = $deals[$dealNid] ?? NULL;
      if (!$deal instanceof NodeInterface) {
        continue;
      }

      if (in_array((int) $deal->id(), $excludedDealNids, TRUE)) {
        continue;
      }

      $venue = $this->dealVenue($deal);
      if (!$venue instanceof NodeInterface) {
        continue;
      }

      $venueNid = (int) $venue->id();
      if (in_array($venueNid, $excludedVenueNids, TRUE)) {
        continue;
      }

      $candidateRows[] = [
        'deal' => $deal,
        'venue' => $venue,
        'venue_cuisine_tokens' => $this->extractVenueCuisineTokens($venue),
        'rank_index' => (int) $rankIndex,
      ];
      $candidateVenueNids[] = $venueNid;
    }

    if (empty($candidateRows)) {
      return [];
    }

    $venueQualityScores = $this->freshnessScorer->scoreVenueNids($candidateVenueNids);
    $candidates = [];

    foreach ($candidateRows as $row) {
      $deal = $row['deal'];
      $venue = $row['venue'];
      if (!$deal instanceof NodeInterface || !$venue instanceof NodeInterface) {
        continue;
      }

      $dealNid = (int) $deal->id();
      $venueNid = (int) $venue->id();

      $candidate = $this->buildCandidate(
        $deal,
        $venue,
        $cuisines,
        $row['venue_cuisine_tokens'],
        (int) $row['rank_index'],
        $freshnessScores[$dealNid] ?? [],
        $venueQualityScores[$venueNid] ?? [],
        0.0,
        0.0
      );
      if ($candidate === NULL) {
        continue;
      }

      $candidates[] = $candidate;
    }

    return $candidates;
  }

  /**
   * Builds scored recommendation candidates for one attempt.
   *
   * @param array<int,string> $cuisines
   *   Optional normalized cuisine tokens.
   * @param array<int,string> $excludedCuisines
   *   Optional excluded cuisine tokens.
   * @param array<int,int> $excludedVenueNids
   *   Venue node IDs already shown in the current recommendation session.
   * @param array<int,string> $rankingKeywords
   *   Optional normalized tokens used only for near-me ranking while keeping
   *   recommendation scoring relaxed.
   *
   * @return array<int,array<string,int|float|string|bool>>
   *   Recommendation candidates keyed numerically.
   */
  private function buildCandidates(
    float $originLat,
    float $originLon,
    array $cuisines,
    float $radiusKm,
    array $excludedCuisines,
    array $excludedVenueNids,
    array $excludedDealNids = [],
    array $rankingKeywords = [],
  ): array {
    $rankingKeywords = $this->normalizeCuisineTokens($rankingKeywords);
    $excludedDealNids = array_values(array_unique(array_filter(
      array_map('intval', $excludedDealNids),
      static fn(int $nid): bool => $nid > 0
    )));
    $queryTokens = !empty($rankingKeywords) ? $rankingKeywords : $cuisines;
    $query = !empty($queryTokens) ? implode(' ', $queryTokens) : '';

    $rankedNids = $this->nearMeRanker->rankDealNids(
      $originLat,
      $originLon,
      $query,
      $radiusKm
    );

    if (empty($rankedNids)) {
      return [];
    }

    $candidateNids = array_slice(array_values($rankedNids), 0, self::CANDIDATE_LIMIT);
    $deals = $this->entityTypeManager->getStorage('node')->loadMultiple($candidateNids);

    if (empty($deals)) {
      return [];
    }

    $rankIndexMap = array_flip($candidateNids);
    $freshnessScores = $this->freshnessScorer->scoreDealNids($candidateNids);
    $candidateRows = [];
    $candidateVenueNids = [];

    foreach ($candidateNids as $dealNid) {
      $deal = $deals[$dealNid] ?? NULL;
      if (!$deal instanceof NodeInterface) {
        continue;
      }

      if (in_array((int) $deal->id(), $excludedDealNids, TRUE)) {
        continue;
      }

      $venue = $this->dealVenue($deal);
      if (!$venue instanceof NodeInterface) {
        continue;
      }

      $venueNid = (int) $venue->id();

      $venueCuisineTokens = $this->extractVenueCuisineTokens($venue);
      if (!empty($excludedCuisines) && !empty(array_intersect($excludedCuisines, $venueCuisineTokens))) {
        continue;
      }

      $candidateRows[] = [
        'deal' => $deal,
        'venue' => $venue,
        'venue_cuisine_tokens' => $venueCuisineTokens,
        'rank_index' => (int) ($rankIndexMap[$dealNid] ?? PHP_INT_MAX),
      ];
      $candidateVenueNids[] = $venueNid;
    }

    if (empty($candidateRows)) {
      return [];
    }

    $venueQualityScores = $this->freshnessScorer->scoreVenueNids($candidateVenueNids);
    $candidates = [];

    foreach ($candidateRows as $row) {
      $deal = $row['deal'];
      $venue = $row['venue'];
      if (!$deal instanceof NodeInterface || !$venue instanceof NodeInterface) {
        continue;
      }

      $dealNid = (int) $deal->id();
      $venueNid = (int) $venue->id();

      $candidate = $this->buildCandidate(
        $deal,
        $venue,
        $cuisines,
        $row['venue_cuisine_tokens'],
        (int) $row['rank_index'],
        $freshnessScores[$dealNid] ?? [],
        $venueQualityScores[$venueNid] ?? [],
        $originLat,
        $originLon
      );
      if ($candidate === NULL) {
        continue;
      }

      $candidates[] = $candidate;
    }

    return $candidates;
  }

  /**
   * Builds one scored recommendation candidate.
   *
   * @param array<int,string> $cuisines
   *   Normalized cuisine tokens.
   * @param array<int,string> $venueCuisineTokens
   *   Normalized venue cuisine tokens.
   *
   * @param array<string,int|float> $freshness
   *   Freshness score data for the deal.
   * @param array<string,int|float> $venueQuality
   *   Vote quality score data for the venue.
   * @param float $originLat
   *   Origin latitude.
   * @param float $originLon
   *   Origin longitude.
   *
   * @return array<string,int|float|string|bool>|null
   *   Candidate data or NULL.
   */
  private function buildCandidate(
    NodeInterface $deal,
    NodeInterface $venue,
    array $cuisines,
    array $venueCuisineTokens,
    int $rankIndex,
    array $freshness,
    array $venueQuality,
    float $originLat,
    float $originLon,
  ): ?array {
    $dealTitle = $this->normalize((string) $deal->label());
    $dealBody = $this->normalize(
      $deal->hasField('body') && !$deal->get('body')->isEmpty()
        ? (string) $deal->get('body')->value
        : ''
    );
    $venueTitle = $this->normalize((string) $venue->label());
    $venueDescription = $this->normalize(
      $venue->hasField('field_short_description') && !$venue->get('field_short_description')->isEmpty()
        ? (string) $venue->get('field_short_description')->value
        : ''
    );
    $venueCuisine = implode(' ', $venueCuisineTokens);
    $dealOfferText = $this->normalize(
      $deal->hasField('field_price_offer_text') && !$deal->get('field_price_offer_text')->isEmpty()
        ? (string) $deal->get('field_price_offer_text')->value
        : ''
    );
    $venueTags = $this->normalize(
      $venue->hasField('field_tags') && !$venue->get('field_tags')->isEmpty()
        ? $this->venueTagsText($venue)
        : ''
    );

    $haystacks = [
      'venue_cuisine' => $venueCuisine,
      'deal_title' => $dealTitle,
      'venue_title' => $venueTitle,
      'deal_body' => $dealBody,
      'deal_offer_text' => $dealOfferText,
      'venue_description' => $venueDescription,
      'venue_tags' => $venueTags,
    ];

    $preferenceMode = !empty($cuisines);
    if ($preferenceMode && $this->hasBreweryPreference($cuisines) && !$this->venueMatchesBreweryPreference($haystacks)) {
      // \$this->logTiming('RECOMMENDATION FILTER brewery_preference deal_nid="' . (string) $deal->id() . '"');
      return NULL;
    }

    $overlapCount = 0;
    $score = 0;

    if ($preferenceMode) {
      foreach ($cuisines as $cuisine) {
        if ($cuisine === '') {
          continue;
        }

        $matched = FALSE;
        $requiresDealLevelMatch = $this->requiresDealLevelMatch($cuisine);

        if (
          !$requiresDealLevelMatch
          && $haystacks['venue_cuisine'] !== ''
          && $this->textContainsPreferenceToken($haystacks['venue_cuisine'], $cuisine)
        ) {
          $score += 80;
          $matched = TRUE;
        }
        if ($haystacks['deal_title'] !== '' && $this->textContainsPreferenceToken($haystacks['deal_title'], $cuisine)) {
          $score += 45;
          $matched = TRUE;
        }
        if ($haystacks['venue_title'] !== '' && $this->textContainsPreferenceToken($haystacks['venue_title'], $cuisine)) {
          $score += 30;
          $matched = TRUE;
        }
        if ($haystacks['deal_body'] !== '' && $this->textContainsPreferenceToken($haystacks['deal_body'], $cuisine)) {
          $score += 18;
          $matched = TRUE;
        }
        if ($haystacks['deal_offer_text'] !== '' && $this->textContainsPreferenceToken($haystacks['deal_offer_text'], $cuisine)) {
          $score += 40;
          $matched = TRUE;
        }
        if (
          !$requiresDealLevelMatch
          && $haystacks['venue_description'] !== ''
          && $this->textContainsPreferenceToken($haystacks['venue_description'], $cuisine)
        ) {
          $score += 12;
          $matched = TRUE;
        }
        if (
          !$requiresDealLevelMatch
          && $haystacks['venue_tags'] !== ''
          && $this->textContainsPreferenceToken($haystacks['venue_tags'], $cuisine)
        ) {
          $score += 20;
          $matched = TRUE;
        }

        if ($matched) {
          $overlapCount++;
        }
      }

      if ($overlapCount === 0) {
        // \$this->logTiming('RECOMMENDATION FILTER no_overlap deal_nid="' . (string) $deal->id() . '" title="' . (string) $deal->label() . '"');
        return NULL;
      }

      if ($overlapCount > 1) {
        $score += ($overlapCount - 1) * 120;
      }
    }
    else {
      if ($haystacks['venue_cuisine'] !== '') {
        $score += 45;
      }
      if ($haystacks['deal_title'] !== '') {
        $score += 25;
      }
      if ($haystacks['venue_description'] !== '') {
        $score += 15;
      }
      if ($haystacks['venue_tags'] !== '') {
        $score += 12;
      }
      if ($haystacks['deal_body'] !== '') {
        $score += 10;
      }
      if ($venueTitle !== '') {
        $score += 5;
      }
    }

    $freshnessScore = (int) ($freshness['score'] ?? 0);
    $qualityTier = $this->candidateQualityTier($freshness, $venueQuality);

    $score += max(0, 60 - min($rankIndex, 60));
    $score += $freshnessScore;

    if ($qualityTier === self::QUALITY_TIER_POSITIVE) {
      $score += 150;
    }
    elseif ($qualityTier === self::QUALITY_TIER_NEGATIVE) {
      $score -= 500;
    }

    return [
      'deal_nid' => (int) $deal->id(),
      'venue_nid' => (int) $venue->id(),
      'overlap_count' => $overlapCount,
      'score' => $score,
      'freshness_score' => $freshnessScore,
      'quality_tier' => $qualityTier,
      'last_checked' => (int) ($freshness['last_checked'] ?? 0),
      'rank_index' => $rankIndex,
      'distance_km' => $this->venueDistanceKm($venue, $originLat, $originLon),
      'deal_title' => (string) $deal->label(),
      'venue_title' => (string) $venue->label(),
      'venue_locality' => $this->venueLocality($venue),
      'preference_mode' => $preferenceMode,
    ];
  }

  /**
   * Checks one normalized preference token against normalized text.
   *
   * Recommendation matching must use token/phrase boundaries instead of raw
   * substring checks. Raw substring matching allowed queries such as "ice"
   * from the Ice Cream chip to match unrelated words like "rice", which made
   * recommendation mode pick irrelevant deals.
   */
  private function textContainsPreferenceToken(string $text, string $token): bool {
    $text = trim($text);
    $token = trim($token);

    if ($text === '' || $token === '') {
      return FALSE;
    }

    $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($token, '/') . '(?![\p{L}\p{N}])/u';
    return (bool) preg_match($pattern, $text);
  }

  /**
   * Determines whether a token should require deal-level matching.
   */
  private function requiresDealLevelMatch(string $token): bool {
    $broadCuisineTerms = [
      'mexican',
      'italian',
      'american',
      'thai',
      'japanese',
      'chinese',
      'indian',
      'mediterranean',
      'greek',
      'bbq',
      'barbecue',
      'seafood',
      'vegan',
      'vegetarian',
      'tex mex',
      'tex-mex',
      'texmex',
    ];

    return !in_array($token, $broadCuisineTerms, TRUE);
  }

  /**
   * Determines whether the current preference set is brewery-specific.
   *
   * This intentionally treats "craft beer" as brewery intent, but avoids using
   * standalone "beer" as brewery intent because regular restaurants can have
   * beer or draft beer deals without being breweries.
   *
   * @param array<int,string> $tokens
   *   Normalized preference tokens.
   */
  private function hasBreweryPreference(array $tokens): bool {
    $tokens = array_values(array_unique(array_filter($tokens)));
    if (empty($tokens)) {
      return FALSE;
    }

    $breweryTokens = [
      'brewery',
      'breweries',
      'brewpub',
      'taproom',
      'brewing',
    ];

    if (!empty(array_intersect($breweryTokens, $tokens))) {
      return TRUE;
    }

    return in_array('craft', $tokens, TRUE) && in_array('beer', $tokens, TRUE);
  }

  /**
   * Checks whether brewery intent is represented by venue-level data.
   *
   * Deal text is deliberately excluded here. A restaurant with a draft beer or
   * happy hour special should not qualify as a brewery unless the venue itself
   * is tagged/described as one.
   *
   * @param array<string,string> $haystacks
   *   Normalized candidate text grouped by source.
   */
  private function venueMatchesBreweryPreference(array $haystacks): bool {
    $venueText = implode(' ', array_filter([
      $haystacks['venue_cuisine'] ?? '',
      $haystacks['venue_title'] ?? '',
      $haystacks['venue_description'] ?? '',
      $haystacks['venue_tags'] ?? '',
    ]));

    if ($venueText === '') {
      return FALSE;
    }

    $breweryNeedles = [
      'brewery',
      'breweries',
      'brewing',
      'brewpub',
      'brew pub',
      'taproom',
      'tap room',
      'craft beer',
      'craft brewery',
    ];

    foreach ($breweryNeedles as $needle) {
      if (str_contains($venueText, $needle)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Keeps recommendation results intuitive by vote quality.
   *
   * Positive deal/venue candidates always win when available. If no positive
   * candidates exist, unrated candidates remain eligible. Explicitly negative
   * candidates are not recommended because they undermine trust in the picker.
   *
   * @param array<int,array<string,int|float|string|bool>> $candidates
   *   Recommendation candidates.
   *
   * @return array<int,array<string,int|float|string|bool>>
   *   Filtered candidates.
   */
  private function filterRecommendationCandidatesByVoteQuality(array $candidates): array {
    $positive = array_values(array_filter(
      $candidates,
      static fn(array $candidate): bool => (int) ($candidate['quality_tier'] ?? self::QUALITY_TIER_UNRATED) === self::QUALITY_TIER_POSITIVE
    ));
    if (!empty($positive)) {
      return $positive;
    }

    return array_values(array_filter(
      $candidates,
      static fn(array $candidate): bool => (int) ($candidate['quality_tier'] ?? self::QUALITY_TIER_UNRATED) === self::QUALITY_TIER_UNRATED
    ));
  }

  /**
   * Calculates the candidate quality tier from deal and venue Worth it votes.
   *
   * @param array<string,int|float> $dealQuality
   *   Deal score data.
   * @param array<string,int|float> $venueQuality
   *   Venue score data.
   */
  private function candidateQualityTier(array $dealQuality, array $venueQuality): int {
    $dealTier = $this->voteQualityTier($dealQuality);
    $venueTier = $this->voteQualityTier($venueQuality);

    if ($dealTier === self::QUALITY_TIER_NEGATIVE || $venueTier === self::QUALITY_TIER_NEGATIVE) {
      return self::QUALITY_TIER_NEGATIVE;
    }

    if ($dealTier === self::QUALITY_TIER_POSITIVE || $venueTier === self::QUALITY_TIER_POSITIVE) {
      return self::QUALITY_TIER_POSITIVE;
    }

    return self::QUALITY_TIER_UNRATED;
  }

  /**
   * Returns the quality tier for one vote aggregate row.
   *
   * @param array<string,int|float> $quality
   *   Vote quality row.
   */
  private function voteQualityTier(array $quality): int {
    $totalVoters = (int) ($quality['total_voters'] ?? 0);
    $worthItPercent = (float) ($quality['worth_it_percent'] ?? 0.0);

    if ($totalVoters <= 0) {
      return self::QUALITY_TIER_UNRATED;
    }

    return $worthItPercent > 0.0 ? self::QUALITY_TIER_POSITIVE : self::QUALITY_TIER_NEGATIVE;
  }

  /**
   * Compares two candidates.
   */
  private function compareCandidates(array $a, array $b): int {
    $aQualityTier = (int) ($a['quality_tier'] ?? self::QUALITY_TIER_UNRATED);
    $bQualityTier = (int) ($b['quality_tier'] ?? self::QUALITY_TIER_UNRATED);
    if ($aQualityTier !== $bQualityTier) {
      return $aQualityTier <=> $bQualityTier;
    }

    if (($a['preference_mode'] ?? FALSE) !== ($b['preference_mode'] ?? FALSE)) {
      return !empty($a['preference_mode']) ? -1 : 1;
    }

    if ($a['overlap_count'] !== $b['overlap_count']) {
      return $b['overlap_count'] <=> $a['overlap_count'];
    }

    // For recommendation/"Try again", near-me order must beat freshness/score
    // within the same quality and preference bucket. Otherwise highly scored
    // farther venues can appear before closer New Smyrna Beach options.
    if ($a['rank_index'] !== $b['rank_index']) {
      return $a['rank_index'] <=> $b['rank_index'];
    }

    if ($a['score'] !== $b['score']) {
      return $b['score'] <=> $a['score'];
    }

    return $a['deal_nid'] <=> $b['deal_nid'];
  }

  /**
   * Returns TRUE when the first candidate ranks higher.
   */
  private function isBetterCandidate(array $candidate, array $existing): bool {
    return $this->compareCandidates($candidate, $existing) < 0;
  }

  /**
   * Restricts candidates to the nearest distance band with acceptable options.
   *
   * This runs before vote-quality filtering so a farther positive-vote venue
   * does not discard closer unrated candidates. The picker exhausts eligible
   * candidates in distance bands before expanding outward. For example, a
   * brewery search near New Smyrna Beach should try NSB/Edgewater, then Port
   * Orange, then Daytona-area candidates before jumping to Ormond, Orange City,
   * or Sanford.
   *
   * @param array<int,array<string,int|float|string|bool>> $candidates
   *   Candidate rows.
   *
   * @return array<int,array<string,int|float|string|bool>>
   *   Candidate rows restricted to the nearest useful distance band when one
   *   exists.
   */
  private function preferDistanceBandCandidatesByQuality(array $candidates): array {
    if (empty($candidates)) {
      return $candidates;
    }

    $bands = self::DISTANCE_BAND_LIMITS_KM;
    $bands[] = PHP_FLOAT_MAX;
    $previousLimitKm = 0.0;

    foreach ($bands as $bandLimitKm) {
      $bandCandidates = array_values(array_filter(
        $candidates,
        static function (array $candidate) use ($previousLimitKm, $bandLimitKm): bool {
          $distanceKm = (float) ($candidate['distance_km'] ?? PHP_FLOAT_MAX);
          return $distanceKm > $previousLimitKm && $distanceKm <= $bandLimitKm;
        }
      ));

      if (!empty($bandCandidates) && !empty($this->filterRecommendationCandidatesByVoteQuality($bandCandidates))) {
        return $bandCandidates;
      }

      $previousLimitKm = $bandLimitKm;
    }

    return $candidates;
  }

  /**
   * Returns the ordered localities the recommendation picker should exhaust.
   *
   * Previously shown localities are honored first. On the first pick, there is
   * no session locality yet, so use the closest eligible candidate locality as
   * the temporary anchor. This prevents optional cuisine preferences with broad
   * tokens from jumping to Port Orange before closer New Smyrna Beach options
   * have been tried.
   *
   * @param array<int,array<string,int|float|string|bool>> $candidates
   *   Sorted candidate rows.
   * @param array<int,string> $preferredLocalities
   *   Ordered normalized localities to exhaust first.
   *
   * @return array<int,string>
   *   Unique normalized localities in exhaustion order.
   */
  private function localityPreferenceOrder(array $candidates, array $preferredLocalities): array {
    $preferredLocalities = array_values(array_unique(array_filter(
      array_map('strval', $preferredLocalities),
      static fn(string $locality): bool => $locality !== ''
    )));

    if (!empty($preferredLocalities)) {
      return $preferredLocalities;
    }

    $nearestByLocality = [];
    foreach ($candidates as $candidate) {
      $locality = (string) ($candidate['venue_locality'] ?? '');
      if ($locality === '') {
        continue;
      }

      $distance = (float) ($candidate['distance_km'] ?? PHP_FLOAT_MAX);
      if (!isset($nearestByLocality[$locality]) || $distance < $nearestByLocality[$locality]) {
        $nearestByLocality[$locality] = $distance;
      }
    }

    if (empty($nearestByLocality)) {
      return [];
    }

    asort($nearestByLocality, SORT_NUMERIC);
    return array_keys($nearestByLocality);
  }

  /**
   * Restricts candidates to the earliest preferred locality when possible.
   *
   * This keeps recommendation retries neighborhood-first. For example, after a
   * New Smyrna Beach recommendation, Try again continues cycling through New
   * Smyrna Beach candidates before expanding to Port Orange or Daytona Beach.
   *
   * @param array<int,array<string,int|float|string|bool>> $candidates
   *   Sorted candidate rows.
   * @param array<int,string> $preferredLocalities
   *   Ordered normalized localities to exhaust first.
   *
   * @return array<int,array<string,int|float|string|bool>>
   *   Candidate rows, restricted to the first still-available locality when one
   *   exists.
   */
  private function preferLocalityCandidates(array $candidates, array $preferredLocalities): array {
    if (empty($candidates) || empty($preferredLocalities)) {
      return $candidates;
    }

    foreach ($preferredLocalities as $preferredLocality) {
      if ($preferredLocality === '') {
        continue;
      }

      $localCandidates = array_values(array_filter(
        $candidates,
        static fn(array $candidate): bool => ($candidate['venue_locality'] ?? '') === $preferredLocality
      ));

      if (!empty($localCandidates)) {
        return $localCandidates;
      }
    }

    return $candidates;
  }

  /**
   * Restricts candidates to the first locality that still has an eligible quality tier.
   *
   * This preserves local-first behavior without letting a locality that only has
   * explicitly negative candidates block nearby acceptable candidates.
   *
   * @param array<int,array<string,int|float|string|bool>> $candidates
   *   Candidate rows.
   * @param array<int,string> $preferredLocalities
   *   Ordered normalized localities to exhaust first.
   *
   * @return array<int,array<string,int|float|string|bool>>
   *   Candidate rows restricted to the best available locality when possible.
   */
  private function preferLocalityCandidatesByQuality(array $candidates, array $preferredLocalities): array {
    if (empty($candidates) || empty($preferredLocalities)) {
      return $candidates;
    }

    foreach ($preferredLocalities as $preferredLocality) {
      if ($preferredLocality === '') {
        continue;
      }

      $localCandidates = array_values(array_filter(
        $candidates,
        static fn(array $candidate): bool => ($candidate['venue_locality'] ?? '') === $preferredLocality
      ));

      if (empty($localCandidates)) {
        continue;
      }

      if (!empty($this->filterRecommendationCandidatesByVoteQuality($localCandidates))) {
        return $localCandidates;
      }
    }

    return $candidates;
  }

  /**
   * Returns ordered normalized localities for venue node IDs.
   *
   * @param array<int,int> $venueNids
   *   Venue node IDs, usually in recommendation session display order.
   *
   * @return array<int,string>
   *   Unique normalized localities.
   */
  private function localitiesForVenueNids(array $venueNids): array {
    $venueNids = array_values(array_unique(array_filter(
      array_map('intval', $venueNids),
      static fn(int $nid): bool => $nid > 0
    )));

    if (empty($venueNids)) {
      return [];
    }

    $venues = $this->entityTypeManager->getStorage('node')->loadMultiple($venueNids);
    $localities = [];

    foreach ($venueNids as $venueNid) {
      $venue = $venues[$venueNid] ?? NULL;
      if (!$venue instanceof NodeInterface) {
        continue;
      }

      $locality = $this->venueLocality($venue);
      if ($locality !== '') {
        $localities[] = $locality;
      }
    }

    return array_values(array_unique($localities));
  }

  /**
   * Returns the normalized locality/city for a venue.
   */
  private function venueLocality(NodeInterface $venue): string {
    if (!$venue->hasField('field_address') || $venue->get('field_address')->isEmpty()) {
      return '';
    }

    $address = $venue->get('field_address')->first();
    if ($address === NULL) {
      return '';
    }

    $locality = (string) ($address->get('locality')->getValue() ?? '');
    return $this->normalize($locality);
  }

  /**
   * Returns the distance from the origin to a venue in kilometers.
   */
  private function venueDistanceKm(NodeInterface $venue, float $originLat, float $originLon): float {
    $coords = $this->venueCoords($venue);
    if ($coords === NULL) {
      return PHP_FLOAT_MAX;
    }

    return $this->haversineKm($originLat, $originLon, $coords[0], $coords[1]);
  }

  /**
   * Extracts venue coordinates from supported venue fields.
   *
   * @return array{0:float,1:float}|null
   *   Latitude and longitude, or NULL.
   */
  private function venueCoords(NodeInterface $venue): ?array {
    if ($venue->hasField('field_coordinates') && !$venue->get('field_coordinates')->isEmpty()) {
      $raw = trim((string) $venue->get('field_coordinates')->value);

      if (preg_match('/POINT\s*\(\s*(-?[0-9.]+)\s+(-?[0-9.]+)\s*\)/i', $raw, $matches)) {
        return [(float) $matches[2], (float) $matches[1]];
      }
    }

    if (
      $venue->hasField('field_latitude') &&
      !$venue->get('field_latitude')->isEmpty() &&
      $venue->hasField('field_longitude') &&
      !$venue->get('field_longitude')->isEmpty()
    ) {
      $lat = $venue->get('field_latitude')->value;
      $lon = $venue->get('field_longitude')->value;

      if (is_numeric($lat) && is_numeric($lon)) {
        return [(float) $lat, (float) $lon];
      }
    }

    return NULL;
  }

  /**
   * Returns the haversine distance between two coordinates in kilometers.
   */
  private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadiusKm = 6371.0;
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);

    $a = sin($latDelta / 2) ** 2
      + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadiusKm * $c;
  }

  /**
   * Returns the venue node for a deal.
   */
  private function dealVenue(NodeInterface $deal): ?NodeInterface {
    if (!$deal->hasField('field_venue') || $deal->get('field_venue')->isEmpty()) {
      return NULL;
    }

    $venue = $deal->get('field_venue')->entity;
    return $venue instanceof NodeInterface ? $venue : NULL;
  }

  /**
   * Returns normalized cuisine text for a venue.
   */
  private function venueCuisineText(NodeInterface $venue): string {
    if (!$venue->hasField('field_cuisine') || $venue->get('field_cuisine')->isEmpty()) {
      return '';
    }

    $labels = [];
    foreach ($venue->get('field_cuisine')->referencedEntities() as $term) {
      $labels[] = (string) $term->label();
    }

    return implode(' ', $labels);
  }

  /**
   * Returns normalized venue cuisine tokens.
   *
   * @return array<int,string>
   *   Unique normalized cuisine tokens.
   */
  private function extractVenueCuisineTokens(NodeInterface $venue): array {
    return $this->normalizeCuisineTokens([$this->venueCuisineText($venue)]);
  }

  /**
   * Returns normalized tag text for a venue.
   */
  private function venueTagsText(NodeInterface $venue): string {
    if (!$venue->hasField('field_tags') || $venue->get('field_tags')->isEmpty()) {
      return '';
    }

    $field = $venue->get('field_tags');
    $parts = [];

    foreach ($field as $item) {
      if (isset($item->entity) && $item->entity) {
        $parts[] = (string) $item->entity->label();
        continue;
      }

      if (isset($item->value) && is_string($item->value) && trim($item->value) !== '') {
        $parts[] = $item->value;
      }
    }

    return implode(' ', $parts);
  }

  /**
   * Normalizes cuisine tokens from the request.
   *
   * @param array<int,string> $cuisines
   *   Raw cuisine values.
   *
   * @return array<int,string>
   *   Unique normalized tokens.
   */
  private function normalizeCuisineTokens(array $cuisines): array {
    $normalized = [];

    foreach ($cuisines as $value) {
      $value = $this->normalize((string) $value);
      if ($value === '') {
        continue;
      }

      $parts = preg_split('/\s+/', $value) ?: [];
      foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
          continue;
        }

        foreach ($this->expandedPreferenceTokens($part) as $expanded) {
          if ($expanded !== '') {
            $normalized[] = $expanded;
          }
        }
      }
    }

    return array_values(array_unique($normalized));
  }

  /**
   * Expands user-facing preference chips into local matching tokens.
   *
   * These aliases keep recommendation mode strict while still making common
   * chips useful. For example, wine should be able to rotate through nearby
   * local drink-oriented venues such as wine bars, breweries, taprooms, and
   * craft beer spots; arepas should stay Venezuelan/arepa-related and never
   * drift into unrelated Mexican/American venues.
   *
   * @return array<int,string>
   *   Normalized preference tokens.
   */
  private function expandedPreferenceTokens(string $token): array {
    $aliases = [
      'arepa' => ['arepa', 'arepas', 'arepita', 'arepitas', 'venezuelan', 'venezolana'],
      'arepas' => ['arepa', 'arepas', 'arepita', 'arepitas', 'venezuelan', 'venezolana'],
      'arepita' => ['arepa', 'arepas', 'arepita', 'arepitas', 'venezuelan', 'venezolana'],
      'arepitas' => ['arepa', 'arepas', 'arepita', 'arepitas', 'venezuelan', 'venezolana'],
      'venezuelan' => ['venezuelan', 'venezolana', 'arepa', 'arepas'],
      'venezolana' => ['venezuelan', 'venezolana', 'arepa', 'arepas'],
      'taco' => ['taco', 'tacos'],
      'tacos' => ['taco', 'tacos'],
      'burrito' => ['burrito', 'burritos'],
      'burritos' => ['burrito', 'burritos'],
      'brewery' => ['brewery', 'breweries', 'brewing', 'brewpub', 'brew pub', 'taproom', 'tap room'],
      'breweries' => ['brewery', 'breweries', 'brewing', 'brewpub', 'brew pub', 'taproom', 'tap room'],
      'brewing' => ['brewery', 'breweries', 'brewing', 'brewpub', 'brew pub', 'taproom', 'tap room'],
      'brewpub' => ['brewery', 'breweries', 'brewing', 'brewpub', 'brew pub'],
      'taproom' => ['taproom', 'tap room', 'brewery', 'breweries', 'brewing'],
      'beer' => ['beer', 'beers'],
      'beers' => ['beer', 'beers'],
      'wine' => ['wine', 'wines', 'winery'],
      'wines' => ['wine', 'wines', 'winery'],
      'winery' => ['wine', 'wines', 'winery'],
      'ice' => ['ice'],
      'cream' => ['cream'],
      'gelato' => ['gelato'],
      'sundae' => ['sundae', 'sundaes'],
      'sundaes' => ['sundae', 'sundaes'],
      'blizzard' => ['blizzard', 'blizzards'],
      'blizzards' => ['blizzard', 'blizzards'],
      'milkshake' => ['milkshake', 'milkshakes', 'shake', 'shakes'],
      'milkshakes' => ['milkshake', 'milkshakes', 'shake', 'shakes'],
      'custard' => ['custard', 'frozen custard'],
    ];

    return $aliases[$token] ?? [$token];
  }

  /**
   * Normalizes text for matching.
   */
  private function normalize(string $value): string {
    $value = mb_strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
  }

  /**
   * Logs timing details for recommendation requests.
   *
   * @param string $message
   *   Log message.
   */
  private function logTiming(string $message): void {
    \Drupal::logger('spotdeals_search_smart_location')->notice($message);
  }

  /**
   * Formats elapsed milliseconds since a start time.
   */
  private function formatMs(float $startedAt): string {
    return number_format((microtime(TRUE) - $startedAt) * 1000, 2, '.', '');
  }

}
