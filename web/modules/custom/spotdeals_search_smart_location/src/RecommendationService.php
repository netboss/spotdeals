<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Builds a recommendation result on top of near-me ranking.
 */
final class RecommendationService {

  /**
   * Default radius in kilometers.
   */
  private const DEFAULT_RADIUS_KM = 25.0;

  /**
   * Maximum number of near-me candidates to inspect.
   */
  private const CANDIDATE_LIMIT = 50;

  /**
   * Maximum number of top ties to randomize across.
   */
  private const RANDOM_TIE_LIMIT = 7;

  /**
   * Constructs a recommendation service.
   */
  public function __construct(
    private readonly NearMeRanker $nearMeRanker,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns one recommended deal node ID.
   *
   * The recommendation logic:
   * - starts from near-me candidates inside the configured radius
   * - optionally favors overlaps with selected cuisines
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
  ): array {
    $cuisines = $this->normalizeCuisineTokens($cuisines);
    $excludedCuisines = $this->normalizeCuisineTokens($excludedCuisines);
    $excludedVenueNids = array_values(array_unique(array_filter(
      array_map('intval', $excludedVenueNids),
      static fn(int $nid): bool => $nid > 0
    )));

    $candidateSets = [];

    if (!empty($cuisines)) {
      $candidateSets[] = $this->buildCandidates(
        $originLat,
        $originLon,
        $cuisines,
        $radiusKm,
        $excludedCuisines,
        $excludedVenueNids
      );
      $candidateSets[] = $this->buildCandidates(
        $originLat,
        $originLon,
        $cuisines,
        $radiusKm,
        [],
        $excludedVenueNids
      );
    }

    $candidateSets[] = $this->buildCandidates(
      $originLat,
      $originLon,
      [],
      $radiusKm,
      $excludedCuisines,
      $excludedVenueNids
    );
    $candidateSets[] = $this->buildCandidates(
      $originLat,
      $originLon,
      [],
      $radiusKm,
      [],
      $excludedVenueNids
    );

    if ($radiusKm < 1000.0) {
      if (!empty($cuisines)) {
        $candidateSets[] = $this->buildCandidates(
          $originLat,
          $originLon,
          $cuisines,
          1000.0,
          $excludedCuisines,
          $excludedVenueNids
        );
        $candidateSets[] = $this->buildCandidates(
          $originLat,
          $originLon,
          $cuisines,
          1000.0,
          [],
          $excludedVenueNids
        );
      }

      $candidateSets[] = $this->buildCandidates(
        $originLat,
        $originLon,
        [],
        1000.0,
        $excludedCuisines,
        $excludedVenueNids
      );
      $candidateSets[] = $this->buildCandidates(
        $originLat,
        $originLon,
        [],
        1000.0,
        [],
        $excludedVenueNids
      );
    }

    foreach ($candidateSets as $candidates) {
      if (empty($candidates)) {
        continue;
      }

      usort($candidates, fn(array $a, array $b): int => $this->compareCandidates($a, $b));

      $top = $candidates[0];
      $topTier = array_values(array_filter(
        $candidates,
        static function (array $candidate) use ($top): bool {
          if (($candidate['preference_mode'] ?? FALSE) !== ($top['preference_mode'] ?? FALSE)) {
            return FALSE;
          }

          if (!empty($top['preference_mode'])) {
            return $candidate['overlap_count'] === $top['overlap_count']
              && $candidate['score'] >= ($top['score'] - 20);
          }

          return $candidate['score'] >= ($top['score'] - 25);
        }
      ));

      if (empty($topTier)) {
        $topTier = [$top];
      }

      $topTier = array_slice($topTier, 0, self::RANDOM_TIE_LIMIT);
      $picked = $topTier[array_rand($topTier)];

      return [(int) $picked['deal_nid']];
    }

    return [];
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
   *
   * @return array<int,array<string,int|string|bool>>
   *   Recommendation candidates keyed numerically.
   */
  private function buildCandidates(
    float $originLat,
    float $originLon,
    array $cuisines,
    float $radiusKm,
    array $excludedCuisines,
    array $excludedVenueNids,
  ): array {
    $query = !empty($cuisines) ? implode(' ', $cuisines) : '';

    $rankedNids = $this->nearMeRanker->rankDealNids(
      $originLat,
      $originLon,
      $query,
      $radiusKm
    );

    if (empty($rankedNids) && $query !== '') {
      $rankedNids = $this->nearMeRanker->rankDealNids(
        $originLat,
        $originLon,
        '',
        $radiusKm
      );
    }

    if (empty($rankedNids)) {
      return [];
    }

    $candidateNids = array_slice(array_values($rankedNids), 0, self::CANDIDATE_LIMIT);
    $deals = $this->entityTypeManager->getStorage('node')->loadMultiple($candidateNids);

    if (empty($deals)) {
      return [];
    }

    $rankIndexMap = array_flip($candidateNids);
    $bestByVenue = [];

    foreach ($candidateNids as $dealNid) {
      $deal = $deals[$dealNid] ?? NULL;
      if (!$deal instanceof NodeInterface) {
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

      $venueCuisineTokens = $this->extractVenueCuisineTokens($venue);
      if (!empty($excludedCuisines) && !empty(array_intersect($excludedCuisines, $venueCuisineTokens))) {
        continue;
      }

      $candidate = $this->buildCandidate(
        $deal,
        $venue,
        $cuisines,
        $venueCuisineTokens,
        (int) ($rankIndexMap[$dealNid] ?? PHP_INT_MAX)
      );
      if ($candidate === NULL) {
        continue;
      }

      if (!isset($bestByVenue[$venueNid]) || $this->isBetterCandidate($candidate, $bestByVenue[$venueNid])) {
        $bestByVenue[$venueNid] = $candidate;
      }
    }

    return array_values($bestByVenue);
  }

  /**
   * Builds one scored recommendation candidate.
   *
   * @param array<int,string> $cuisines
   *   Normalized cuisine tokens.
   * @param array<int,string> $venueCuisineTokens
   *   Normalized venue cuisine tokens.
   *
   * @return array<string,int|string|bool>|null
   *   Candidate data or NULL.
   */
  private function buildCandidate(
    NodeInterface $deal,
    NodeInterface $venue,
    array $cuisines,
    array $venueCuisineTokens,
    int $rankIndex,
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

    $haystacks = [
      'venue_cuisine' => $venueCuisine,
      'deal_title' => $dealTitle,
      'venue_title' => $venueTitle,
      'deal_body' => $dealBody,
      'venue_description' => $venueDescription,
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

        if ($haystacks['venue_cuisine'] !== '' && str_contains($haystacks['venue_cuisine'], $cuisine)) {
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
        if ($haystacks['venue_description'] !== '' && str_contains($haystacks['venue_description'], $cuisine)) {
          $score += 12;
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
      if ($haystacks['deal_body'] !== '') {
        $score += 10;
      }
      if ($venueTitle !== '') {
        $score += 5;
      }
    }

    $score += max(0, 60 - min($rankIndex, 60));

    return [
      'deal_nid' => (int) $deal->id(),
      'venue_nid' => (int) $venue->id(),
      'overlap_count' => $overlapCount,
      'score' => $score,
      'rank_index' => $rankIndex,
      'deal_title' => (string) $deal->label(),
      'venue_title' => (string) $venue->label(),
      'preference_mode' => $preferenceMode,
    ];
  }

  /**
   * Compares two candidates.
   */
  private function compareCandidates(array $a, array $b): int {
    if (($a['preference_mode'] ?? FALSE) !== ($b['preference_mode'] ?? FALSE)) {
      return !empty($a['preference_mode']) ? -1 : 1;
    }

    if ($a['overlap_count'] !== $b['overlap_count']) {
      return $b['overlap_count'] <=> $a['overlap_count'];
    }

    if ($a['score'] !== $b['score']) {
      return $b['score'] <=> $a['score'];
    }

    if ($a['rank_index'] !== $b['rank_index']) {
      return $a['rank_index'] <=> $b['rank_index'];
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
        if ($part !== '') {
          $normalized[] = $part;
        }
      }
    }

    return array_values(array_unique($normalized));
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

}
