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
  private const RANDOM_TIE_LIMIT = 3;

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
   * - strongly favors matches that overlap more selected cuisines
   * - keeps only the best deal per venue
   * - randomizes only inside the strongest top tier
   *
   * @param float $originLat
   *   Origin latitude.
   * @param float $originLon
   *   Origin longitude.
   * @param array<int,string> $cuisines
   *   Recommendation cuisines.
   * @param float $radiusKm
   *   Search radius.
   *
   * @return array<int,int>
   *   A single recommended deal node ID, or an empty array.
   */
  public function recommendDealNids(
    float $originLat,
    float $originLon,
    array $cuisines,
    float $radiusKm = self::DEFAULT_RADIUS_KM,
  ): array {
    $cuisines = $this->normalizeCuisineTokens($cuisines);
    if (count($cuisines) < 2) {
      return [];
    }

    $ranked_nids = $this->nearMeRanker->rankDealNids(
      $originLat,
      $originLon,
      implode(' ', $cuisines),
      $radiusKm
    );

    if (empty($ranked_nids)) {
      return [];
    }

    $candidate_nids = array_slice(array_values($ranked_nids), 0, self::CANDIDATE_LIMIT);
    $deals = $this->entityTypeManager->getStorage('node')->loadMultiple($candidate_nids);

    if (empty($deals)) {
      return [];
    }

    $rank_index_map = array_flip($candidate_nids);
    $best_by_venue = [];

    foreach ($candidate_nids as $deal_nid) {
      $deal = $deals[$deal_nid] ?? NULL;
      if (!$deal instanceof NodeInterface) {
        continue;
      }

      $venue = $this->dealVenue($deal);
      if (!$venue instanceof NodeInterface) {
        continue;
      }

      $candidate = $this->buildCandidate($deal, $venue, $cuisines, (int) ($rank_index_map[$deal_nid] ?? PHP_INT_MAX));
      if ($candidate === NULL) {
        continue;
      }

      $venue_nid = $candidate['venue_nid'];
      if (!isset($best_by_venue[$venue_nid]) || $this->isBetterCandidate($candidate, $best_by_venue[$venue_nid])) {
        $best_by_venue[$venue_nid] = $candidate;
      }
    }

    if (empty($best_by_venue)) {
      return [];
    }

    $candidates = array_values($best_by_venue);
    usort($candidates, fn(array $a, array $b): int => $this->compareCandidates($a, $b));

    $top = $candidates[0];
    $top_tier = array_values(array_filter(
      $candidates,
      static function (array $candidate) use ($top): bool {
        return $candidate['overlap_count'] === $top['overlap_count']
          && $candidate['score'] >= ($top['score'] - 20);
      }
    ));

    $top_tier = array_slice($top_tier, 0, self::RANDOM_TIE_LIMIT);
    $picked = $top_tier[array_rand($top_tier)];

    return [(int) $picked['deal_nid']];
  }

  /**
   * Builds one scored recommendation candidate.
   *
   * @param array<int,string> $cuisines
   *   Normalized cuisine tokens.
   *
   * @return array<string,int|string>|null
   *   Candidate data or NULL.
   */
  private function buildCandidate(NodeInterface $deal, NodeInterface $venue, array $cuisines, int $rankIndex): ?array {
    $deal_title = $this->normalize((string) $deal->label());
    $deal_body = $this->normalize(
      $deal->hasField('body') && !$deal->get('body')->isEmpty()
        ? (string) $deal->get('body')->value
        : ''
    );
    $venue_title = $this->normalize((string) $venue->label());
    $venue_description = $this->normalize(
      $venue->hasField('field_short_description') && !$venue->get('field_short_description')->isEmpty()
        ? (string) $venue->get('field_short_description')->value
        : ''
    );
    $venue_cuisine = $this->normalize($this->venueCuisineText($venue));

    $haystacks = [
      'venue_cuisine' => $venue_cuisine,
      'deal_title' => $deal_title,
      'venue_title' => $venue_title,
      'deal_body' => $deal_body,
      'venue_description' => $venue_description,
    ];

    $overlap_count = 0;
    $score = 0;

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
        $overlap_count++;
      }
    }

    if ($overlap_count === 0) {
      return NULL;
    }

    if ($overlap_count > 1) {
      $score += ($overlap_count - 1) * 120;
    }

    return [
      'deal_nid' => (int) $deal->id(),
      'venue_nid' => (int) $venue->id(),
      'overlap_count' => $overlap_count,
      'score' => $score,
      'rank_index' => $rankIndex,
      'deal_title' => (string) $deal->label(),
      'venue_title' => (string) $venue->label(),
    ];
  }

  /**
   * Compares two candidates.
   */
  private function compareCandidates(array $a, array $b): int {
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
   * Normalizes cuisine tokens from the request.
   *
   * @param array<int,string> $cuisines
   *   Raw cuisine values.
   *
   * @return array<int,string>
   *   Unique normalized tokens.
   */
  private function normalizeCuisineTokens(array $cuisines): array {
    $normalized = array_map(
      fn(string $value): string => $this->normalize($value),
      $cuisines
    );

    return array_values(array_unique(array_filter($normalized)));
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
