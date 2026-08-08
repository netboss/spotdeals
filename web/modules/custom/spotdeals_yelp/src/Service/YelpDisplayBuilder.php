<?php

declare(strict_types=1);

namespace Drupal\spotdeals_yelp\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Builds render arrays for Yelp display components.
 */
final class YelpDisplayBuilder {

  /**
   * Constructs the display builder.
   */
  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds a lightweight summary render array for venue displays.
   */
  public function buildVenueSummary(NodeInterface $venue): array {
    if (!$this->hasYelpData($venue)) {
      return [];
    }

    return [
      '#theme' => 'spotdeals_yelp_summary',
      '#rating' => $venue->get('field_yelp_rating')->value,
      '#review_count' => $venue->get('field_yelp_review_count')->value,
      '#business_url' => $venue->get('field_yelp_url')->isEmpty() ? '' : $venue->get('field_yelp_url')->first()->getUrl()->toString(),
      '#show_attribution' => TRUE,
      '#attached' => [
        'library' => ['spotdeals_yelp/yelp_display'],
      ],
    ];
  }

  /**
   * Builds review excerpt output from cached Yelp data.
   */
  public function buildVenueReviews(NodeInterface $venue): array {
    if (!$this->hasYelpData($venue)) {
      return [];
    }

    $businessId = (string) $venue->get('field_yelp_business_id')->value;
    $locale = (string) $this->configFactory->get('spotdeals_yelp.settings')->get('default_locale');
    $cache = $this->cache->get('spotdeals_yelp:business:' . $businessId . ':reviews:' . ($locale ?: 'default'));
    $reviews = is_array($cache?->data['reviews'] ?? NULL) ? $cache->data['reviews'] : [];

    return [
      '#theme' => 'spotdeals_yelp_reviews',
      '#rating' => $venue->get('field_yelp_rating')->value,
      '#review_count' => $venue->get('field_yelp_review_count')->value,
      '#business_url' => $venue->get('field_yelp_url')->isEmpty() ? '' : $venue->get('field_yelp_url')->first()->getUrl()->toString(),
      '#reviews' => array_slice($reviews, 0, 2),
      '#show_attribution' => TRUE,
      '#attached' => [
        'library' => ['spotdeals_yelp/yelp_display'],
      ],
    ];
  }

  /**
   * Builds a compact card summary.
   */
  public function buildCardSummary(NodeInterface $venue): array {
    return $this->buildVenueSummary($venue);
  }

  /**
   * Returns TRUE when the venue has usable Yelp data.
   */
  private function hasYelpData(NodeInterface $venue): bool {
    return $venue->hasField('field_yelp_business_id')
      && !$venue->get('field_yelp_business_id')->isEmpty()
      && $venue->hasField('field_yelp_rating')
      && !$venue->get('field_yelp_rating')->isEmpty();
  }

}
