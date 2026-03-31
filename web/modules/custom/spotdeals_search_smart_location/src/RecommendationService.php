<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Builds a single recommendation result on top of near-me ranking.
 */
final class RecommendationService {

  /**
   * Default radius in kilometers.
   */
  private const DEFAULT_RADIUS_KM = 25.0;

  /**
   * Maximum number of top-ranked candidates eligible for random selection.
   */
  private const RANDOM_POOL_LIMIT = 5;

  /**
   * Constructs a recommendation service.
   */
  public function __construct(
    private readonly NearMeRanker $nearMeRanker,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns a single recommended deal node ID.
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
   *   A single randomly selected deal node ID from the top-ranked pool.
   */
  public function recommendDealNids(
    float $originLat,
    float $originLon,
    array $cuisines,
    float $radiusKm = self::DEFAULT_RADIUS_KM,
  ): array {
    $cuisines = array_values(array_unique(array_filter(array_map(
      static fn(string $value): string => trim(mb_strtolower($value)),
      $cuisines
    ))));

    if (count($cuisines) < 2) {
      return [];
    }

    $keywords = implode(' ', $cuisines);
    if ($keywords === '') {
      return [];
    }

    $ranked_nids = $this->nearMeRanker->rankDealNids($originLat, $originLon, $keywords, $radiusKm);
    if (empty($ranked_nids)) {
      return [];
    }

    $candidate_pool = array_slice(array_values($ranked_nids), 0, self::RANDOM_POOL_LIMIT);
    if (empty($candidate_pool)) {
      return [];
    }

    $random_key = array_rand($candidate_pool);

    return [(int) $candidate_pool[$random_key]];
  }

}
