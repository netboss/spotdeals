<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Computes deterministic near-me ranking for deals.
 */
final class NearMeRanker {

  /**
   * Cache ID for the cross-request base dataset cache.
   */
  private const BASE_DATASET_CACHE_ID = 'spotdeals_search_smart_location:near_me_ranker:base_dataset:v1';

  /**
   * Default radius in kilometers.
   */
  private const DEFAULT_RADIUS_KM = 25.0;

  /**
   * Request-local cache of all nearby deal candidates for one origin.
   *
   * The cache is keyed only by origin so repeated recommendation attempts with
   * different radii can reuse the same loaded venue/deal dataset.
   *
   * @var array<string,array<int,array<string,int|float|string>>>
   */
  private array $nearbyDealCandidateCache = [];

  /**
   * Request-local cache of active deal rows keyed by deal node ID.
   *
   * Each row stores only the base data needed to assemble candidates quickly.
   *
   * @var array<int,array<string,int|string>>|null
   */
  private ?array $activeDealRowCache = NULL;

  /**
   * Request-local cache of active venue metadata keyed by venue node ID.
   *
   * @var array<int,array<string,float|string>>|null
   */
  private ?array $activeVenueMetadataCache = NULL;

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
   * Distance is primary. Keywords influence ranking confidence, but nearby
   * candidates inside the radius should not be excluded solely because their
   * text fields are sparse or incomplete.
   *
   * @return array<int, int>
   *   Ordered deal node IDs.
   */
  public function rankDealNids(
    float $originLat,
    float $originLon,
    string $keywords,
    float $radiusKm = self::DEFAULT_RADIUS_KM,
  ): array {
    $startedAt = microtime(TRUE);

    $keywords = $this->normalize($keywords);
    $tokens = $this->tokens($keywords);

    $cacheKey = $this->buildCacheKey($originLat, $originLon);
    $cacheHit = isset($this->nearbyDealCandidateCache[$cacheKey]);

    $allCandidates = $this->getAllDealCandidatesForOrigin($originLat, $originLon);
    if (empty($allCandidates)) {
      $this->logTiming(sprintf(
        'SMART LOCATION near-me ranking timing: total_ms="%s" radius_km="%s" keywords="%s" tokens_count="%d" cache_hit="%s" candidate_count="0" ranked_count="0"',
        $this->formatMs($startedAt),
        (string) $radiusKm,
        $keywords,
        count($tokens),
        $cacheHit ? '1' : '0',
      ));
      return [];
    }

    $candidates = $this->filterCandidatesByRadius($allCandidates, $radiusKm);
    if (empty($candidates)) {
      $this->logTiming(sprintf(
        'SMART LOCATION near-me ranking timing: total_ms="%s" radius_km="%s" keywords="%s" tokens_count="%d" cache_hit="%s" candidate_count="0" ranked_count="0"',
        $this->formatMs($startedAt),
        (string) $radiusKm,
        $keywords,
        count($tokens),
        $cacheHit ? '1' : '0',
      ));
      return [];
    }

    $ranked = [];
    foreach ($candidates as $candidate) {
      $ranked[] = [
        'nid' => (int) $candidate['nid'],
        'distance' => (float) $candidate['distance'],
        'score' => $this->keywordScore($candidate, $keywords, $tokens),
        'position' => (int) $candidate['position'],
      ];
    }

    usort($ranked, static function (array $a, array $b): int {
      $distanceCompare = $a['distance'] <=> $b['distance'];
      if ($distanceCompare !== 0) {
        return $distanceCompare;
      }

      $scoreCompare = $b['score'] <=> $a['score'];
      if ($scoreCompare !== 0) {
        return $scoreCompare;
      }

      $positionCompare = $a['position'] <=> $b['position'];
      if ($positionCompare !== 0) {
        return $positionCompare;
      }

      return $a['nid'] <=> $b['nid'];
    });

    $rankedNids = array_values(array_map(static fn(array $row): int => $row['nid'], $ranked));

    $this->logTiming(sprintf(
      'SMART LOCATION near-me ranking timing: total_ms="%s" radius_km="%s" keywords="%s" tokens_count="%d" cache_hit="%s" candidate_count="%d" ranked_count="%d"',
      $this->formatMs($startedAt),
      (string) $radiusKm,
      $keywords,
      count($tokens),
      $cacheHit ? '1' : '0',
      count($candidates),
      count($rankedNids),
    ));

    return $rankedNids;
  }

  /**
   * Returns cached deal candidates for one origin, across all distances.
   *
   * @return array<int,array<string,int|float|string>>
   *   Candidate rows.
   */
  private function getAllDealCandidatesForOrigin(
    float $originLat,
    float $originLon,
  ): array {
    $cacheKey = $this->buildCacheKey($originLat, $originLon);

    if (isset($this->nearbyDealCandidateCache[$cacheKey])) {
      return $this->nearbyDealCandidateCache[$cacheKey];
    }

    $this->ensureBaseCachesBuilt();

    if (empty($this->activeDealRowCache) || empty($this->activeVenueMetadataCache)) {
      return $this->nearbyDealCandidateCache[$cacheKey] = [];
    }

    $candidates = [];
    foreach ($this->activeDealRowCache as $dealRow) {
      $venueNid = (int) $dealRow['venue_nid'];
      $venueMetadata = $this->activeVenueMetadataCache[$venueNid] ?? NULL;
      if ($venueMetadata === NULL) {
        continue;
      }

      $candidates[] = [
        'nid' => (int) $dealRow['nid'],
        'distance' => $this->haversineKm(
          $originLat,
          $originLon,
          (float) $venueMetadata['lat'],
          (float) $venueMetadata['lon'],
        ),
        'position' => (int) $dealRow['position'],
        'deal_title' => (string) $dealRow['deal_title'],
        'deal_body' => (string) $dealRow['deal_body'],
        'venue_title' => (string) $venueMetadata['title'],
        'venue_description' => (string) $venueMetadata['description'],
        'venue_cuisine' => (string) $venueMetadata['cuisine'],
        'venue_tags' => (string) $venueMetadata['tags'],
      ];
    }

    return $this->nearbyDealCandidateCache[$cacheKey] = $candidates;
  }

  /**
   * Builds request-local base caches for active deals and referenced venues.
   *
   * This keeps the behavior intact but avoids the previous full venue scan
   * followed by a second deal query constrained by all venue IDs.
   */
  private function ensureBaseCachesBuilt(): void {
    if ($this->activeDealRowCache !== NULL && $this->activeVenueMetadataCache !== NULL) {
      return;
    }

    $cacheLookupStartedAt = microtime(TRUE);

    $cached = \Drupal::cache()->get(self::BASE_DATASET_CACHE_ID);
    if ($cached !== FALSE && is_array($cached->data)) {
      $dealRows = $cached->data['deal_rows'] ?? NULL;
      $venueMetadata = $cached->data['venue_metadata'] ?? NULL;

      if (is_array($dealRows) && is_array($venueMetadata)) {
        $this->activeDealRowCache = $dealRows;
        $this->activeVenueMetadataCache = $venueMetadata;

        $this->logTiming(sprintf(
          'SMART LOCATION base dataset cache: hit total_ms="%s" deals="%d" venues="%d"',
          $this->formatMs($cacheLookupStartedAt),
          count($dealRows),
          count($venueMetadata),
        ));
        return;
      }
    }

    $buildStartedAt = microtime(TRUE);

    $this->activeDealRowCache = [];
    $this->activeVenueMetadataCache = [];

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $dealNids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'deal')
      ->condition('status', 1)
      ->execute();

    if (empty($dealNids)) {
      $this->storeBaseCaches();
      $this->logBaseDatasetBuild($buildStartedAt);
      return;
    }

    $dealPositions = array_flip(array_values($dealNids));
    $deals = $this->loadNodesSafely($nodeStorage, $dealNids, 'deal');
    if (empty($deals)) {
      $this->storeBaseCaches();
      $this->logBaseDatasetBuild($buildStartedAt);
      return;
    }

    $venueNids = [];
    foreach ($deals as $deal) {
      if (!$deal->hasField('field_venue') || $deal->get('field_venue')->isEmpty()) {
        continue;
      }

      $venueNid = (int) $deal->get('field_venue')->target_id;
      if ($venueNid > 0) {
        $venueNids[$venueNid] = $venueNid;
      }
    }

    if (empty($venueNids)) {
      $this->storeBaseCaches();
      $this->logBaseDatasetBuild($buildStartedAt);
      return;
    }

    $venues = $this->loadNodesSafely($nodeStorage, array_values($venueNids), 'venue');
    if (empty($venues)) {
      $this->storeBaseCaches();
      $this->logBaseDatasetBuild($buildStartedAt);
      return;
    }

    foreach ($venues as $venue) {
      $coords = $this->resultExtractor->extractVenueCoords($venue);
      if ($coords === NULL) {
        continue;
      }

      [$lat, $lon] = $coords;
      $venueNid = (int) $venue->id();

      $this->activeVenueMetadataCache[$venueNid] = [
        'lat' => $lat,
        'lon' => $lon,
        'title' => $this->normalize((string) $venue->label()),
        'description' => $this->normalize(
          $venue->hasField('field_short_description') && !$venue->get('field_short_description')->isEmpty()
            ? (string) $venue->get('field_short_description')->value
            : ''
        ),
        'cuisine' => $this->normalize($this->venueCuisineText($venue)),
        'tags' => $this->normalize($this->venueTagsText($venue)),
      ];
    }

    if (empty($this->activeVenueMetadataCache)) {
      $this->activeDealRowCache = [];
      $this->storeBaseCaches();
      $this->logBaseDatasetBuild($buildStartedAt);
      return;
    }

    foreach ($deals as $deal) {
      if (!$deal->hasField('field_venue') || $deal->get('field_venue')->isEmpty()) {
        continue;
      }

      $venueNid = (int) $deal->get('field_venue')->target_id;
      if ($venueNid <= 0 || !isset($this->activeVenueMetadataCache[$venueNid])) {
        continue;
      }

      $dealNid = (int) $deal->id();
      $this->activeDealRowCache[$dealNid] = [
        'nid' => $dealNid,
        'venue_nid' => $venueNid,
        'position' => (int) ($dealPositions[$dealNid] ?? PHP_INT_MAX),
        'deal_title' => $this->normalize((string) $deal->label()),
        'deal_body' => $this->normalize(
          $deal->hasField('body') && !$deal->get('body')->isEmpty()
            ? (string) $deal->get('body')->value
            : ''
        ),
      ];
    }

    $this->storeBaseCaches();
    $this->logBaseDatasetBuild($buildStartedAt);
  }

  /**
   * Stores the base dataset caches across requests.
   */
  private function storeBaseCaches(): void {
    \Drupal::cache()->set(
      self::BASE_DATASET_CACHE_ID,
      [
        'deal_rows' => $this->activeDealRowCache ?? [],
        'venue_metadata' => $this->activeVenueMetadataCache ?? [],
      ],
      Cache::PERMANENT,
      ['node_list']
    );
  }

  /**
   * Logs base dataset build timing details.
   */
  private function logBaseDatasetBuild(float $buildStartedAt): void {
    $this->logTiming(sprintf(
      'SMART LOCATION base dataset cache: miss build_ms="%s" deals="%d" venues="%d"',
      $this->formatMs($buildStartedAt),
      count($this->activeDealRowCache ?? []),
      count($this->activeVenueMetadataCache ?? []),
    ));
  }

  /**
   * Filters cached candidates to the requested radius.
   *
   * Reassigns positions after filtering so tie-breaking stays stable inside the
   * current radius-specific subset.
   *
   * @param array<int,array<string,int|float|string>> $candidates
   *   Unfiltered candidate rows.
   *
   * @return array<int,array<string,int|float|string>>
   *   Radius-filtered candidate rows.
   */
  private function filterCandidatesByRadius(array $candidates, float $radiusKm): array {
    $filtered = [];

    foreach ($candidates as $candidate) {
      if ((float) $candidate['distance'] > $radiusKm) {
        continue;
      }

      $candidate['position'] = count($filtered);
      $filtered[] = $candidate;
    }

    return $filtered;
  }

  /**
   * Loads node entities in bulk, with per-entity fallback for broken records.
   *
   * This keeps requests fast in the normal case while still skipping corrupted
   * entities safely if bulk loading fails.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   Node storage.
   * @param array<int,int|string> $ids
   *   Entity IDs to load.
   * @param string $label
   *   Context label for logging.
   *
   * @return array<int,\Drupal\node\NodeInterface>
   *   Loaded nodes only.
   */
  private function loadNodesSafely(EntityStorageInterface $storage, array $ids, string $label): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (empty($ids)) {
      return [];
    }

    try {
      $entities = $storage->loadMultiple($ids);
    }
    catch (\Throwable $e) {
      \Drupal::logger('spotdeals_search_smart_location')->warning(
        'SMART LOCATION bulk load failed for @label entities, falling back to safe single loads: count="@count" error="@error"',
        [
          '@label' => $label,
          '@count' => (string) count($ids),
          '@error' => $e->getMessage(),
        ]
      );
      return $this->safeLoadNodesIndividually($storage, $ids, $label);
    }

    $loaded = [];
    foreach ($ids as $id) {
      $entity = $entities[$id] ?? NULL;
      if (!$entity instanceof NodeInterface) {
        if ($entity !== NULL) {
          \Drupal::logger('spotdeals_search_smart_location')->warning(
            'SMART LOCATION skipped non-node @label entity during bulk load: id="@id"',
            [
              '@label' => $label,
              '@id' => (string) $id,
            ]
          );
        }
        continue;
      }

      $loaded[] = $entity;
    }

    return $loaded;
  }

  /**
   * Safely loads node entities one by one and skips broken records.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   Node storage.
   * @param array<int,int> $ids
   *   Entity IDs to load.
   * @param string $label
   *   Context label for logging.
   *
   * @return array<int,\Drupal\node\NodeInterface>
   *   Loaded nodes only.
   */
  private function safeLoadNodesIndividually(EntityStorageInterface $storage, array $ids, string $label): array {
    $loaded = [];

    foreach ($ids as $id) {
      if ($id <= 0) {
        continue;
      }

      try {
        $entity = $storage->load($id);
      }
      catch (\Throwable $e) {
        \Drupal::logger('spotdeals_search_smart_location')->error(
          'SMART LOCATION skipped broken @label entity during safe load: id="@id" error="@error"',
          [
            '@label' => $label,
            '@id' => (string) $id,
            '@error' => $e->getMessage(),
          ]
        );
        continue;
      }

      if (!$entity instanceof NodeInterface) {
        \Drupal::logger('spotdeals_search_smart_location')->warning(
          'SMART LOCATION skipped non-node @label entity during safe load: id="@id"',
          [
            '@label' => $label,
            '@id' => (string) $id,
          ]
        );
        continue;
      }

      $loaded[] = $entity;
    }

    return $loaded;
  }

  /**
   * Scores a candidate against the near-me keyword query.
   *
   * @param array<string,int|float|string> $candidate
   *   Candidate row.
   * @param array<int,string> $tokens
   *   Query tokens.
   */
  private function keywordScore(array $candidate, string $keywords, array $tokens): int {
    if ($keywords === '') {
      return 10;
    }

    $dealTitle = (string) ($candidate['deal_title'] ?? '');
    $body = (string) ($candidate['deal_body'] ?? '');
    $venueTitle = (string) ($candidate['venue_title'] ?? '');
    $venueDescription = (string) ($candidate['venue_description'] ?? '');
    $venueCuisine = (string) ($candidate['venue_cuisine'] ?? '');
    $venueTags = (string) ($candidate['venue_tags'] ?? '');

    $score = 0;

    if ($dealTitle !== '' && str_contains($dealTitle, $keywords)) {
      $score += 120;
    }
    if ($venueTitle !== '' && str_contains($venueTitle, $keywords)) {
      $score += 100;
    }
    if ($venueCuisine !== '' && str_contains($venueCuisine, $keywords)) {
      $score += 90;
    }
    if ($venueTags !== '' && str_contains($venueTags, $keywords)) {
      $score += 75;
    }
    if ($body !== '' && str_contains($body, $keywords)) {
      $score += 60;
    }
    if ($venueDescription !== '' && str_contains($venueDescription, $keywords)) {
      $score += 50;
    }

    foreach ($tokens as $token) {
      if ($token === '') {
        continue;
      }

      if (str_contains($dealTitle, $token)) {
        $score += 20;
      }
      if (str_contains($venueTitle, $token)) {
        $score += 18;
      }
      if (str_contains($venueCuisine, $token)) {
        $score += 16;
      }
      if (str_contains($venueTags, $token)) {
        $score += 14;
      }
      if (str_contains($body, $token)) {
        $score += 10;
      }
      if (str_contains($venueDescription, $token)) {
        $score += 8;
      }
    }

    return $score;
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
    $earthRadiusKm = 6371.0;

    $lat1Rad = deg2rad($lat1);
    $lon1Rad = deg2rad($lon1);
    $lat2Rad = deg2rad($lat2);
    $lon2Rad = deg2rad($lon2);

    $dlat = $lat2Rad - $lat1Rad;
    $dlon = $lon2Rad - $lon1Rad;

    $a = sin($dlat / 2) ** 2
      + cos($lat1Rad) * cos($lat2Rad) * sin($dlon / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadiusKm * $c;
  }

  /**
   * Builds a request-local cache key.
   */
  private function buildCacheKey(float $originLat, float $originLon): string {
    return implode(':', [
      number_format($originLat, 6, '.', ''),
      number_format($originLon, 6, '.', ''),
    ]);
  }

  /**
   * Logs timing details for near-me ranking.
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
