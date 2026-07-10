<?php

declare(strict_types=1);

use Drupal\Core\Database\Connection;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\node\NodeInterface;

/**
 * SpotDeals append-only CSV migration runner.
 *
 * Reads the canonical venues.csv/deals.csv files, creates temporary append-only
 * CSV files with only rows missing from the migrate map tables, temporarily
 * points the existing migrations to those append files, imports them using the
 * real migration mappings, then restores the original migration config.
 */

const SPOTDEALS_VENUES_MIGRATION = 'spotdeals_venues';
const SPOTDEALS_DEALS_MIGRATION = 'spotdeals_deals';

$root = dirname(__DIR__);
$module_data_dir = spotdeals_append_find_data_dir($root);
$append_dir = $module_data_dir . '/.append';

if (!is_dir($append_dir) && !mkdir($append_dir, 0775, TRUE) && !is_dir($append_dir)) {
  throw new RuntimeException("Unable to create append directory: {$append_dir}");
}

$venues_csv = $module_data_dir . '/venues.csv';
$deals_csv = $module_data_dir . '/deals.csv';

$venues_append_csv = $append_dir . '/venues.append.csv';
$deals_append_csv = $append_dir . '/deals.append.csv';

print "SpotDeals append-only CSV import\n";
print "================================\n";
print "Data directory: {$module_data_dir}\n\n";

$venues_result = spotdeals_append_prepare_venues_csv($venues_csv, $venues_append_csv);
$deals_result = spotdeals_append_prepare_deals_csv($deals_csv, $deals_append_csv);

print "Prepared append files\n";
print "---------------------\n";
print "Venues total rows: {$venues_result['total']}\n";
print "Venues append rows: {$venues_result['append']}\n";
print "Venues skipped existing: {$venues_result['skipped']}\n";
print "Deals total rows: {$deals_result['total']}\n";
print "Deals append rows: {$deals_result['append']}\n";
print "Deals skipped existing: {$deals_result['skipped']}\n\n";

if ($venues_result['append'] === 0 && $deals_result['append'] === 0) {
  print "No new venue/deal rows found. Nothing to import.\n\n";
  spotdeals_append_reindex_and_clear_cache();
  print "\nDone.\n";
  return;
}

$original_paths = [];

try {
  if ($venues_result['append'] > 0) {
    $original_paths[SPOTDEALS_VENUES_MIGRATION] = spotdeals_append_set_migration_source_path(
      SPOTDEALS_VENUES_MIGRATION,
      'modules/custom/spotdeals_import/data/.append/venues.append.csv'
    );

    spotdeals_append_run_migration(SPOTDEALS_VENUES_MIGRATION);
  }

  if ($deals_result['append'] > 0) {
    $original_paths[SPOTDEALS_DEALS_MIGRATION] = spotdeals_append_set_migration_source_path(
      SPOTDEALS_DEALS_MIGRATION,
      'modules/custom/spotdeals_import/data/.append/deals.append.csv'
    );

    spotdeals_append_run_migration(SPOTDEALS_DEALS_MIGRATION);
  }
}
finally {
  foreach ($original_paths as $migration_id => $original_path) {
    spotdeals_append_set_migration_source_path($migration_id, $original_path);
  }

  spotdeals_append_clear_migration_plugin_cache();
}

spotdeals_append_reindex_and_clear_cache();

print "\nDone.\n";

/**
 * Finds the SpotDeals import module data directory.
 */
function spotdeals_append_find_data_dir(string $root): string {
  $candidates = [
    $root . '/web/modules/custom/spotdeals_import/data',
    $root . '/modules/custom/spotdeals_import/data',
  ];

  foreach ($candidates as $candidate) {
    if (is_dir($candidate) && is_readable($candidate . '/venues.csv') && is_readable($candidate . '/deals.csv')) {
      return $candidate;
    }
  }

  throw new RuntimeException('Unable to find spotdeals_import/data with venues.csv and deals.csv.');
}

/**
 * Prepares the append-only venues CSV.
 *
 * @return array{total:int,append:int,skipped:int}
 */
function spotdeals_append_prepare_venues_csv(string $source_csv, string $append_csv): array {
  return spotdeals_append_filter_csv(
    $source_csv,
    $append_csv,
    static function (array $row): bool {
      $title = trim((string) ($row['title'] ?? ''));

      if ($title === '') {
        return FALSE;
      }

      if (spotdeals_append_source_exists_in_map(SPOTDEALS_VENUES_MIGRATION, [$title])) {
        return FALSE;
      }

      if (spotdeals_append_existing_venue_node_exists($row)) {
        return FALSE;
      }

      return TRUE;
    }
  );
}

/**
 * Prepares the append-only deals CSV.
 *
 * @return array{total:int,append:int,skipped:int}
 */
function spotdeals_append_prepare_deals_csv(string $source_csv, string $append_csv): array {
  return spotdeals_append_filter_csv(
    $source_csv,
    $append_csv,
    static function (array $row): bool {
      $title = trim((string) ($row['title'] ?? ''));
      $venue = trim((string) ($row['field_venue'] ?? ''));
      $day = trim((string) ($row['field_day_of_week'] ?? ''));
      $start = trim((string) ($row['field_start_time'] ?? ''));

      if ($title === '' || $venue === '') {
        return FALSE;
      }

      if (spotdeals_append_source_exists_in_map(SPOTDEALS_DEALS_MIGRATION, [$title, $venue, $day, $start])) {
        return FALSE;
      }

      if (spotdeals_append_existing_deal_node_exists($row)) {
        return FALSE;
      }

      return TRUE;
    }
  );
}

/**
 * Filters a full CSV into an append-only CSV using the supplied callback.
 *
 * @return array{total:int,append:int,skipped:int}
 */
function spotdeals_append_filter_csv(string $source_csv, string $append_csv, callable $should_append): array {
  if (!is_readable($source_csv)) {
    throw new RuntimeException("CSV is not readable: {$source_csv}");
  }

  $input = fopen($source_csv, 'rb');
  if (!$input) {
    throw new RuntimeException("Unable to open source CSV: {$source_csv}");
  }

  $headers = fgetcsv($input);
  if (!$headers) {
    fclose($input);
    throw new RuntimeException("CSV has no header row: {$source_csv}");
  }

  $output = fopen($append_csv, 'wb');
  if (!$output) {
    fclose($input);
    throw new RuntimeException("Unable to write append CSV: {$append_csv}");
  }

  fputcsv($output, $headers);

  $total = 0;
  $append = 0;
  $skipped = 0;

  while (($data = fgetcsv($input)) !== FALSE) {
    if (spotdeals_append_csv_row_is_empty($data)) {
      continue;
    }

    $total++;
    $row = [];

    foreach ($headers as $index => $header) {
      $row[(string) $header] = $data[$index] ?? '';
    }

    if ($should_append($row)) {
      fputcsv($output, spotdeals_append_row_to_ordered_values($headers, $row));
      $append++;
    }
    else {
      $skipped++;
    }
  }

  fclose($input);
  fclose($output);

  return [
    'total' => $total,
    'append' => $append,
    'skipped' => $skipped,
  ];
}

/**
 * Checks whether a CSV row is empty.
 */
function spotdeals_append_csv_row_is_empty(array $data): bool {
  foreach ($data as $value) {
    if (trim((string) $value) !== '') {
      return FALSE;
    }
  }

  return TRUE;
}

/**
 * Converts an associative row back into ordered CSV values.
 */
function spotdeals_append_row_to_ordered_values(array $headers, array $row): array {
  $values = [];

  foreach ($headers as $header) {
    $values[] = $row[(string) $header] ?? '';
  }

  return $values;
}

/**
 * Checks whether a source row already exists in the migration map.
 */
function spotdeals_append_source_exists_in_map(string $migration_id, array $source_ids): bool {
  $database = spotdeals_append_database();
  $table = 'migrate_map_' . $migration_id;

  if (!$database->schema()->tableExists($table)) {
    return FALSE;
  }

  $query = $database->select($table, 'm');
  $query->fields('m', ['destid1']);

  foreach (array_values($source_ids) as $index => $source_id) {
    $query->condition('sourceid' . ($index + 1), (string) $source_id);
  }

  $query->range(0, 1);
  $result = $query->execute()->fetchAssoc();

  if (!$result) {
    return FALSE;
  }

  $destid = $result['destid1'] ?? NULL;

  if (!$destid || !is_numeric($destid)) {
    return FALSE;
  }

  $node = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->load((int) $destid);

  return $node instanceof NodeInterface;
}

/**
 * Checks whether a venue node already exists by exact title or exact address.
 */
function spotdeals_append_existing_venue_node_exists(array $row): bool {
  $title = trim((string) ($row['title'] ?? ''));

  if ($title !== '') {
    $ids = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->condition('title', $title)
      ->range(0, 1)
      ->execute();

    if (!empty($ids)) {
      return TRUE;
    }
  }

  $address = trim((string) ($row['field_address_address_line1'] ?? ''));
  $city = trim((string) ($row['field_address_locality'] ?? ''));
  $state = trim((string) ($row['field_address_administrative_area'] ?? ''));
  $zip = trim((string) ($row['field_address_postal_code'] ?? ''));

  if ($address === '' || $city === '' || $state === '') {
    return FALSE;
  }

  $query = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'venue')
    ->condition('field_address.address_line1', $address)
    ->condition('field_address.locality', $city)
    ->condition('field_address.administrative_area', $state)
    ->range(0, 1);

  if ($zip !== '') {
    $query->condition('field_address.postal_code', $zip);
  }

  $ids = $query->execute();

  return !empty($ids);
}

/**
 * Checks whether a deal node already exists by title, venue, and start time.
 */
function spotdeals_append_existing_deal_node_exists(array $row): bool {
  $title = trim((string) ($row['title'] ?? ''));
  $venue_title = trim((string) ($row['field_venue'] ?? ''));
  $start_time = trim((string) ($row['field_start_time'] ?? ''));

  if ($title === '') {
    return FALSE;
  }

  $query = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'deal')
    ->condition('title', $title)
    ->range(0, 20);

  $venue_nid = spotdeals_append_find_venue_nid_by_title($venue_title);
  if ($venue_nid) {
    $query->condition('field_venue.target_id', $venue_nid);
  }

  if ($start_time !== '') {
    $query->condition('field_start_time', $start_time);
  }

  $ids = $query->execute();

  return !empty($ids);
}

/**
 * Finds a venue node ID by exact title.
 */
function spotdeals_append_find_venue_nid_by_title(string $title): ?int {
  if ($title === '') {
    return NULL;
  }

  $ids = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'venue')
    ->condition('title', $title)
    ->sort('nid', 'ASC')
    ->range(0, 1)
    ->execute();

  if (empty($ids)) {
    return NULL;
  }

  return (int) reset($ids);
}

/**
 * Temporarily changes a migration source path.
 */
function spotdeals_append_set_migration_source_path(string $migration_id, string $path): string {
  $config_name = 'migrate_plus.migration.' . $migration_id;
  $config = \Drupal::configFactory()->getEditable($config_name);
  $source = $config->get('source') ?? [];

  if (!is_array($source)) {
    throw new RuntimeException("Migration {$migration_id} has invalid source config.");
  }

  $original_path = (string) ($source['path'] ?? '');

  if ($original_path === '') {
    throw new RuntimeException("Migration {$migration_id} does not have a source.path value.");
  }

  $source['path'] = $path;
  $config->set('source', $source);
  $config->save();

  spotdeals_append_clear_migration_plugin_cache();

  print "Set {$migration_id} source path to {$path}\n";

  return $original_path;
}

/**
 * Runs a migration import through Drupal Migrate using the active config.
 */
function spotdeals_append_run_migration(string $migration_id): void {
  $migration = \Drupal::service('plugin.manager.migration')->createInstance($migration_id);

  if (!$migration instanceof MigrationInterface) {
    throw new RuntimeException("Unable to load migration: {$migration_id}");
  }

  if ($migration->getStatus() !== MigrationInterface::STATUS_IDLE) {
    print "Resetting {$migration_id} status to Idle.\n";
    $migration->setStatus(MigrationInterface::STATUS_IDLE);
  }

  print "\nImporting {$migration_id} append rows...\n";

  $executable = new MigrateExecutable($migration, new MigrateMessage());
  $result = $executable->import();

  if ($result !== MigrationInterface::RESULT_COMPLETED) {
    print "Migration {$migration_id} finished with result code {$result}.\n";
  }
  else {
    print "Migration {$migration_id} completed.\n";
  }
}

/**
 * Clears cached migration plugin definitions.
 */
function spotdeals_append_clear_migration_plugin_cache(): void {
  $manager = \Drupal::service('plugin.manager.migration');

  if (method_exists($manager, 'clearCachedDefinitions')) {
    $manager->clearCachedDefinitions();
  }
}

/**
 * Reindexes Search API and rebuilds Drupal caches.
 */
function spotdeals_append_reindex_and_clear_cache(): void {
  print "\nReindexing deals_solr...\n";

  try {
    $index = \Drupal::entityTypeManager()
      ->getStorage('search_api_index')
      ->load('deals_solr');

    if ($index && method_exists($index, 'indexItems')) {
      $indexed = $index->indexItems();
      $indexed_count = is_array($indexed) ? count($indexed) : 0;
      print "Search API index triggered. Items processed now: {$indexed_count}\n";
    }
    else {
      print "Search API index deals_solr not found; skipping.\n";
    }
  }
  catch (Throwable $e) {
    print "Search API reindex skipped: {$e->getMessage()}\n";
  }

  print "Rebuilding cache...\n";
  drupal_flush_all_caches();
  print "Cache rebuilt.\n";
}

/**
 * Gets the database connection.
 */
function spotdeals_append_database(): Connection {
  return \Drupal::database();
}
