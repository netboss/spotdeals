<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\spotdeals_search_smart_location\Service\DealFreshnessScorer;

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
    private readonly DealFreshnessScorer $freshnessScorer,
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

    $candidateNids = array_values(array_unique(array_map(
      static fn (array $candidate): int => (int) $candidate['nid'],
      $candidates
    )));
    $freshnessScores = $this->freshnessScorer->scoreDealNids($candidateNids);

    $ranked = [];
    foreach ($candidates as $candidate) {
      $keywordScore = $this->keywordScore($candidate, $keywords, $tokens);
      if ($keywords !== '' && $keywordScore <= 0) {
        continue;
      }

      $nid = (int) $candidate['nid'];
      $freshnessScore = (int) ($freshnessScores[$nid]['score'] ?? 0);
      $totalScore = $keywordScore + $freshnessScore;
      $ranked[] = [
        'nid' => $nid,
        'distance' => (float) $candidate['distance'],
        'distance_bucket' => $this->distanceBucket((float) $candidate['distance']),
        'score' => $totalScore,
        'relevance_tier' => $this->relevanceTier($keywordScore),
        'freshness_score' => $freshnessScore,
        'position' => (int) $candidate['position'],
      ];
    }

    usort($ranked, static function (array $a, array $b) use ($keywords): int {
      // Keyword searches should feel like search first, near-me second.
      // Within the same relevance tier, prefer closer results before tiny
      // score differences. This keeps true happy-hour/taco/etc. matches near
      // the user ahead of farther matches that only have a small freshness or
      // venue-tag boost.
      if ($keywords !== '') {
        $tierCompare = ($b['relevance_tier'] ?? 0) <=> ($a['relevance_tier'] ?? 0);
        if ($tierCompare !== 0) {
          return $tierCompare;
        }

        $bucketCompare = $a['distance_bucket'] <=> $b['distance_bucket'];
        if ($bucketCompare !== 0) {
          return $bucketCompare;
        }

        $scoreCompare = $b['score'] <=> $a['score'];
        if ($scoreCompare !== 0) {
          return $scoreCompare;
        }
      }
      else {
        $bucketCompare = $a['distance_bucket'] <=> $b['distance_bucket'];
        if ($bucketCompare !== 0) {
          return $bucketCompare;
        }

        $scoreCompare = $b['score'] <=> $a['score'];
        if ($scoreCompare !== 0) {
          return $scoreCompare;
        }
      }

      $distanceCompare = $a['distance'] <=> $b['distance'];
      if ($distanceCompare !== 0) {
        return $distanceCompare;
      }

      $positionCompare = $a['position'] <=> $b['position'];
      if ($positionCompare !== 0) {
        return $positionCompare;
      }

      return $a['nid'] <=> $b['nid'];
    });

    $rankedNids = array_values(array_map(static fn(array $row): int => $row['nid'], $ranked));

    $this->logTiming(sprintf(
      'SMART LOCATION near-me ranking timing: total_ms="%s" radius_km="%s" keywords="%s" tokens_count="%d" cache_hit="%s" candidate_count="%d" ranked_count="%d" freshness_scored_count="%d"',
      $this->formatMs($startedAt),
      (string) $radiusKm,
      $keywords,
      count($tokens),
      $cacheHit ? '1' : '0',
      count($candidates),
      count($rankedNids),
      count($freshnessScores),
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

    // "Happy hour" is a deal-intent phrase. Keep it strict so venue tags like
    // "bar food burgers grill happy hour wings" cannot qualify unrelated deals.
    if ($keywords === 'happy hour') {
      $score = 0;

      if ($dealTitle !== '' && str_contains($dealTitle, 'happy hour')) {
        $score += 300;
      }
      if ($body !== '' && str_contains($body, 'happy hour')) {
        $score += 140;
      }

      foreach (['happy', 'hour'] as $token) {
        if (str_contains($dealTitle, $token)) {
          $score += 45;
        }
        if (str_contains($body, $token)) {
          $score += 20;
        }
      }

      return $score;
    }

    $dealScore = 0;
    $venueScore = 0;

    if ($dealTitle !== '' && str_contains($dealTitle, $keywords)) {
      $dealScore += 300;
    }
    if ($body !== '' && str_contains($body, $keywords)) {
      $dealScore += 140;
    }

    foreach ($tokens as $token) {
      if ($token === '') {
        continue;
      }

      foreach ($this->tokenVariants($token) as $variant) {
        if ($variant === '') {
          continue;
        }

        if (str_contains($dealTitle, $variant)) {
          $dealScore += $variant === $token ? 45 : 38;
          break;
        }
      }

      foreach ($this->tokenVariants($token) as $variant) {
        if ($variant === '') {
          continue;
        }

        if (str_contains($body, $variant)) {
          $dealScore += $variant === $token ? 25 : 20;
          break;
        }
      }
    }

    if ($venueTitle !== '' && str_contains($venueTitle, $keywords)) {
      $venueScore += 45;
    }
    if ($venueCuisine !== '' && str_contains($venueCuisine, $keywords)) {
      $venueScore += 70;
    }
    if ($venueTags !== '' && str_contains($venueTags, $keywords)) {
      $venueScore += 45;
    }
    if ($venueDescription !== '' && str_contains($venueDescription, $keywords)) {
      $venueScore += 20;
    }

    foreach ($tokens as $token) {
      if ($token === '') {
        continue;
      }

      foreach ($this->tokenVariants($token) as $variant) {
        if ($variant === '') {
          continue;
        }

        if (str_contains($venueTitle, $variant)) {
          $venueScore += $variant === $token ? 10 : 8;
          break;
        }
      }

      foreach ($this->tokenVariants($token) as $variant) {
        if ($variant === '') {
          continue;
        }

        if (str_contains($venueCuisine, $variant)) {
          $venueScore += $variant === $token ? 12 : 10;
          break;
        }
      }

      foreach ($this->tokenVariants($token) as $variant) {
        if ($variant === '') {
          continue;
        }

        if (str_contains($venueTags, $variant)) {
          $venueScore += $variant === $token ? 8 : 6;
          break;
        }
      }

      foreach ($this->tokenVariants($token) as $variant) {
        if ($variant === '') {
          continue;
        }

        if (str_contains($venueDescription, $variant)) {
          $venueScore += $variant === $token ? 4 : 3;
          break;
        }
      }
    }

    $cuisineAliases = $this->cuisineIntentAliases($keywords, $tokens);
    $isCuisineIntent = $cuisineAliases !== [];
    $cuisineVenueScore = 0;

    if ($isCuisineIntent) {
      foreach ($cuisineAliases as $alias) {
        if ($alias === '') {
          continue;
        }

        if ($dealTitle !== '' && str_contains($dealTitle, $alias)) {
          $dealScore += $alias === $keywords ? 60 : 35;
        }
        if ($body !== '' && str_contains($body, $alias)) {
          $dealScore += $alias === $keywords ? 30 : 18;
        }

        if ($venueTitle !== '' && str_contains($venueTitle, $alias)) {
          $cuisineVenueScore += $alias === $keywords ? 45 : 25;
        }
        if ($venueCuisine !== '' && str_contains($venueCuisine, $alias)) {
          $cuisineVenueScore += $alias === $keywords ? 70 : 40;
        }
        if ($venueTags !== '' && str_contains($venueTags, $alias)) {
          $cuisineVenueScore += $alias === $keywords ? 45 : 28;
        }
        if ($venueDescription !== '' && str_contains($venueDescription, $alias)) {
          $cuisineVenueScore += $alias === $keywords ? 20 : 12;
        }
      }
    }

    // Deal-intent searches must match deal-owned text. Cuisine-intent searches
    // are different: "mexican", "thai", "pizza", "burger", etc. should return
    // active deals at matching venues, even when the individual deal title is
    // generic. Deal-owned matches still receive a large lead so specific offers
    // like "Taco Tuesday" rank above venue-only cuisine matches.
    if ($dealScore <= 0) {
      if (!$isCuisineIntent || ($venueScore + $cuisineVenueScore) <= 0) {
        return 0;
      }

      return 100 + min($venueScore + $cuisineVenueScore, 140);
    }

    if ($isCuisineIntent) {
      return 500 + $dealScore + min($venueScore + $cuisineVenueScore, 140);
    }

    return $dealScore + min($venueScore, 40);
  }

  /**
   * Converts a keyword score into a coarse relevance tier for sorting.
   *
   * Tier 3: strong deal-owned/cuisine deal matches.
   * Tier 2: strict deal-intent matches such as happy hour.
   * Tier 1: cuisine/venue-only fallback matches.
   */
  private function relevanceTier(int $keywordScore): int {
    if ($keywordScore >= 500) {
      return 3;
    }
    if ($keywordScore >= 250) {
      return 2;
    }
    if ($keywordScore > 0) {
      return 1;
    }

    return 0;
  }

  /**
   * Returns cuisine-intent aliases for searches where venue cuisine/tags may qualify a deal.
   *
   * @param array<int,string> $tokens
   *   Query tokens.
   *
   * @return array<int,string>
   *   Normalized cuisine aliases.
   */
  private function cuisineIntentAliases(string $keywords, array $tokens): array {
    $map = [
      'american' => ['american'],
      'asian' => ['asian', 'thai', 'japanese', 'chinese', 'sushi', 'ramen', 'hibachi'],
      'bbq' => ['bbq', 'barbecue', 'bar b q', 'bar-b-q'],
      'barbecue' => ['barbecue', 'bbq', 'bar b q', 'bar-b-q'],
      'burger' => ['burger', 'burgers'],
      'burgers' => ['burgers', 'burger'],
      'burrito' => ['burrito', 'burritos', 'mexican', 'tex mex', 'tex-mex'],
      'burritos' => ['burritos', 'burrito', 'mexican', 'tex mex', 'tex-mex'],
      'cafe' => ['cafe', 'coffee'],
      'chinese' => ['chinese', 'asian'],
      'coffee' => ['coffee', 'cafe'],
      'deli' => ['deli', 'sandwich', 'sandwiches'],
      'hibachi' => ['hibachi', 'japanese', 'asian'],
      'italian' => ['italian', 'pizza', 'pasta'],
      'japanese' => ['japanese', 'sushi', 'ramen', 'hibachi', 'asian'],
      'mexican' => ['mexican', 'tex mex', 'tex-mex', 'taco', 'tacos', 'burrito', 'burritos', 'quesadilla', 'quesadillas', 'enchilada', 'enchiladas'],
      'pasta' => ['pasta', 'italian'],
      'pizza' => ['pizza', 'italian'],
      'ramen' => ['ramen', 'japanese', 'asian'],
      'sandwich' => ['sandwich', 'sandwiches', 'deli'],
      'sandwiches' => ['sandwiches', 'sandwich', 'deli'],
      'seafood' => ['seafood', 'oyster', 'oysters', 'raw bar'],
      'sushi' => ['sushi', 'japanese', 'asian'],
      'taco' => ['taco', 'tacos', 'mexican', 'tex mex', 'tex-mex'],
      'tacos' => ['tacos', 'taco', 'mexican', 'tex mex', 'tex-mex'],
      'thai' => ['thai', 'pad thai', 'thai curry', 'asian'],
      'tex mex' => ['tex mex', 'tex-mex', 'mexican', 'taco', 'tacos'],
      'tex-mex' => ['tex-mex', 'tex mex', 'mexican', 'taco', 'tacos'],
      'wings' => ['wings', 'chicken'],
    ];

    $candidates = array_filter(array_merge([$keywords], $tokens));
    $aliases = [];

    foreach ($candidates as $candidate) {
      $candidate = $this->normalize($candidate);
      if (isset($map[$candidate])) {
        $aliases = array_merge($aliases, $map[$candidate]);
      }
    }

    return array_values(array_unique(array_map(
      fn(string $alias): string => $this->normalize($alias),
      $aliases
    )));
  }

  /**
   * Returns conservative singular/plural variants for a normalized token.
   *
   * This lets a user search for "tacos" and still match deal-owned text like
   * "Taco Tuesday".
   *
   * @return array<int,string>
   *   Token variants, ordered from strongest to weakest.
   */
  private function tokenVariants(string $token): array {
    $token = trim($token);
    if ($token === '') {
      return [];
    }

    $variants = [$token];

    if (str_ends_with($token, 'ies') && mb_strlen($token) > 3) {
      $variants[] = mb_substr($token, 0, -3) . 'y';
    }
    elseif (str_ends_with($token, 'es') && mb_strlen($token) > 3) {
      $variants[] = mb_substr($token, 0, -2);
    }
    elseif (str_ends_with($token, 's') && mb_strlen($token) > 3) {
      $variants[] = mb_substr($token, 0, -1);
    }
    else {
      $variants[] = $token . 's';
    }

    return array_values(array_unique(array_filter($variants)));
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
   * Returns a conservative distance bucket used before secondary ranking.
   *
   * Deals still rank by proximity first, but comparable deals within about
   * half a kilometer can now be ordered by text relevance and freshness.
   */
  private function distanceBucket(float $distanceKm): int {
    return (int) floor(max(0.0, $distanceKm) * 2);
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
