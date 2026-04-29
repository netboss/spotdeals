<?php

namespace Drupal\spotdeals_import\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Drupal\migrate\Event\MigrateRowDeleteEvent;
use Drupal\migrate\MigrateSkipRowException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Preserves manually edited imported venue/deal nodes.
 */
final class PreserveEditedImportedNodesSubscriber implements EventSubscriberInterface {

  /**
   * Migration IDs protected by this subscriber.
   */
  private const PROTECTED_MIGRATIONS = [
    'spotdeals_venues' => 'venue',
    'spotdeals_deals' => 'deal',
  ];

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      MigrateEvents::PRE_ROW_SAVE => 'onPreRowSave',
      MigrateEvents::PRE_ROW_DELETE => 'onPreRowDelete',
    ];
  }

  /**
   * Skips import/update for previously imported nodes edited after creation.
   */
  public function onPreRowSave(MigratePreRowSaveEvent $event): void {
    $migration = $event->getMigration();
    $migration_id = $migration->id();

    if (!$this->isProtectedMigration($migration_id)) {
      return;
    }

    $row = $event->getRow();
    $destination_ids = $migration->getIdMap()->lookupDestinationIds($row->getSourceIdValues());

    if (empty($destination_ids)) {
      return;
    }

    foreach ($destination_ids as $destination_id_values) {
      $nid = $this->extractNodeId($destination_id_values);

      if (!$nid || !$this->isEditedProtectedNode($nid, $migration_id)) {
        continue;
      }

      $message = sprintf(
        'Skipped import update for manually edited %s node %s from migration %s because changed timestamp differs from created timestamp.',
        self::PROTECTED_MIGRATIONS[$migration_id],
        $nid,
        $migration_id
      );

      $this->logger->notice($message);
      throw new MigrateSkipRowException($message, FALSE);
    }
  }

  /**
   * Skips rollback deletion for imported nodes edited after creation.
   */
  public function onPreRowDelete(MigrateRowDeleteEvent $event): void {
    $migration = $event->getMigration();
    $migration_id = $migration->id();

    if (!$this->isProtectedMigration($migration_id)) {
      return;
    }

    $nid = $this->extractNodeId($event->getDestinationIdValues());

    if (!$nid || !$this->isEditedProtectedNode($nid, $migration_id)) {
      return;
    }

    $message = sprintf(
      'Skipped rollback deletion for manually edited %s node %s from migration %s because changed timestamp differs from created timestamp.',
      self::PROTECTED_MIGRATIONS[$migration_id],
      $nid,
      $migration_id
    );

    $this->logger->notice($message);
    throw new MigrateSkipRowException($message, FALSE);
  }

  /**
   * Checks whether this migration should be protected.
   */
  private function isProtectedMigration(string $migration_id): bool {
    return isset(self::PROTECTED_MIGRATIONS[$migration_id]);
  }

  /**
   * Extracts a node ID from destination ID values.
   */
  private function extractNodeId(array $destination_id_values): ?int {
    $nid = reset($destination_id_values);

    if (is_array($nid)) {
      $nid = reset($nid);
    }

    return is_numeric($nid) ? (int) $nid : NULL;
  }

  /**
   * Checks whether a destination node was manually edited after creation.
   */
  private function isEditedProtectedNode(int $nid, string $migration_id): bool {
    $node = $this->entityTypeManager
      ->getStorage('node')
      ->load($nid);

    if (!$node || $node->bundle() !== self::PROTECTED_MIGRATIONS[$migration_id]) {
      return FALSE;
    }

    return (int) $node->getChangedTime() !== (int) $node->getCreatedTime();
  }

}
