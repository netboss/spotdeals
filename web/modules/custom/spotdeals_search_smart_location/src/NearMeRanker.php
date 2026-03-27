<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Computes deterministic near-me ranking for deals.
 */
final class NearMeRanker {

  /**
   * Default radius in kilometers.
   */
  private const DEFAULT_RADIUS_KM = 25.0;

  /**
   * Constructs a NearMeRanker service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly NearMeResultExtractor $resultExtractor,
  ) {}

  /**
   * Returns ordered deal node IDs for a near-me search.
   *
   * Distance is primary. Keywords only decide eligibility.
   *
   * @return array<int, int>
   *   Ordered deal node IDs.
   */
  public function rankDealNids(float $originLat, float $originLon, string $keywords, float $radiusKm = self::DEFAULT_RADIUS_KM): array {
    $keywords = $this->normalize($keywords);
    $tokens = $this->tokens($keywords);

    $venue_storage = $this->entityTypeManager->getStorage('node');
    $deal_storage = $this->entityTypeManager->getStorage('node');

    $venue_nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->condition('status', 1)
      ->execute();

    if (empty($venue_nids)) {
      return [];
    }

    $venues = $venue_storage->loadMultiple($venue_nids);

    $candidate_venues = [];
    foreach ($venues as $venue) {
      if (!$venue instanceof NodeInterface) {
        continue;
      }

      $coords = $this->resultExtractor->extractVenueCoords($venue);
      if ($coords === NULL) {
        continue;
      }

      [$lat, $lon] = $coords;
      $distance = $this->haversineKm($originLat, $originLon, $lat, $lon);

      if ($distance > $radiusKm) {
        continue;
      }

      $candidate_venues[(int) $venue->id()] = [
        'distance' => $distance,
        'title' => $this->normalize((string) $venue->label()),
        'description' => $this->normalize($venue->hasField('field_short_description') && !$venue->get('field_short_description')->isEmpty() ? (string) $venue->get('field_short_description')->value : ''),
        'cuisine' => $this->normalize($this->venueCuisineText($venue)),
      ];
    }

    if (empty($candidate_venues)) {
      return [];
    }

    $deal_nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'deal')
      ->condition('status', 1)
      ->condition('field_venue.target_id', array_keys($candidate_venues), 'IN')
      ->execute();

    if (empty($deal_nids)) {
      return [];
    }

    $deal_positions = array_flip(array_values($deal_nids));
    $deals = $deal_storage->loadMultiple($deal_nids);

    $ranked = [];
    foreach ($deals as $deal) {
      if (!$deal instanceof NodeInterface) {
        continue;
      }

      if (!$deal->hasField('field_venue') || $deal->get('field_venue')->isEmpty()) {
        continue;
      }

      $venue = $deal->get('field_venue')->entity;
      if (!$venue instanceof NodeInterface) {
        continue;
      }

      $venue_nid = (int) $venue->id();
      if (!isset($candidate_venues[$venue_nid])) {
        continue;
      }

      $score = $this->keywordScore($deal, $candidate_venues[$venue_nid], $keywords, $tokens);

      // Keep broad near-me coverage. Only drop rows when there are keywords and
      // the score is clearly non-matching.
      if ($keywords !== '' && $score < 5) {
        continue;
      }

      $deal_nid = (int) $deal->id();
      $ranked[] = [
        'nid' => $deal_nid,
        'distance' => (float) $candidate_venues[$venue_nid]['distance'],
        'position' => $deal_positions[$deal_nid] ?? PHP_INT_MAX,
      ];
    }

    usort($ranked, static function (array $a, array $b): int {
      $distance_compare = $a['distance'] <=> $b['distance'];
      if ($distance_compare !== 0) {
        return $distance_compare;
      }

      $position_compare = $a['position'] <=> $b['position'];
      if ($position_compare !== 0) {
        return $position_compare;
      }

      return $a['nid'] <=> $b['nid'];
    });

    return array_values(array_map(static fn(array $row): int => $row['nid'], $ranked));
  }

  /**
   * Scores a deal against the near-me keyword query.
   */
  private function keywordScore(NodeInterface $deal, array $venueData, string $keywords, array $tokens): int {
    if ($keywords === '') {
      return 10;
    }

    $deal_title = $this->normalize((string) $deal->label());
    $body = $this->normalize(
      $deal->hasField('body') && !$deal->get('body')->isEmpty()
        ? (string) $deal->get('body')->value
        : ''
    );

    $venue_title = $venueData['title'] ?? '';
    $venue_description = $venueData['description'] ?? '';
    $venue_cuisine = $venueData['cuisine'] ?? '';

    $score = 0;

    if ($deal_title !== '' && str_contains($deal_title, $keywords)) {
      $score += 120;
    }
    if ($venue_title !== '' && str_contains($venue_title, $keywords)) {
      $score += 100;
    }
    if ($venue_cuisine !== '' && str_contains($venue_cuisine, $keywords)) {
      $score += 90;
    }
    if ($body !== '' && str_contains($body, $keywords)) {
      $score += 60;
    }
    if ($venue_description !== '' && str_contains($venue_description, $keywords)) {
      $score += 50;
    }

    foreach ($tokens as $token) {
      if ($token === '') {
        continue;
      }

      if (str_contains($deal_title, $token)) {
        $score += 20;
      }
      if (str_contains($venue_title, $token)) {
        $score += 18;
      }
      if (str_contains($venue_cuisine, $token)) {
        $score += 16;
      }
      if (str_contains($body, $token)) {
        $score += 10;
      }
      if (str_contains($venue_description, $token)) {
        $score += 8;
      }
    }

    return $score;
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
   * Tokenizes normalized search text.
   *
   * @return array<int, string>
   *   Tokens.
   */
  private function tokens(string $value): array {
    if ($value === '') {
      return [];
    }

    $parts = preg_split('/\s+/', $value) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts)));

    return array_values(array_unique($parts));
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
   * Returns great-circle distance in kilometers.
   */
  private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earth_radius_km = 6371.0;

    $lat1_rad = deg2rad($lat1);
    $lon1_rad = deg2rad($lon1);
    $lat2_rad = deg2rad($lat2);
    $lon2_rad = deg2rad($lon2);

    $dlat = $lat2_rad - $lat1_rad;
    $dlon = $lon2_rad - $lon1_rad;

    $a = sin($dlat / 2) ** 2
      + cos($lat1_rad) * cos($lat2_rad) * sin($dlon / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earth_radius_km * $c;
  }

}
