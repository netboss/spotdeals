<?php

/**
 * @file
 * Protects Geoapify-backed migration rows during destructive rollbacks.
 *
 * Usage:
 *   SPOTDEALS_GEOAPIFY_PROTECTION_MODE=protect drush php:script scripts/spotdeals_protect_geoapify_migration_rows.php
 *   SPOTDEALS_GEOAPIFY_PROTECTION_MODE=restore drush php:script scripts/spotdeals_protect_geoapify_migration_rows.php
 *   SPOTDEALS_GEOAPIFY_PROTECTION_MODE=cleanup drush php:script scripts/spotdeals_protect_geoapify_migration_rows.php
 */

declare(strict_types=1);

use Drupal\Core\Database\Connection;

$argumentMode = '';

if (isset($extra) && is_array($extra) && isset($extra[0])) {
  $argumentMode = trim((string) $extra[0]);
}

$mode = strtolower(
  $argumentMode !== ''
    ? $argumentMode
    : trim((string) getenv('SPOTDEALS_GEOAPIFY_PROTECTION_MODE'))
);

if (!in_array($mode, ['protect', 'restore', 'cleanup'], TRUE)) {
  throw new RuntimeException(
    'Pass protect, restore, or cleanup after the Drush -- separator.'
  );
}

/** @var \Drupal\Core\Database\Connection $database */
$database = \Drupal::database();

$tables = [
  'venues' => [
    'map' => 'migrate_map_spotdeals_venues',
    'backup' => 'spotdeals_protected_map_spotdeals_venues',
  ],
  'deals' => [
    'map' => 'migrate_map_spotdeals_deals',
    'backup' => 'spotdeals_protected_map_spotdeals_deals',
  ],
];

foreach ($tables as $definition) {
  if (!$database->schema()->tableExists($definition['map'])) {
    throw new RuntimeException(sprintf(
      'Required migration map table does not exist: %s',
      $definition['map'],
    ));
  }
}

switch ($mode) {
  case 'protect':
    protectGeoapifyRows($database, $tables);
    break;

  case 'restore':
    restoreGeoapifyRows($database, $tables);
    break;

  case 'cleanup':
    cleanupProtectionTables($database, $tables);
    break;
}

/**
 * Snapshots and removes protected rows from the active migration maps.
 */
function protectGeoapifyRows(Connection $database, array $tables): void {
  foreach ($tables as $definition) {
    if ($database->schema()->tableExists($definition['backup'])) {
      throw new RuntimeException(sprintf(
        'Protection table already exists: %s. Restore or clean it up before starting another migration.',
        $definition['backup'],
      ));
    }

    $database->query(sprintf(
      'CREATE TABLE {%s} LIKE {%s}',
      $definition['backup'],
      $definition['map'],
    ));
  }

  try {
    $database->query(sprintf(
      <<<'SQL'
INSERT INTO {%s}
SELECT DISTINCT map.*
FROM {%s} map
INNER JOIN {node__field_external_source} source
  ON source.entity_id = map.destid1
  AND source.deleted = 0
WHERE LOWER(TRIM(source.field_external_source_value)) = 'geoapify'
SQL,
      $tables['venues']['backup'],
      $tables['venues']['map'],
    ));

    $database->query(sprintf(
      <<<'SQL'
INSERT INTO {%s}
SELECT DISTINCT map.*
FROM {%s} map
INNER JOIN {node__field_venue} deal_venue
  ON deal_venue.entity_id = map.destid1
  AND deal_venue.deleted = 0
INNER JOIN {node__field_external_source} venue_source
  ON venue_source.entity_id = deal_venue.field_venue_target_id
  AND venue_source.deleted = 0
WHERE LOWER(TRIM(venue_source.field_external_source_value)) = 'geoapify'
SQL,
      $tables['deals']['backup'],
      $tables['deals']['map'],
    ));

    foreach ($tables as $label => $definition) {
      $protectedCount = countRows($database, $definition['backup']);

      if ($protectedCount > 0) {
        $database->query(sprintf(
          <<<'SQL'
DELETE active_map
FROM {%s} active_map
INNER JOIN {%s} protected_map
  ON protected_map.source_ids_hash = active_map.source_ids_hash
SQL,
          $definition['map'],
          $definition['backup'],
        ));
      }

      $remainingProtectedRows = countProtectedRowsStillInMap(
        $database,
        $definition['map'],
        $definition['backup'],
      );

      if ($remainingProtectedRows !== 0) {
        throw new RuntimeException(sprintf(
          'Failed to remove %d protected %s map row(s) before rollback.',
          $remainingProtectedRows,
          $label,
        ));
      }

      print sprintf(
        "Protected %d Geoapify-backed %s migration row(s).\n",
        $protectedCount,
        $label,
      );
    }
  }
  catch (Throwable $exception) {
    cleanupProtectionTables($database, $tables);
    throw $exception;
  }
}

/**
 * Restores protected rows to the active migration maps.
 */
function restoreGeoapifyRows(Connection $database, array $tables): void {
  foreach ($tables as $label => $definition) {
    if (!$database->schema()->tableExists($definition['backup'])) {
      throw new RuntimeException(sprintf(
        'Protection table does not exist: %s',
        $definition['backup'],
      ));
    }

    $missingDestinations = (int) $database->query(sprintf(
      <<<'SQL'
SELECT COUNT(*)
FROM {%s} protected_map
LEFT JOIN {node} destination
  ON destination.nid = protected_map.destid1
WHERE protected_map.destid1 IS NOT NULL
  AND destination.nid IS NULL
SQL,
      $definition['backup'],
    ))->fetchField();

    if ($missingDestinations > 0) {
      throw new RuntimeException(sprintf(
        '%d protected %s destination node(s) were deleted during rollback. The map rows were not restored.',
        $missingDestinations,
        $label,
      ));
    }

    $database->query(sprintf(
      <<<'SQL'
DELETE active_map
FROM {%s} active_map
INNER JOIN {%s} protected_map
  ON protected_map.source_ids_hash = active_map.source_ids_hash
SQL,
      $definition['map'],
      $definition['backup'],
    ));

    $database->query(sprintf(
      'INSERT INTO {%s} SELECT * FROM {%s}',
      $definition['map'],
      $definition['backup'],
    ));

    $expectedCount = countRows($database, $definition['backup']);
    $restoredCount = countProtectedRowsStillInMap(
      $database,
      $definition['map'],
      $definition['backup'],
    );

    if ($expectedCount !== $restoredCount) {
      throw new RuntimeException(sprintf(
        'Expected to restore %d protected %s map row(s), but verified %d.',
        $expectedCount,
        $label,
        $restoredCount,
      ));
    }

    print sprintf(
      "Restored %d protected Geoapify-backed %s migration row(s).\n",
      $restoredCount,
      $label,
    );
  }

  cleanupProtectionTables($database, $tables);
}

/**
 * Removes protection tables.
 */
function cleanupProtectionTables(Connection $database, array $tables): void {
  foreach ($tables as $definition) {
    if ($database->schema()->tableExists($definition['backup'])) {
      $database->schema()->dropTable($definition['backup']);
      print sprintf("Removed protection table %s.\n", $definition['backup']);
    }
  }
}

/**
 * Counts all rows in a table.
 */
function countRows(Connection $database, string $table): int {
  return (int) $database->select($table, 'protected_map')
    ->countQuery()
    ->execute()
    ->fetchField();
}

/**
 * Counts protected rows currently present in an active map.
 */
function countProtectedRowsStillInMap(
  Connection $database,
  string $mapTable,
  string $backupTable,
): int {
  return (int) $database->query(sprintf(
    <<<'SQL'
SELECT COUNT(*)
FROM {%s} active_map
INNER JOIN {%s} protected_map
  ON protected_map.source_ids_hash = active_map.source_ids_hash
SQL,
    $mapTable,
    $backupTable,
  ))->fetchField();
}
