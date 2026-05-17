<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Assigns computed activity badges to deal nodes.
 *
 * The badge field is treated as computed storage. Updates are written directly
 * to the field storage tables so the deal node "changed" timestamp is not
 * modified. That keeps CSV rollback/import safeguards based on created/changed
 * timestamps from treating automatic badge updates as user edits.
 */
final class DealActivityBadgeAssigner {

  /**
   * Activity table name.
   */
  private const ACTIVITY_TABLE = 'spotdeals_search_smart_location_activity';

  /**
   * Activity badge field machine name.
   */
  private const FIELD_NAME = 'field_activity_badges';

  /**
   * Maximum published deals to process in one refresh.
   *
   * Kept above the current dataset size so rule changes can clear obsolete
   * badges, such as removed auto-assigned terms, in a single refresh.
   */
  private const MAX_CANDIDATES = 2500;

  /**
   * Activity term names used by the initial automation pass.
   */
  private const TERM_TRENDING = 'Trending';
  private const TERM_ACTIVE_TODAY = 'Active Today';
  private const TERM_HOT_THIS_WEEK = 'Hot This Week';

  /**
   * Constructs the badge assigner.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly DealActivityLogger $dealActivityLogger,
  ) {}

  /**
   * Refreshes computed activity badges.
   *
   * @return array{processed:int,updated:int,missing_terms:string[]}
   *   Refresh summary.
   */
  public function refresh(int $limit = self::MAX_CANDIDATES): array {
    $limit = max(1, min($limit, self::MAX_CANDIDATES));

    if (!$this->fieldTablesExist()) {
      return [
        'processed' => 0,
        'updated' => 0,
        'missing_terms' => [],
      ];
    }

    $terms = $this->loadActivityTerms();
    $required = [
      self::TERM_TRENDING,
      self::TERM_ACTIVE_TODAY,
      self::TERM_HOT_THIS_WEEK,
    ];
    $missing = array_values(array_filter($required, static fn (string $name): bool => !isset($terms[$name])));

    if ($missing !== []) {
      return [
        'processed' => 0,
        'updated' => 0,
        'missing_terms' => $missing,
      ];
    }

    $now = $this->time->getRequestTime();
    $todayStart = strtotime('today', $now);
    if ($todayStart === FALSE) {
      $todayStart = $now - 86400;
    }

    $weekStart = $now - (7 * 86400);

    $trendingNids = $this->loadTrendingDealNids();
    $activeTodayNids = $this->loadActiveTodayDealNids($todayStart);
    $hotThisWeekNids = $this->loadHotThisWeekDealNids($weekStart);
    $currentlyTaggedNids = $this->loadCurrentlyTaggedDealNids();

    $candidateNids = array_values(array_unique(array_merge(
      $trendingNids,
      $activeTodayNids,
      $hotThisWeekNids,
      $currentlyTaggedNids,
    )));
    $candidateNids = array_slice($candidateNids, 0, $limit);

    if ($candidateNids === []) {
      return [
        'processed' => 0,
        'updated' => 0,
        'missing_terms' => [],
      ];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($candidateNids);
    $updated = 0;

    foreach ($candidateNids as $nid) {
      $deal = $nodes[$nid] ?? NULL;
      if (!$deal instanceof NodeInterface || $deal->bundle() !== 'deal' || !$deal->isPublished()) {
        continue;
      }

      $targetIds = [];

      if (in_array($nid, $trendingNids, TRUE)) {
        $targetIds[] = (int) $terms[self::TERM_TRENDING]->id();
      }
      if (in_array($nid, $activeTodayNids, TRUE)) {
        $targetIds[] = (int) $terms[self::TERM_ACTIVE_TODAY]->id();
      }
      if (in_array($nid, $hotThisWeekNids, TRUE)) {
        $targetIds[] = (int) $terms[self::TERM_HOT_THIS_WEEK]->id();
      }

      $targetIds = array_slice(array_values(array_unique(array_filter($targetIds))), 0, 2);
      if ($this->replaceBadgeValues($deal, $targetIds)) {
        $updated++;
      }
    }

    return [
      'processed' => count($candidateNids),
      'updated' => $updated,
      'missing_terms' => [],
    ];
  }

  /**
   * Loads supported activity terms keyed by name.
   *
   * @return array<string,\Drupal\taxonomy\TermInterface>
   *   Terms keyed by their exact label.
   */
  private function loadActivityTerms(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'activity')
      ->condition('name', [
        self::TERM_TRENDING,
        self::TERM_ACTIVE_TODAY,
        self::TERM_HOT_THIS_WEEK,
      ], 'IN');

    $ids = $query->execute();
    if ($ids === []) {
      return [];
    }

    $terms = [];
    foreach ($storage->loadMultiple($ids) as $term) {
      if ($term instanceof TermInterface) {
        $terms[$term->label()] = $term;
      }
    }

    return $terms;
  }

  /**
   * Loads globally trending deal IDs.
   *
   * @return int[]
   *   Deal node IDs.
   */
  private function loadTrendingDealNids(): array {
    // Keep this badge intentionally scarce so it remains meaningful.
    $rows = $this->dealActivityLogger->getTrendingDeals(NULL, NULL, 25, 3, NULL);
    return array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['deal_nid'] ?? 0), $rows)));
  }

  /**
   * Loads deal IDs with activity today.
   *
   * @return int[]
   *   Deal node IDs.
   */
  private function loadActiveTodayDealNids(int $todayStart): array {
    $nids = [];

    if ($this->database->schema()->tableExists(self::ACTIVITY_TABLE)) {
      $query = $this->database->select(self::ACTIVITY_TABLE, 'a');
      $query->addField('a', 'deal_nid');
      $query->condition('a.created', $todayStart, '>=');
      $query->isNotNull('a.deal_nid');
      $query->range(0, 250);
      foreach ($query->distinct()->execute()->fetchCol() as $nid) {
        $nid = (int) $nid;
        if ($nid > 0) {
          $nids[] = $nid;
        }
      }
    }

    if ($this->database->schema()->tableExists('spotdeals_vote')) {
      $query = $this->database->select('spotdeals_vote', 'v');
      $query->addField('v', 'deal_nid');
      $query->condition('v.changed', $todayStart, '>=');
      $query->condition('v.deal_nid', 0, '>');
      $query->range(0, 250);
      foreach ($query->distinct()->execute()->fetchCol() as $nid) {
        $nid = (int) $nid;
        if ($nid > 0) {
          $nids[] = $nid;
        }
      }
    }

    return array_values(array_unique($nids));
  }

  /**
   * Loads deal IDs with activity in the last seven days.
   *
   * @return int[]
   *   Deal node IDs.
   */
  private function loadHotThisWeekDealNids(int $weekStart): array {
    $nids = [];

    if ($this->database->schema()->tableExists(self::ACTIVITY_TABLE)) {
      $query = $this->database->select(self::ACTIVITY_TABLE, 'a');
      $query->addField('a', 'deal_nid');
      $query->condition('a.created', $weekStart, '>=');
      $query->isNotNull('a.deal_nid');
      $query->range(0, 250);
      foreach ($query->distinct()->execute()->fetchCol() as $nid) {
        $nid = (int) $nid;
        if ($nid > 0) {
          $nids[] = $nid;
        }
      }
    }

    if ($this->database->schema()->tableExists('spotdeals_vote')) {
      $query = $this->database->select('spotdeals_vote', 'v');
      $query->addField('v', 'deal_nid');
      $query->condition('v.changed', $weekStart, '>=');
      $query->condition('v.deal_nid', 0, '>');
      $query->range(0, 250);
      foreach ($query->distinct()->execute()->fetchCol() as $nid) {
        $nid = (int) $nid;
        if ($nid > 0) {
          $nids[] = $nid;
        }
      }
    }

    return array_values(array_unique($nids));
  }

  /**
   * Loads deals that currently have at least one activity badge.
   *
   * @return int[]
   *   Deal node IDs.
   */
  private function loadCurrentlyTaggedDealNids(): array {
    if (!$this->database->schema()->tableExists('node__' . self::FIELD_NAME)) {
      return [];
    }

    $query = $this->database->select('node__' . self::FIELD_NAME, 'f');
    $query->addField('f', 'entity_id');
    $query->condition('f.deleted', 0);
    $query->range(0, 500);

    return array_values(array_unique(array_map('intval', $query->execute()->fetchCol())));
  }

  /**
   * Replaces stored badge target IDs without changing node changed timestamps.
   *
   * @param int[] $targetIds
   *   Taxonomy term IDs in display priority order.
   *
   * @return bool
   *   TRUE if the stored field values changed.
   */
  private function replaceBadgeValues(NodeInterface $deal, array $targetIds): bool {
    $nid = (int) $deal->id();
    $vid = (int) $deal->getRevisionId();
    if ($nid <= 0 || $vid <= 0) {
      return FALSE;
    }

    $existing = $this->loadExistingBadgeTargetIds($nid);
    if ($existing === $targetIds) {
      return FALSE;
    }

    $transaction = $this->database->startTransaction();

    try {
      foreach (['node__' . self::FIELD_NAME, 'node_revision__' . self::FIELD_NAME] as $table) {
        $delete = $this->database->delete($table)
          ->condition('entity_id', $nid);
        if ($table === 'node_revision__' . self::FIELD_NAME) {
          $delete->condition('revision_id', $vid);
        }
        $delete->execute();

        foreach ($targetIds as $delta => $targetId) {
          $this->database->insert($table)
            ->fields([
              'bundle' => 'deal',
              'deleted' => 0,
              'entity_id' => $nid,
              'revision_id' => $vid,
              'langcode' => $deal->language()->getId(),
              'delta' => $delta,
              self::FIELD_NAME . '_target_id' => $targetId,
            ])
            ->execute();
        }
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }

    $this->cacheTagsInvalidator->invalidateTags(['node:' . $nid]);

    return TRUE;
  }

  /**
   * Loads existing activity badge term IDs for a deal.
   *
   * @return int[]
   *   Existing target IDs.
   */
  private function loadExistingBadgeTargetIds(int $nid): array {
    if (!$this->database->schema()->tableExists('node__' . self::FIELD_NAME)) {
      return [];
    }

    $query = $this->database->select('node__' . self::FIELD_NAME, 'f');
    $query->addField('f', self::FIELD_NAME . '_target_id');
    $query->condition('f.entity_id', $nid);
    $query->condition('f.deleted', 0);
    $query->orderBy('f.delta', 'ASC');

    return array_values(array_map('intval', $query->execute()->fetchCol()));
  }

  /**
   * Checks that the activity badge field storage tables exist.
   */
  private function fieldTablesExist(): bool {
    return $this->database->schema()->tableExists('node__' . self::FIELD_NAME)
      && $this->database->schema()->tableExists('node_revision__' . self::FIELD_NAME);
  }

}
