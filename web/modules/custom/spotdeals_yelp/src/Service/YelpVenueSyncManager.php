<?php

declare(strict_types=1);

namespace Drupal\spotdeals_yelp\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Coordinates matching, syncing, and caching Yelp venue data.
 */
final class YelpVenueSyncManager {

  private const QUEUE_NAME = 'spotdeals_yelp_sync';

  /**
   * Constructs the sync manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly QueueFactory $queueFactory,
    private readonly YelpApiClient $apiClient,
    private readonly YelpVenueMatcher $venueMatcher,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Queues venues without a recent sync.
   */
  public function enqueueUnsyncedVenues(int $limit = 25): int {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'venue')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->sort('nid', 'ASC')
      ->range(0, max(1, $limit));

    $query->notExists('field_yelp_last_synced');
    $nids = $query->execute();
    return $this->enqueueNodeIds($nids);
  }

  /**
   * Queues venues with stale Yelp metadata.
   */
  public function enqueueStaleVenues(int $limit = 25): int {
    $config = $this->configFactory->get('spotdeals_yelp.settings');
    $hours = max(1, (int) $config->get('refresh_interval_hours'));
    $threshold = $this->time->getRequestTime() - ($hours * 3600);

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'venue')
      ->condition('status', 1)
      ->condition('field_yelp_business_id', '', '<>')
      ->accessCheck(FALSE)
      ->sort('field_yelp_last_synced.value', 'ASC')
      ->range(0, max(1, $limit));

    $orGroup = $query->orConditionGroup()
      ->notExists('field_yelp_last_synced')
      ->condition('field_yelp_last_synced.value', gmdate('Y-m-d\TH:i:s', $threshold), '<');
    $query->condition($orGroup);

    $nids = $query->execute();
    return $this->enqueueNodeIds($nids);
  }

  /**
   * Syncs a venue immediately.
   */
  public function syncVenue(NodeInterface $venue): array {
    if ($venue->bundle() !== 'venue') {
      throw new \InvalidArgumentException('Only venue nodes can be synced with Yelp.');
    }

    $businessId = trim((string) $venue->get('field_yelp_business_id')->value);
    if ($businessId === '') {
      $match = $this->venueMatcher->matchVenue($venue);
      $this->applyMatchResult($venue, $match);
      if (empty($match['matched']) || empty($match['business_id'])) {
        return $match;
      }
      $businessId = (string) $match['business_id'];
    }

    $details = $this->apiClient->getBusinessDetails($businessId);
    $reviews = $this->apiClient->getBusinessReviews($businessId, (string) $this->configFactory->get('spotdeals_yelp.settings')->get('default_locale'));

    $this->saveVenueYelpData($venue, $details);
    $this->cacheBusinessDetails($businessId, $details);
    $this->cacheBusinessReviews($businessId, $reviews, (string) $this->configFactory->get('spotdeals_yelp.settings')->get('default_locale'));

    return [
      'matched' => TRUE,
      'business_id' => $businessId,
      'status' => 'matched',
      'details' => $details,
      'reviews' => $reviews,
    ];
  }

  /**
   * Syncs a venue by node id.
   */
  public function refreshVenueById(int $nid): array {
    $venue = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$venue instanceof NodeInterface) {
      throw new \InvalidArgumentException(sprintf('Venue node %d could not be loaded.', $nid));
    }

    return $this->syncVenue($venue);
  }

  /**
   * Saves durable Yelp metadata on the venue.
   */
  public function saveVenueYelpData(NodeInterface $venue, array $details): void {
    $businessId = (string) ($details['id'] ?? '');
    if ($businessId !== '') {
      $venue->set('field_yelp_business_id', $businessId);
    }

    if (isset($details['rating'])) {
      $venue->set('field_yelp_rating', (string) $details['rating']);
    }

    if (isset($details['review_count'])) {
      $venue->set('field_yelp_review_count', (int) $details['review_count']);
    }

    if (!empty($details['url'])) {
      $venue->set('field_yelp_url', ['uri' => (string) $details['url'], 'title' => 'View on Yelp']);
    }

    $venue->set('field_yelp_match_status', 'matched');
    $venue->set('field_yelp_last_synced', gmdate('Y-m-d', $this->time->getRequestTime()));
    $venue->save();
  }

  /**
   * Caches Yelp business details.
   */
  public function cacheBusinessDetails(string $businessId, array $data): void {
    $ttl = max(60, (int) $this->configFactory->get('spotdeals_yelp.settings')->get('cache_ttl_seconds'));
    $this->cache->set($this->getBusinessDetailsCacheId($businessId), $data, $this->time->getRequestTime() + $ttl);
  }

  /**
   * Caches Yelp review excerpts.
   */
  public function cacheBusinessReviews(string $businessId, array $data, ?string $locale = NULL): void {
    $ttl = max(60, (int) $this->configFactory->get('spotdeals_yelp.settings')->get('cache_ttl_seconds'));
    $this->cache->set($this->getBusinessReviewsCacheId($businessId, $locale), $data, $this->time->getRequestTime() + $ttl);
  }

  /**
   * Returns cached business details.
   */
  public function getCachedBusinessDetails(string $businessId): ?array {
    $cache = $this->cache->get($this->getBusinessDetailsCacheId($businessId));
    return is_array($cache?->data) ? $cache->data : NULL;
  }

  /**
   * Returns cached business reviews.
   */
  public function getCachedBusinessReviews(string $businessId, ?string $locale = NULL): ?array {
    $cache = $this->cache->get($this->getBusinessReviewsCacheId($businessId, $locale));
    return is_array($cache?->data) ? $cache->data : NULL;
  }

  /**
   * Returns basic status counts for admin/reporting use.
   */
  public function getStatusCounts(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $statuses = ['matched', 'needs_review', 'unmatched', 'ignored', 'error'];
    $counts = [];

    foreach ($statuses as $status) {
      $query = $storage->getQuery()
        ->condition('type', 'venue')
        ->condition('field_yelp_match_status', $status)
        ->accessCheck(FALSE);
      $counts[$status] = (int) $query->count()->execute();
    }

    $staleQuery = $storage->getQuery()
      ->condition('type', 'venue')
      ->condition('field_yelp_business_id', '', '<>')
      ->accessCheck(FALSE);
    $counts['with_business_id'] = (int) $staleQuery->count()->execute();

    return $counts;
  }

  /**
   * Returns venues that still need manual review.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Venue nodes.
   */
  public function getNeedsReviewVenues(int $limit = 50): array {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'venue')
      ->condition('field_yelp_match_status', 'needs_review')
      ->accessCheck(FALSE)
      ->sort('changed', 'DESC')
      ->range(0, max(1, $limit))
      ->execute();

    $venues = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    return array_values(array_filter($venues, static fn($venue): bool => $venue instanceof NodeInterface));
  }

  /**
   * Adds node ids to the queue.
   */
  private function enqueueNodeIds(array $nids): int {
    if (empty($nids)) {
      return 0;
    }

    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    foreach ($nids as $nid) {
      $queue->createItem(['nid' => (int) $nid]);
    }

    return count($nids);
  }

  /**
   * Applies a match result to the venue fields.
   */
  private function applyMatchResult(NodeInterface $venue, array $match): void {
    if (!empty($match['business_id'])) {
      $venue->set('field_yelp_business_id', (string) $match['business_id']);
    }

    $status = (string) ($match['status'] ?? 'unmatched');
    $venue->set('field_yelp_match_status', $status);
    $venue->save();
  }

  /**
   * Returns the cache id for business details.
   */
  private function getBusinessDetailsCacheId(string $businessId): string {
    return 'spotdeals_yelp:business:' . $businessId . ':details';
  }

  /**
   * Returns the cache id for business reviews.
   */
  private function getBusinessReviewsCacheId(string $businessId, ?string $locale = NULL): string {
    $locale = $locale ?: 'default';
    return 'spotdeals_yelp:business:' . $businessId . ':reviews:' . $locale;
  }

}
