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

    foreach ($radiusAttempts as $attemptRadiusKm) {
      $candidateSets[] = $this->buildCandidates(
        $originLat,
        $originLon,
        $cuisines,
        $attemptRadiusKm,
        $excludedCuisines,
        $excludedVenueNids,
        $excludedDealNids
      );
      $candidateAttemptMeta[] = [
        'radius_km' => $attemptRadiusKm,
        'recycled' => FALSE,
      ];

      if (!empty($excludedCuisines)) {
        $candidateSets[] = $this->buildCandidates(
          $originLat,
          $originLon,
          $cuisines,
          $attemptRadiusKm,
          [],
          $excludedVenueNids,
          $excludedDealNids
        );
        $candidateAttemptMeta[] = [
          'radius_km' => $attemptRadiusKm,
          'recycled' => FALSE,
        ];
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
        $candidateSets[] = $this->buildCandidates(
          $originLat,
          $originLon,
          $cuisines,
          $attemptRadiusKm,
          $excludedCuisines,
          [],
          $recycleExcludedDealNids
        );
        $candidateAttemptMeta[] = [
          'radius_km' => $attemptRadiusKm,
          'recycled' => TRUE,
        ];

        if (!empty($excludedCuisines)) {
          $candidateSets[] = $this->buildCandidates(
            $originLat,
            $originLon,
            $cuisines,
            $attemptRadiusKm,
            [],
            [],
            $recycleExcludedDealNids
          );
          $candidateAttemptMeta[] = [
            'radius_km' => $attemptRadiusKm,
            'recycled' => TRUE,
          ];
        }
      }
    }

    $setSizes = array_map(static fn(array $set): int => count($set), $candidateSets);
    $attemptSizesJson = json_encode($setSizes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';

    $picked = $this->pickFromCandidateSets($candidateSets, $preferredLocalities);
    if ($picked !== NULL) {
      $attemptIndex = max(0, ((int) $picked['attempt_index']) - 1);
      $attemptMeta = $candidateAttemptMeta[$attemptIndex] ?? ['radius_km' => $radiusKm, 'recycled' => FALSE];
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
        (int) $picked['attempt_index'],
        (int) $picked['top_tier_count'],
        (int) $picked['candidate']['deal_nid'],
        (int) $picked['candidate']['venue_nid'],
        !empty($attemptMeta['recycled']) ? '1' : '0',
      ));

      return [(int) $picked['candidate']['deal_nid']];
    }

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

      $candidates = $this->filterRecommendationCandidatesByVoteQuality($candidates);
      if (empty($candidates)) {
        continue;
      }

      usort($candidates, fn(array $a, array $b): int => $this->compareCandidates($a, $b));
      $localityPreferenceOrder = $this->localityPreferenceOrder($candidates, $preferredLocalities);
      $candidates = $this->preferLocalityCandidates($candidates, $localityPreferenceOrder);

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
      80.47,
      160.93,
      402.34,
      1000.0,
    ];

    return array_values(array_unique(array_map(
      static fn(float $radius): float => round($radius, 2),
      array_filter($radii, static fn(float $radius): bool => $radius >= $baseRadiusKm)
    )));
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
          && str_contains($haystacks['venue_cuisine'], $cuisine)
        ) {
          $score += 80;
          $matched = TRUE;
        }
        if ($haystacks['deal_title'] !== '' && str_contains($haystacks['deal_title'], $cuisine)) {
          $score += 45;
          $matched = TRUE;
        }
        if ($haystacks['venue_title'] !== '' && str_contains($haystacks['venue_title'], $cuisine)) {
          $score += 30;
          $matched = TRUE;
        }
        if ($haystacks['deal_body'] !== '' && str_contains($haystacks['deal_body'], $cuisine)) {
          $score += 18;
          $matched = TRUE;
        }
        if ($haystacks['deal_offer_text'] !== '' && str_contains($haystacks['deal_offer_text'], $cuisine)) {
          $score += 40;
          $matched = TRUE;
        }
        if (
          !$requiresDealLevelMatch
          && $haystacks['venue_description'] !== ''
          && str_contains($haystacks['venue_description'], $cuisine)
        ) {
          $score += 12;
          $matched = TRUE;
        }
        if (
          !$requiresDealLevelMatch
          && $haystacks['venue_tags'] !== ''
          && str_contains($haystacks['venue_tags'], $cuisine)
        ) {
          $score += 20;
          $matched = TRUE;
        }

        if ($matched) {
          $overlapCount++;
        }
      }

      if ($overlapCount === 0) {
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
      'wine' => ['wine', 'wines', 'winery'],
      'wines' => ['wine', 'wines', 'winery'],
      'winery' => ['wine', 'wines', 'winery'],
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
