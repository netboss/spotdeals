<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Computes conservative freshness scores for deal ranking.
 *
 * This intentionally stays small and secondary. Text relevance and distance
 * should remain the primary ranking signals; freshness only helps comparable
 * deals rise above stale or inactive alternatives.
 */
final class DealFreshnessScorer {

  /**
   * Deal activity table name.
   */
  private const ACTIVITY_TABLE = 'spotdeals_search_smart_location_activity';

  /**
   * Vote table name.
   */
  private const VOTE_TABLE = 'spotdeals_vote';

  /**
   * Vote aggregate table name.
   */
  private const VOTE_AGGREGATE_TABLE = 'spotdeals_vote_aggregate';

  /**
   * Venue vote aggregate table name.
   */
  private const VENUE_VOTE_AGGREGATE_TABLE = 'spotdeals_vote_venue_aggregate';

  /**
   * Maximum absolute freshness boost.
   */
  private const MAX_BOOST = 35;

  /**
   * Maximum stale penalty.
   */
  private const MAX_PENALTY = -18;

  /**
   * Constructs the freshness scorer.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}



  /**
   * Loads lightweight venue vote quality rows.
   *
   * @param array<int,int|string> $venueNids
   *   Venue node IDs.
   *
   * @return array<int,array{worth_it_percent:float,total_voters:int}>
   *   Venue vote quality keyed by venue node ID.
   */
  public function scoreVenueNids(array $venueNids): array {
    $venueNids = array_values(array_unique(array_filter(
      array_map('intval', $venueNids),
      static fn (int $nid): bool => $nid > 0
    )));

    if ($venueNids === []) {
      return [];
    }

    $rows = [];
    foreach ($venueNids as $venueNid) {
      $rows[$venueNid] = [
        'worth_it_percent' => 0.0,
        'total_voters' => 0,
      ];
    }

    if (!$this->database->schema()->tableExists(self::VENUE_VOTE_AGGREGATE_TABLE)) {
      return $rows;
    }

    $query = $this->database->select(self::VENUE_VOTE_AGGREGATE_TABLE, 'a');
    $query->fields('a', ['venue_nid', 'worth_it_percent', 'total_voters']);
    $query->condition('a.venue_nid', $venueNids, 'IN');

    foreach ($query->execute() as $row) {
      $venueNid = (int) $row->venue_nid;
      if (!isset($rows[$venueNid])) {
        continue;
      }

      $rows[$venueNid]['worth_it_percent'] = (float) $row->worth_it_percent;
      $rows[$venueNid]['total_voters'] = (int) $row->total_voters;
    }

    return $rows;
  }

  /**
   * Scores deal IDs by recent validation and engagement signals.
   *
   * @param array<int,int|string> $dealNids
   *   Deal node IDs.
   *
   * @return array<int,array{score:int,last_checked:int,last_activity:int,worth_it_percent:float,total_voters:int,recent_views:int,recent_clicks:int}>
   *   Freshness rows keyed by deal node ID.
   */
  public function scoreDealNids(array $dealNids): array {
    $dealNids = array_values(array_unique(array_filter(
      array_map('intval', $dealNids),
      static fn (int $nid): bool => $nid > 0
    )));

    if ($dealNids === []) {
      return [];
    }

    $now = $this->time->getRequestTime();
    $rows = [];

    foreach ($dealNids as $dealNid) {
      $rows[$dealNid] = [
        'score' => 0,
        'last_checked' => 0,
        'last_activity' => 0,
        'worth_it_percent' => 0.0,
        'total_voters' => 0,
        'recent_views' => 0,
        'recent_clicks' => 0,
      ];
    }

    $this->addVoteSignals($rows, $dealNids);
    $this->addAggregateSignals($rows, $dealNids);
    $this->addActivitySignals($rows, $dealNids, $now);
    $this->finalizeScores($rows, $now);

    return $rows;
  }

  /**
   * Adds latest positive Worth it vote timestamps.
   *
   * Negative Worth it votes are not freshness validation. They are handled
   * through aggregate quality signals instead.
   *
   * @param array<int,array<string,int|float>> $rows
   *   Freshness rows keyed by deal node ID.
   * @param array<int,int> $dealNids
   *   Deal node IDs.
   */
  private function addVoteSignals(array &$rows, array $dealNids): void {
    if (!$this->database->schema()->tableExists(self::VOTE_TABLE)) {
      return;
    }

    $query = $this->database->select(self::VOTE_TABLE, 'v');
    $query->addField('v', 'deal_nid');
    $query->addExpression('MAX(v.changed)', 'last_checked');
    $query->condition('v.deal_nid', $dealNids, 'IN');
    $query->condition('v.worth_it', 1);
    $query->groupBy('v.deal_nid');

    foreach ($query->execute() as $row) {
      $dealNid = (int) $row->deal_nid;
      if (!isset($rows[$dealNid])) {
        continue;
      }

      $timestamp = max(0, (int) $row->last_checked);
      $rows[$dealNid]['last_checked'] = $timestamp;
      $rows[$dealNid]['last_activity'] = max((int) $rows[$dealNid]['last_activity'], $timestamp);
    }
  }

  /**
   * Adds aggregate vote quality signals.
   *
   * @param array<int,array<string,int|float>> $rows
   *   Freshness rows keyed by deal node ID.
   * @param array<int,int> $dealNids
   *   Deal node IDs.
   */
  private function addAggregateSignals(array &$rows, array $dealNids): void {
    if (!$this->database->schema()->tableExists(self::VOTE_AGGREGATE_TABLE)) {
      return;
    }

    $query = $this->database->select(self::VOTE_AGGREGATE_TABLE, 'a');
    $query->fields('a', ['deal_nid', 'worth_it_percent', 'total_voters']);
    $query->condition('a.deal_nid', $dealNids, 'IN');

    foreach ($query->execute() as $row) {
      $dealNid = (int) $row->deal_nid;
      if (!isset($rows[$dealNid])) {
        continue;
      }

      $rows[$dealNid]['worth_it_percent'] = (float) $row->worth_it_percent;
      $rows[$dealNid]['total_voters'] = (int) $row->total_voters;
    }
  }

  /**
   * Adds recent page/click activity signals.
   *
   * @param array<int,array<string,int|float>> $rows
   *   Freshness rows keyed by deal node ID.
   * @param array<int,int> $dealNids
   *   Deal node IDs.
   */
  private function addActivitySignals(array &$rows, array $dealNids, int $now): void {
    if (!$this->database->schema()->tableExists(self::ACTIVITY_TABLE)) {
      return;
    }

    $since = $now - (30 * 86400);

    $query = $this->database->select(self::ACTIVITY_TABLE, 'a');
    $query->fields('a', ['deal_nid', 'action', 'created']);
    $query->condition('a.deal_nid', $dealNids, 'IN');
    $query->condition('a.created', $since, '>=');
    $query->range(0, 5000);

    foreach ($query->execute() as $row) {
      $dealNid = (int) $row->deal_nid;
      if (!isset($rows[$dealNid])) {
        continue;
      }

      $created = max(0, (int) $row->created);
      $rows[$dealNid]['last_activity'] = max((int) $rows[$dealNid]['last_activity'], $created);

      if ((string) $row->action === 'click') {
        $rows[$dealNid]['recent_clicks'] = (int) $rows[$dealNid]['recent_clicks'] + 1;
      }
      else {
        $rows[$dealNid]['recent_views'] = (int) $rows[$dealNid]['recent_views'] + 1;
      }
    }
  }

  /**
   * Calculates final conservative score values.
   *
   * @param array<int,array<string,int|float>> $rows
   *   Freshness rows keyed by deal node ID.
   */
  private function finalizeScores(array &$rows, int $now): void {
    foreach ($rows as $dealNid => $row) {
      $lastChecked = (int) ($row['last_checked'] ?? 0);
      $lastActivity = (int) ($row['last_activity'] ?? 0);
      $worthItPercent = (float) ($row['worth_it_percent'] ?? 0.0);
      $totalVoters = (int) ($row['total_voters'] ?? 0);
      $recentViews = (int) ($row['recent_views'] ?? 0);
      $recentClicks = (int) ($row['recent_clicks'] ?? 0);

      $score = 0;

      if ($lastChecked > 0) {
        $checkedAgeDays = max(0.0, ($now - $lastChecked) / 86400);
        if ($checkedAgeDays <= 7) {
          $score += 22;
        }
        elseif ($checkedAgeDays <= 30) {
          $score += 14;
        }
        elseif ($checkedAgeDays <= 90) {
          $score += 7;
        }
        elseif ($checkedAgeDays > 180) {
          $score -= 5;
        }
      }

      if ($recentViews > 0 || $recentClicks > 0) {
        $engagement = log(1 + $recentViews + ($recentClicks * 4));
        $engagementBoost = (int) min(10, round($engagement * 3));

        // Engagement can help unrated or positively rated deals, but it should
        // not rescue a deal that users have explicitly marked as not worth it.
        if ($totalVoters === 0 || $worthItPercent >= 50.0) {
          $score += $engagementBoost;
        }
      }

      if ($totalVoters >= 2 && $worthItPercent >= 80.0) {
        $score += 6;
      }
      elseif ($totalVoters >= 2 && $worthItPercent >= 65.0) {
        $score += 3;
      }
      elseif ($totalVoters >= 1 && $worthItPercent <= 0.0) {
        $score -= 14;
      }
      elseif ($totalVoters >= 2 && $worthItPercent < 40.0) {
        $score -= 8;
      }

      if ($lastChecked <= 0 && $lastActivity <= 0) {
        $score -= 8;
      }
      elseif ($lastActivity > 0 && (($now - $lastActivity) / 86400) > 120) {
        $score -= 4;
      }

      $rows[$dealNid]['score'] = max(self::MAX_PENALTY, min(self::MAX_BOOST, $score));
    }
  }

}
