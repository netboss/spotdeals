<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote_venue;

use Drupal\Core\Database\Connection;

/**
 * Handles venue vote aggregate reads and writes.
 */
final class VenueVoteAggregateStorage {

  /**
   * Constructs aggregate storage.
   */
  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Rebuilds aggregate data for one venue.
   */
  public function rebuildVenueAggregate(int $venueNid): void {
    $rows = $this->database->select('spotdeals_vote_venue', 'v')
      ->fields('v', ['worth_it', 'would_go_again'])
      ->condition('venue_nid', $venueNid)
      ->execute()
      ->fetchAll();

    $worthItYes = 0;
    $worthItNo = 0;
    $wouldGoAgainYes = 0;
    $wouldGoAgainNo = 0;
    $totalVoters = 0;

    foreach ($rows as $row) {
      $hasVote = FALSE;

      if ($row->worth_it !== NULL) {
        $hasVote = TRUE;
        if ((int) $row->worth_it === 1) {
          $worthItYes++;
        }
        else {
          $worthItNo++;
        }
      }

      if ($row->would_go_again !== NULL) {
        $hasVote = TRUE;
        if ((int) $row->would_go_again === 1) {
          $wouldGoAgainYes++;
        }
        else {
          $wouldGoAgainNo++;
        }
      }

      if ($hasVote) {
        $totalVoters++;
      }
    }

    $worthItTotal = $worthItYes + $worthItNo;
    $wouldGoAgainTotal = $wouldGoAgainYes + $wouldGoAgainNo;

    $record = [
      'venue_nid' => $venueNid,
      'worth_it_yes' => $worthItYes,
      'worth_it_no' => $worthItNo,
      'would_go_again_yes' => $wouldGoAgainYes,
      'would_go_again_no' => $wouldGoAgainNo,
      'total_voters' => $totalVoters,
      'worth_it_percent' => $worthItTotal > 0 ? round(($worthItYes / $worthItTotal) * 100, 2) : 0,
      'would_go_again_percent' => $wouldGoAgainTotal > 0 ? round(($wouldGoAgainYes / $wouldGoAgainTotal) * 100, 2) : 0,
      'changed' => \Drupal::time()->getRequestTime(),
    ];

    $exists = (bool) $this->database->select('spotdeals_vote_venue_aggregate', 'a')
      ->fields('a', ['venue_nid'])
      ->condition('venue_nid', $venueNid)
      ->execute()
      ->fetchField();

    if ($exists) {
      $this->database->update('spotdeals_vote_venue_aggregate')
        ->fields($record)
        ->condition('venue_nid', $venueNid)
        ->execute();
      return;
    }

    $this->database->insert('spotdeals_vote_venue_aggregate')
      ->fields($record)
      ->execute();
  }

  /**
   * Loads aggregate data for one venue.
   *
   * @return array<string,mixed>
   *   Aggregate row or defaults.
   */
  public function loadVenueAggregate(int $venueNid): array {
    $row = $this->database->select('spotdeals_vote_venue_aggregate', 'a')
      ->fields('a')
      ->condition('venue_nid', $venueNid)
      ->execute()
      ->fetchAssoc();

    if (is_array($row)) {
      return $row;
    }

    return [
      'venue_nid' => $venueNid,
      'worth_it_yes' => 0,
      'worth_it_no' => 0,
      'would_go_again_yes' => 0,
      'would_go_again_no' => 0,
      'total_voters' => 0,
      'worth_it_percent' => 0,
      'would_go_again_percent' => 0,
      'changed' => 0,
    ];
  }

}
