<?php

namespace Drupal\spotdeals_import\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Drupal\migrate\Event\MigrateRollbackEvent;
use Drupal\migrate\Event\MigrateRowDeleteEvent;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
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
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      MigrateEvents::PRE_ROLLBACK => 'onPreRollback',
      MigrateEvents::PRE_ROW_SAVE => 'onPreRowSave',
      MigrateEvents::PRE_ROW_DELETE => 'onPreRowDelete',
    ];
  }

  /**
   * Marks manually edited imported nodes as preserve before rollback begins.
   */
  public function onPreRollback(MigrateRollbackEvent $event): void {
    $migration = $event->getMigration();
    $migration_id = $migration->id();

    if (!$this->isProtectedMigration($migration_id)) {
      return;
    }

    $map_table = $this->getMapTableName($migration_id);

    if (!$this->database->schema()->tableExists($map_table)) {
      return;
    }

    $query = $this->database->select($map_table, 'map');
    $query->fields('map');
    $query->condition('map.destid1', NULL, 'IS NOT NULL');

    $rows = $query->execute();

    $preserved_count = 0;

    foreach ($rows as $row) {
      $nid = isset($row->destid1) && is_numeric($row->destid1) ? (int) $row->destid1 : NULL;

      if (!$nid || !$this->isEditedProtectedNode($nid, $migration_id)) {
        continue;
      }

      $this->database->update($map_table)
        ->fields([
          'rollback_action' => MigrateIdMapInterface::ROLLBACK_PRESERVE,
        ])
        ->condition('source_ids_hash', $row->source_ids_hash)
        ->execute();

      $preserved_count++;
    }

    if ($preserved_count > 0) {
      $this->logger->notice(
        'Marked @count manually edited @type migration rows as rollback preserve before rolling back @migration.',
        [
          '@count' => $preserved_count,
          '@type' => self::PROTECTED_MIGRATIONS[$migration_id],
          '@migration' => $migration_id,
        ]
      );
    }
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
   * Logs protected rollback rows without throwing.
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

    $this->logger->warning(
      'Edited @type node @nid reached PRE_ROW_DELETE during @migration rollback. The row should have been marked rollback preserve before deletion.',
      [
        '@type' => self::PROTECTED_MIGRATIONS[$migration_id],
        '@nid' => $nid,
        '@migration' => $migration_id,
      ]
    );
  }

  /**
   * Checks whether this migration should be protected.
   */
  private function isProtectedMigration(string $migration_id): bool {
    return isset(self::PROTECTED_MIGRATIONS[$migration_id]);
  }

  /**
   * Gets the migrate map table name for a migration ID.
   */
  private function getMapTableName(string $migration_id): string {
    return 'migrate_map_' . $migration_id;
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
