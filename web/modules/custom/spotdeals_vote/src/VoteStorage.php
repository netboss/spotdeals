<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote;

use Drupal\Core\Database\Connection;

/**
 * Handles raw vote row storage.
 */
final class VoteStorage {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Loads one voter's vote for one deal.
   *
   * @return array<string,mixed>|null
   *   The row or NULL.
   */
  public function loadVoterDealVote(string $voterKey, int $dealNid): ?array {
    if ($voterKey === '' || $dealNid <= 0) {
      return NULL;
    }

    $row = $this->database->select('spotdeals_vote', 'v')
      ->fields('v')
      ->condition('voter_key', $voterKey)
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
  public function upsertVote(int $uid, string $voterKey, string $anonymousHash, int $dealNid, int $venueNid, array $fields, ?string $source = NULL): void {
    $now = \Drupal::time()->getRequestTime();
    $existing = $this->loadVoterDealVote($voterKey, $dealNid);

    $record = [
      'uid' => $uid,
      'voter_key' => $voterKey,
      'anonymous_voter_hash' => $anonymousHash,
      'deal_nid' => $dealNid,
      'venue_nid' => $venueNid,
      'changed' => $now,
      'source' => $source !== NULL ? substr(trim($source), 0, 32) : NULL,
      'vote_schema_version' => 2,
    ] + $fields;

    if ($existing === NULL) {
      $record['created'] = $now;
      $this->database->insert('spotdeals_vote')->fields($record)->execute();
      return;
    }

    $this->database->update('spotdeals_vote')
      ->fields($record)
      ->condition('voter_key', $voterKey)
      ->condition('deal_nid', $dealNid)
      ->execute();
  }

}
