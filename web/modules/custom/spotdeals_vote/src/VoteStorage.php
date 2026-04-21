<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote;

use Drupal\Core\Database\Connection;

/**
 * Handles raw vote row storage.
 */
final class VoteStorage {

  /**
   * Constructs vote storage.
   */
  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Loads one user's vote for one deal.
   *
   * @return array<string,mixed>|null
   *   The row or NULL.
   */
  public function loadUserDealVote(int $uid, int $dealNid): ?array {
    if ($uid <= 0 || $dealNid <= 0) {
      return NULL;
    }

    $row = $this->database->select('spotdeals_vote', 'v')
      ->fields('v')
      ->condition('uid', $uid)
      ->condition('deal_nid', $dealNid)
      ->execute()
      ->fetchAssoc();

    return is_array($row) ? $row : NULL;
  }

  /**
   * Inserts or updates a vote row.
   *
   * @param array<string,mixed> $fields
   *   Vote field values to store.
   */
  public function upsertVote(int $uid, int $dealNid, int $venueNid, array $fields, ?string $source = NULL): void {
    $now = \Drupal::time()->getRequestTime();
    $existing = $this->loadUserDealVote($uid, $dealNid);

    $record = [
      'uid' => $uid,
      'deal_nid' => $dealNid,
      'venue_nid' => $venueNid,
      'changed' => $now,
      'source' => $source !== NULL ? substr(trim($source), 0, 32) : NULL,
      'vote_schema_version' => 1,
    ] + $fields;

    if ($existing === NULL) {
      $record['created'] = $now;
      $this->database->insert('spotdeals_vote')
        ->fields($record)
        ->execute();
      return;
    }

    $this->database->update('spotdeals_vote')
      ->fields($record)
      ->condition('uid', $uid)
      ->condition('deal_nid', $dealNid)
      ->execute();
  }
}
