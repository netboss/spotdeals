<?php

declare(strict_types=1);

namespace Drupal\spotdeals_yelp\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\spotdeals_yelp\Service\YelpVenueSyncManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued Yelp venue sync jobs.
 *
 * @QueueWorker(
 *   id = "spotdeals_yelp_sync",
 *   title = @Translation("SpotDeals Yelp venue sync"),
 *   cron = {"time" = 30}
 * )
 */
final class YelpVenueSyncWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the queue worker.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly YelpVenueSyncManager $syncManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('spotdeals_yelp.venue_sync_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $nid = (int) ($data['nid'] ?? 0);
    if ($nid <= 0) {
      return;
    }

    $this->syncManager->refreshVenueById($nid);
  }

}
