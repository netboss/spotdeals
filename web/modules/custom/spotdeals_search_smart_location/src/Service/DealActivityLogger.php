<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Logs deal activity and returns activity-based trending candidates.
 */
final class DealActivityLogger {

  /**
   * Activity table name.
   */
  private const TABLE = 'spotdeals_search_smart_location_activity';

  /**
   * Supported activity actions.
   */
  private const ALLOWED_ACTIONS = ['view', 'click'];

  /**
   * Minimum seconds before the same visitor/action/deal is logged again.
   */
  private const VIEW_DEDUPE_WINDOW = 300;

  /**
   * Minimum seconds before the same visitor/deal click is logged again.
   */
  private const CLICK_DEDUPE_WINDOW = 60;

  /**
   * Cache lifetime for trending calculations.
   */
  private const TRENDING_CACHE_TTL = 60;

  /**
   * Recency half-life for activity signals, in seconds.
   */
  private const ACTIVITY_HALF_LIFE = 172800;

  /**
   * Constructs the deal activity logger.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cacheBackend,
  ) {}

  /**
   * Logs one deal activity signal.
   */
  public function log(int $dealNid, string $action = 'view', string $source = '', int $venueNid = 0): void {
    $dealNid = max(0, $dealNid);
    if ($dealNid <= 0) {
      return;
    }

    $action = strtolower(trim($action));
    if (!in_array($action, self::ALLOWED_ACTIONS, TRUE)) {
      return;
    }

    $source = mb_substr(preg_replace('/[^a-z0-9_\-]+/i', '_', strtolower(trim($source))) ?? '', 0, 32);
    $uid = (int) $this->currentUser->id();
    $anonHash = $uid > 0 ? '' : $this->buildAnonymousHash();
    $venueNid = $venueNid > 0 ? $venueNid : $this->resolveVenueNid($dealNid);
    $now = $this->time->getRequestTime();

    if ($this->isDuplicate($dealNid, $action, $uid, $anonHash, $now)) {
      return;
    }

    $this->database->insert(self::TABLE)
      ->fields([
        'deal_nid' => $dealNid,
        'venue_nid' => max(0, $venueNid),
        'uid' => max(0, $uid),
        'anon_hash' => $anonHash,
        'action' => $action,
        'source' => $source,
        'created' => $now,
      ])
      ->execute();
  }

  /**
   * Returns activity-ranked deal IDs.
   *
   * @return array<int,array{deal_nid:int,venue_nid:int,score:float,views:int,clicks:int,votes:int,distance_km:float|null,latest:int}>
   *   Trending rows keyed numerically and sorted by score descending.
   */
  public function getTrendingDeals(?float $originLat = NULL, ?float $originLon = NULL, int $limit = 6, int $days = 14, ?float $radiusKm = 25.0): array {
    $limit = max(1, min($limit, 20));
    $days = max(1, min($days, 90));
    $radiusKm = $radiusKm !== NULL ? max(1.0, min($radiusKm, 250.0)) : NULL;

    $cacheKey = $this->buildTrendingCacheKey($originLat, $originLon, $limit, $days, $radiusKm);
    $cached = $this->cacheBackend->get($cacheKey);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $rows = $this->calculateTrendingDeals($originLat, $originLon, $limit, $days, $radiusKm);
    $this->cacheBackend->set($cacheKey, $rows, $this->time->getRequestTime() + self::TRENDING_CACHE_TTL);

    return $rows;
  }

  /**
   * Calculates activity-ranked deal IDs.
   *
   * @return array<int,array{deal_nid:int,venue_nid:int,score:float,views:int,clicks:int,votes:int,distance_km:float|null,latest:int}>
   *   Trending rows keyed numerically and sorted by score descending.
   */
  private function calculateTrendingDeals(?float $originLat, ?float $originLon, int $limit, int $days, ?float $radiusKm): array {
    $now = $this->time->getRequestTime();
    $since = $now - ($days * 86400);

    $activity = $this->loadWeightedRecentActivity($since, $now);
    $voteActivity = $this->loadWeightedRecentVoteActivity($since, $now);

    foreach ($voteActivity as $dealNid => $row) {
      if (!isset($activity[$dealNid])) {
        $activity[$dealNid] = [
          'deal_nid' => $dealNid,
          'venue_nid' => (int) ($row['venue_nid'] ?? 0),
          'views' => 0,
          'clicks' => 0,
          'votes' => 0,
          'weighted_views' => 0.0,
          'weighted_clicks' => 0.0,
          'weighted_votes' => 0.0,
          'latest' => 0,
        ];
      }
      $activity[$dealNid]['votes'] = (int) ($row['votes'] ?? 0);
      $activity[$dealNid]['weighted_votes'] = (float) ($row['weighted_votes'] ?? 0.0);
      if ((int) ($row['latest'] ?? 0) > (int) $activity[$dealNid]['latest']) {
        $activity[$dealNid]['latest'] = (int) $row['latest'];
      }
      if ((int) $activity[$dealNid]['venue_nid'] <= 0 && (int) ($row['venue_nid'] ?? 0) > 0) {
        $activity[$dealNid]['venue_nid'] = (int) $row['venue_nid'];
      }
    }

    if ($activity === []) {
      return [];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple(array_keys($activity));
    $rows = [];

    foreach ($activity as $dealNid => $row) {
      $deal = $nodes[$dealNid] ?? NULL;
      if (!$deal instanceof NodeInterface || $deal->bundle() !== 'deal' || !$deal->isPublished()) {
        continue;
      }

      $venue = $this->loadDealVenue($deal);
      if (!$venue instanceof NodeInterface || !$venue->isPublished()) {
        continue;
      }

      $distanceKm = NULL;
      if ($originLat !== NULL && $originLon !== NULL) {
        $coords = $this->getNodeCoordinates($venue);
        if ($coords === NULL) {
          continue;
        }
        $distanceKm = $this->distanceKm($originLat, $originLon, $coords['lat'], $coords['lon']);
        if ($radiusKm !== NULL && $radiusKm > 0 && $distanceKm > $radiusKm) {
          continue;
        }
      }

      $latest = max(0, (int) ($row['latest'] ?? 0));
      $ageDays = $latest > 0 ? max(0.0, ($now - $latest) / 86400) : (float) $days;
      $recencyBoost = max(0.0, 1.0 - min($ageDays, $days) / max(1, $days));
      $distanceBoost = $distanceKm === NULL || $radiusKm === NULL ? 1.0 : max(0.2, 1.0 - min($distanceKm, $radiusKm) / max(1.0, $radiusKm));

      $views = (int) ($row['views'] ?? 0);
      $clicks = (int) ($row['clicks'] ?? 0);
      $votes = (int) ($row['votes'] ?? 0);
      $weightedViews = (float) ($row['weighted_views'] ?? 0.0);
      $weightedClicks = (float) ($row['weighted_clicks'] ?? 0.0);
      $weightedVotes = (float) ($row['weighted_votes'] ?? 0.0);

      $engagementScore = log(1 + ($weightedViews * 1.0) + ($weightedClicks * 4.0) + ($weightedVotes * 5.0));
      $score = ($engagementScore * 6.0) + ($recencyBoost * 3.0) + ($distanceBoost * 2.0) + $this->stableJitter((int) $dealNid, $now);

      $rows[] = [
        'deal_nid' => (int) $dealNid,
        'venue_nid' => (int) $venue->id(),
        'score' => round($score, 4),
        'views' => $views,
        'clicks' => $clicks,
        'votes' => $votes,
        'distance_km' => $distanceKm !== NULL ? round($distanceKm, 2) : NULL,
        'latest' => $latest,
      ];
    }

    usort($rows, static function (array $a, array $b): int {
      $scoreCompare = ($b['score'] <=> $a['score']);
      if ($scoreCompare !== 0) {
        return $scoreCompare;
      }
      return ($b['latest'] <=> $a['latest']) ?: ($b['deal_nid'] <=> $a['deal_nid']);
    });

    return array_slice($rows, 0, $limit);
  }

  /**
   * Loads weighted recent activity.
   *
   * @return array<int,array<string,int|float>>
   *   Aggregates keyed by deal nid.
   */
  private function loadWeightedRecentActivity(int $since, int $now): array {
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return [];
    }

    $query = $this->database->select(self::TABLE, 'a');
    $query->fields('a', ['deal_nid', 'venue_nid', 'action', 'created']);
    $query->condition('a.created', $since, '>=');
    $query->orderBy('a.created', 'DESC');
    $query->range(0, 5000);

    $rows = [];
    foreach ($query->execute() as $row) {
      $dealNid = (int) $row->deal_nid;
      if ($dealNid <= 0) {
        continue;
      }

      if (!isset($rows[$dealNid])) {
        $rows[$dealNid] = [
          'deal_nid' => $dealNid,
          'venue_nid' => 0,
          'views' => 0,
          'clicks' => 0,
          'votes' => 0,
          'weighted_views' => 0.0,
          'weighted_clicks' => 0.0,
          'weighted_votes' => 0.0,
          'latest' => 0,
        ];
      }

      $created = (int) ($row->created ?? 0);
      $weight = $this->timeDecayWeight($created, $now);
      $action = (string) ($row->action ?? '');

      if ($action === 'view') {
        $rows[$dealNid]['views'] = (int) $rows[$dealNid]['views'] + 1;
        $rows[$dealNid]['weighted_views'] = (float) $rows[$dealNid]['weighted_views'] + $weight;
      }
      elseif ($action === 'click') {
        $rows[$dealNid]['clicks'] = (int) $rows[$dealNid]['clicks'] + 1;
        $rows[$dealNid]['weighted_clicks'] = (float) $rows[$dealNid]['weighted_clicks'] + $weight;
      }

      if ((int) $rows[$dealNid]['venue_nid'] <= 0 && (int) ($row->venue_nid ?? 0) > 0) {
        $rows[$dealNid]['venue_nid'] = (int) $row->venue_nid;
      }
      if ($created > (int) $rows[$dealNid]['latest']) {
        $rows[$dealNid]['latest'] = $created;
      }
    }

    return $rows;
  }

  /**
   * Loads weighted recent vote activity from the existing vote module table.
   *
   * @return array<int,array<string,int|float>>
   *   Vote aggregates keyed by deal nid.
   */
  private function loadWeightedRecentVoteActivity(int $since, int $now): array {
    if (!$this->database->schema()->tableExists('spotdeals_vote')) {
      return [];
    }

    $query = $this->database->select('spotdeals_vote', 'v');
    $query->fields('v', ['deal_nid', 'venue_nid', 'changed']);
    $query->condition('v.changed', $since, '>=');
    $query->orderBy('v.changed', 'DESC');
    $query->range(0, 5000);

    $rows = [];
    foreach ($query->execute() as $row) {
      $dealNid = (int) $row->deal_nid;
      if ($dealNid <= 0) {
        continue;
      }

      if (!isset($rows[$dealNid])) {
        $rows[$dealNid] = [
          'deal_nid' => $dealNid,
          'venue_nid' => 0,
          'votes' => 0,
          'weighted_votes' => 0.0,
          'latest' => 0,
        ];
      }

      $changed = (int) ($row->changed ?? 0);
      $rows[$dealNid]['votes'] = (int) $rows[$dealNid]['votes'] + 1;
      $rows[$dealNid]['weighted_votes'] = (float) $rows[$dealNid]['weighted_votes'] + $this->timeDecayWeight($changed, $now);

      if ((int) $rows[$dealNid]['venue_nid'] <= 0 && (int) ($row->venue_nid ?? 0) > 0) {
        $rows[$dealNid]['venue_nid'] = (int) $row->venue_nid;
      }
      if ($changed > (int) $rows[$dealNid]['latest']) {
        $rows[$dealNid]['latest'] = $changed;
      }
    }

    return $rows;
  }

  /**
   * Returns TRUE when a duplicate activity should be skipped.
   */
  private function isDuplicate(int $dealNid, string $action, int $uid, string $anonHash, int $now): bool {
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return TRUE;
    }

    $query = $this->database->select(self::TABLE, 'a');
    $query->addExpression('COUNT(*)');
    $query->condition('deal_nid', $dealNid);
    $query->condition('action', $action);
    $dedupeWindow = $action === 'view' ? self::VIEW_DEDUPE_WINDOW : self::CLICK_DEDUPE_WINDOW;
    $query->condition('created', $now - $dedupeWindow, '>=');

    if ($uid > 0) {
      $query->condition('uid', $uid);
    }
    elseif ($anonHash !== '') {
      $query->condition('anon_hash', $anonHash);
    }
    else {
      return FALSE;
    }

    return (int) $query->execute()->fetchField() > 0;
  }

  /**
   * Resolves the venue node ID for a deal.
   */
  private function resolveVenueNid(int $dealNid): int {
    $deal = $this->entityTypeManager->getStorage('node')->load($dealNid);
    if (!$deal instanceof NodeInterface) {
      return 0;
    }

    $venue = $this->loadDealVenue($deal);
    return $venue instanceof NodeInterface ? (int) $venue->id() : 0;
  }

  /**
   * Loads a deal's venue.
   */
  private function loadDealVenue(NodeInterface $deal): ?NodeInterface {
    if (!$deal->hasField('field_venue') || $deal->get('field_venue')->isEmpty()) {
      return NULL;
    }

    $venue = $deal->get('field_venue')->entity;
    return $venue instanceof NodeInterface ? $venue : NULL;
  }

  /**
   * Returns node coordinates when available.
   *
   * @return array{lat:float,lon:float}|null
   *   Coordinates or NULL.
   */
  private function getNodeCoordinates(NodeInterface $node): ?array {
    if (
      !$node->hasField('field_latitude') ||
      $node->get('field_latitude')->isEmpty() ||
      !$node->hasField('field_longitude') ||
      $node->get('field_longitude')->isEmpty()
    ) {
      return NULL;
    }

    $lat = (float) $node->get('field_latitude')->value;
    $lon = (float) $node->get('field_longitude')->value;

    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
      return NULL;
    }

    return ['lat' => $lat, 'lon' => $lon];
  }

  /**
   * Calculates distance in kilometers.
   */
  private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadiusKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusKm * $c;
  }

  /**
   * Builds a short anonymous hash for rough dedupe only.
   */
  private function buildAnonymousHash(): string {
    $request = \Drupal::request();
    $ip = $request->getClientIp() ?? '';
    $agent = (string) $request->headers->get('User-Agent', '');
    return hash('sha256', $ip . '|' . substr($agent, 0, 160));
  }

  /**
   * Returns a half-life decay weight for recent activity.
   */
  private function timeDecayWeight(int $created, int $now): float {
    if ($created <= 0 || $created > $now) {
      return 0.0;
    }

    $age = max(0, $now - $created);
    return 0.5 ** ($age / self::ACTIVITY_HALF_LIFE);
  }

  /**
   * Adds tiny deterministic variation so tied scores do not feel frozen.
   */
  private function stableJitter(int $dealNid, int $now): float {
    $bucket = (int) floor($now / self::TRENDING_CACHE_TTL);
    $hash = crc32($dealNid . ':' . $bucket);
    return (($hash % 1000) / 1000) * 0.05;
  }

  /**
   * Builds the cache ID for trending rows.
   */
  private function buildTrendingCacheKey(?float $originLat, ?float $originLon, int $limit, int $days, ?float $radiusKm): string {
    $latBucket = $originLat !== NULL ? number_format(round($originLat, 2), 2, '.', '') : 'none';
    $lonBucket = $originLon !== NULL ? number_format(round($originLon, 2), 2, '.', '') : 'none';
    $radiusBucket = $radiusKm !== NULL ? (string) (int) round($radiusKm) : 'none';
    return 'spotdeals_search_smart_location:trending:' . implode(':', [$latBucket, $lonBucket, $limit, $days, $radiusBucket]);
  }

}
