<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote_venue;

use Drupal\Core\Database\Connection;

/**
 * Handles raw venue vote row storage.
 */
final class VenueVoteStorage {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Loads one voter's vote for one venue.
   *
   * @return array<string,mixed>|null
   *   The row or NULL.
   */
  public function loadVoterVenueVote(string $voterKey, int $venueNid): ?array {
    if ($voterKey === '' || $venueNid <= 0) {
      return NULL;
    }

    $row = $this->database->select('spotdeals_vote_venue', 'v')
      ->fields('v')
      ->condition('voter_key', $voterKey)
      ->condition('venue_nid', $venueNid)
      ->execute()
      ->fetchAssoc();

    return is_array($row) ? $row : NULL;
  }

  /**
   * Inserts or updates a venue vote row.
   *
   * @param array<string,mixed> $fields
   *   Vote field values to store.
   */
  public function upsertVote(int $uid, string $voterKey, string $anonymousHash, int $venueNid, array $fields, ?string $source = NULL): void {
    $now = \Drupal::time()->getRequestTime();
    $existing = $this->loadVoterVenueVote($voterKey, $venueNid);

    $record = [
      'uid' => $uid,
      'voter_key' => $voterKey,
      'anonymous_voter_hash' => $anonymousHash,
      'venue_nid' => $venueNid,
      'changed' => $now,
      'source' => $source !== NULL ? substr(trim($source), 0, 32) : NULL,
      'vote_schema_version' => 2,
    ] + $fields;

    if ($existing === NULL) {
      $record['created'] = $now;
      $this->database->insert('spotdeals_vote_venue')->fields($record)->execute();
      return;
    }

    $this->database->update('spotdeals_vote_venue')
      ->fields($record)
      ->condition('voter_key', $voterKey)
      ->condition('venue_nid', $venueNid)
      ->execute();
  }

}
