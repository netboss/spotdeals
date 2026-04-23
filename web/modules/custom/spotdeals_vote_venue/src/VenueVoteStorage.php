<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote_venue;

use Drupal\Core\Database\Connection;

/**
 * Handles raw venue vote row storage.
 */
final class VenueVoteStorage {

  /**
   * Constructs venue vote storage.
   */
  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Loads one user's vote for one venue.
   *
   * @return array<string,mixed>|null
   *   The row or NULL.
   */
  public function loadUserVenueVote(int $uid, int $venueNid): ?array {
    if ($uid <= 0 || $venueNid <= 0) {
      return NULL;
    }

    $row = $this->database->select('spotdeals_vote_venue', 'v')
      ->fields('v')
      ->condition('uid', $uid)
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
  public function upsertVote(int $uid, int $venueNid, array $fields, ?string $source = NULL): void {
    $now = \Drupal::time()->getRequestTime();
    $existing = $this->loadUserVenueVote($uid, $venueNid);

    $record = [
      'uid' => $uid,
      'venue_nid' => $venueNid,
      'changed' => $now,
      'source' => $source !== NULL ? substr(trim($source), 0, 32) : NULL,
      'vote_schema_version' => 1,
    ] + $fields;

    if ($existing === NULL) {
      $record['created'] = $now;
      $this->database->insert('spotdeals_vote_venue')
        ->fields($record)
        ->execute();
      return;
    }

    $this->database->update('spotdeals_vote_venue')
      ->fields($record)
      ->condition('uid', $uid)
      ->condition('venue_nid', $venueNid)
      ->execute();
  }

}
