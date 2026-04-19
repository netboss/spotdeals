<?php

declare(strict_types=1);

namespace Drupal\spotdeals_yelp\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\spotdeals_yelp\Service\YelpVenueSyncManager;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;

/**
 * Drush commands for SpotDeals Yelp.
 */
final class SpotdealsYelpCommands extends DrushCommands {

  /**
   * Constructs the commands service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly YelpVenueSyncManager $syncManager,
    private readonly LoggerInterface $spotdealsYelpLogger,
  ) {
    parent::__construct();
  }

  /**
   * Attempts to match venue nodes to Yelp businesses.
   *
   * @command spotdeals-yelp:match-venues
   * @option limit Maximum number of venues to process.
   * @option only-unmatched Restrict matching to venues without a Yelp business id.
   * @usage drush spotdeals-yelp:match-venues --limit=50
   */
  public function matchVenues(array $options = ['limit' => 50, 'only-unmatched' => TRUE]): void {
    $limit = max(1, (int) $options['limit']);
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'venue')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->sort('nid', 'ASC')
      ->range(0, $limit);

    if (!empty($options['only-unmatched'])) {
      $orGroup = $query->orConditionGroup()
        ->condition('field_yelp_business_id', '', '=')
        ->notExists('field_yelp_business_id');
      $query->condition($orGroup);
    }

    $nids = $query->execute();
    if (empty($nids)) {
      $this->output()->writeln('No venue nodes matched the requested criteria.');
      return;
    }

    $venues = $storage->loadMultiple($nids);
    $matched = 0;
    $needsReview = 0;
    $unmatched = 0;

    foreach ($venues as $venue) {
      if (!$venue instanceof NodeInterface) {
        continue;
      }

      try {
        $result = $this->syncManager->syncVenue($venue);
        $status = (string) ($result['status'] ?? 'unmatched');
        if ($status === 'matched') {
          $matched++;
        }
        elseif ($status === 'needs_review') {
          $needsReview++;
        }
        else {
          $unmatched++;
        }
      }
      catch (\Throwable $exception) {
        $unmatched++;
        $this->spotdealsYelpLogger->warning('Yelp venue match failed for venue @nid: @message', [
          '@nid' => $venue->id(),
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    $this->output()->writeln(sprintf('Processed %d venues. matched=%d needs_review=%d unmatched=%d', count($venues), $matched, $needsReview, $unmatched));
  }

  /**
   * Syncs Yelp details for venues.
   *
   * @command spotdeals-yelp:sync
   * @option limit Maximum number of venues to sync.
   * @option nid Sync a single venue node id.
   * @usage drush spotdeals-yelp:sync --limit=25
   */
  public function sync(array $options = ['limit' => 25, 'nid' => 0]): void {
    $nid = (int) $options['nid'];
    if ($nid > 0) {
      $this->syncManager->refreshVenueById($nid);
      $this->output()->writeln(sprintf('Synced venue %d.', $nid));
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'venue')
      ->condition('field_yelp_business_id', '', '<>')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->sort('field_yelp_last_synced.value', 'ASC')
      ->range(0, max(1, (int) $options['limit']));

    $nids = $query->execute();
    $count = 0;
    foreach ($nids as $venueNid) {
      $this->syncManager->refreshVenueById((int) $venueNid);
      $count++;
    }

    $this->output()->writeln(sprintf('Synced %d venue(s).', $count));
  }

  /**
   * Queues stale Yelp venue sync jobs.
   *
   * @command spotdeals-yelp:queue-stale
   * @option limit Maximum number of venues to queue.
   */
  public function queueStale(array $options = ['limit' => 50]): void {
    $queued = $this->syncManager->enqueueStaleVenues(max(1, (int) $options['limit']));
    $this->output()->writeln(sprintf('Queued %d stale venue(s).', $queued));
  }

  /**
   * Prints a short Yelp sync report.
   *
   * @command spotdeals-yelp:report
   */
  public function report(): void {
    $counts = $this->syncManager->getStatusCounts();
    foreach ($counts as $label => $value) {
      $this->output()->writeln(sprintf('%s: %d', $label, $value));
    }
  }

}
