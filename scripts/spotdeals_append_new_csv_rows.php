<?php

declare(strict_types=1);

use Drupal\Core\Database\Connection;
use Drupal\node\NodeInterface;

/**
 * SpotDeals append-only CSV preparation/restoration helper.
 *
 * This script deliberately does NOT execute migrations. It prepares append-only
 * CSV files, temporarily points the configured migrations at those files, and
 * records the original source paths. The calling shell script then runs normal
 * `drush migrate:import` commands in fresh PHP processes and finally calls this
 * helper again with --action=restore.
 */

$arguments = isset($extra) && is_array($extra) ? $extra : ($argv ?? []);
$dataset = spotdeals_append_parse_dataset($arguments);
$action = spotdeals_append_parse_action($arguments);

if ($dataset === 'non-food') {
  $venues_migration = 'spotdeals_non_food_venues';
  $deals_migration = 'spotdeals_non_food_deals';
  $relative_data_dir = 'non_food';
  $canonical_venues_path = 'modules/custom/spotdeals_import/data/non_food/venues.csv';
  $canonical_deals_path = 'modules/custom/spotdeals_import/data/non_food/deals.csv';
}
else {
  $venues_migration = 'spotdeals_venues';
  $deals_migration = 'spotdeals_deals';
  $relative_data_dir = '';
  $canonical_venues_path = 'modules/custom/spotdeals_import/data/venues.csv';
  $canonical_deals_path = 'modules/custom/spotdeals_import/data/deals.csv';
}

$root = dirname(__DIR__);
$module_data_dir = spotdeals_append_find_data_dir($root, $relative_data_dir);
$append_dir = $module_data_dir . '/.append';
$plan_file = $append_dir . '/append-plan.json';
$venues_ready_file = $append_dir . '/venues.ready';
$deals_ready_file = $append_dir . '/deals.ready';

if (!is_dir($append_dir) && !mkdir($append_dir, 0775, TRUE) && !is_dir($append_dir)) {
  throw new RuntimeException("Unable to create append directory: {$append_dir}");
}

if ($action === 'restore') {
  spotdeals_append_restore_migration_source_paths(
    $plan_file,
    $venues_ready_file,
    $deals_ready_file
  );
  return;
}

$venues_csv = $module_data_dir . '/venues.csv';
$deals_csv = $module_data_dir . '/deals.csv';
$venues_append_csv = $append_dir . '/venues.append.csv';
$deals_append_csv = $append_dir . '/deals.append.csv';

@unlink($venues_ready_file);
@unlink($deals_ready_file);
@unlink($plan_file);

// Recover from any previously interrupted append run before preparing a new one.
spotdeals_append_set_migration_source_path($venues_migration, $canonical_venues_path);
spotdeals_append_set_migration_source_path($deals_migration, $canonical_deals_path);

print "SpotDeals append-only CSV preparation ({$dataset})\n";
print "================================\n";
print "Data directory: {$module_data_dir}\n\n";

$venues_result = spotdeals_append_prepare_venues_csv($venues_csv, $venues_append_csv, $venues_migration);
$deals_result = spotdeals_append_prepare_deals_csv($deals_csv, $deals_append_csv, $deals_migration);

print "Prepared append files\n";
print "---------------------\n";
print "Venues total rows: {$venues_result['total']}\n";
print "Venues append rows: {$venues_result['append']}\n";
print "Venues skipped existing: {$venues_result['skipped']}\n";
print "Deals total rows: {$deals_result['total']}\n";
print "Deals append rows: {$deals_result['append']}\n";
print "Deals skipped existing: {$deals_result['skipped']}\n\n";

$plan = [
  'dataset' => $dataset,
  'migrations' => [],
];

if ($venues_result['append'] > 0) {
  $plan['migrations'][$venues_migration] = $canonical_venues_path;
}

if ($deals_result['append'] > 0) {
  $plan['migrations'][$deals_migration] = $canonical_deals_path;
}

spotdeals_append_write_plan($plan_file, $plan);

try {
  if ($venues_result['append'] > 0) {
    $venues_append_path = $relative_data_dir === ''
      ? 'modules/custom/spotdeals_import/data/.append/venues.append.csv'
      : 'modules/custom/spotdeals_import/data/non_food/.append/venues.append.csv';

    spotdeals_append_set_migration_source_path($venues_migration, $venues_append_path);
    spotdeals_append_touch_ready_file($venues_ready_file);
  }

  if ($deals_result['append'] > 0) {
    $deals_append_path = $relative_data_dir === ''
      ? 'modules/custom/spotdeals_import/data/.append/deals.append.csv'
      : 'modules/custom/spotdeals_import/data/non_food/.append/deals.append.csv';

    spotdeals_append_set_migration_source_path($deals_migration, $deals_append_path);
    spotdeals_append_touch_ready_file($deals_ready_file);
  }
}
catch (Throwable $e) {
  spotdeals_append_restore_migration_source_paths(
    $plan_file,
    $venues_ready_file,
    $deals_ready_file
  );
  throw $e;
}

if ($venues_result['append'] === 0 && $deals_result['append'] === 0) {
  print "No new venue/deal rows found. Nothing to import.\n";
}
else {
  print "Append CSV preparation complete.\n";
  print "Run the ready migrations in separate Drush processes, then restore the source paths.\n";
}

/**
 * Parses the requested dataset.
 */
function spotdeals_append_parse_dataset(array $arguments): string {
  foreach ($arguments as $argument) {
    if ($argument === '--non-food' || $argument === '--dataset=non-food') {
      return 'non-food';
    }

    if ($argument === '--food' || $argument === '--dataset=food') {
      return 'food';
    }
  }

  return 'food';
}

/**
 * Parses the requested helper action.
 */
function spotdeals_append_parse_action(array $arguments): string {
  foreach ($arguments as $argument) {
    if ($argument === '--restore' || $argument === '--action=restore') {
      return 'restore';
    }

    if ($argument === '--prepare' || $argument === '--action=prepare') {
      return 'prepare';
    }
  }

  return 'prepare';
}

/**
 * Finds the SpotDeals import module data directory.
 */
function spotdeals_append_find_data_dir(string $root, string $relative_data_dir = ''): string {
  $suffix = $relative_data_dir === '' ? '' : '/' . trim($relative_data_dir, '/');
  $candidates = [
    $root . '/web/modules/custom/spotdeals_import/data' . $suffix,
    $root . '/modules/custom/spotdeals_import/data' . $suffix,
  ];

  foreach ($candidates as $candidate) {
    if (is_dir($candidate) && is_readable($candidate . '/venues.csv') && is_readable($candidate . '/deals.csv')) {
      return $candidate;
    }
  }

  throw new RuntimeException('Unable to find the requested SpotDeals data directory with venues.csv and deals.csv.');
}

/**
 * Prepares the append-only venues CSV.
 *
 * @return array{total:int,append:int,skipped:int}
 */
function spotdeals_append_prepare_venues_csv(string $source_csv, string $append_csv, string $migration_id): array {
  return spotdeals_append_filter_csv(
    $source_csv,
    $append_csv,
    static function (array $row) use ($migration_id): bool {
      $title = trim((string) ($row['title'] ?? ''));

      if ($title === '') {
        return FALSE;
      }

      if (spotdeals_append_source_exists_in_map($migration_id, [$title])) {
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
function spotdeals_append_prepare_deals_csv(string $source_csv, string $append_csv, string $migration_id): array {
  return spotdeals_append_filter_csv(
    $source_csv,
    $append_csv,
    static function (array $row) use ($migration_id): bool {
      $title = trim((string) ($row['title'] ?? ''));
      $venue = trim((string) ($row['field_venue'] ?? ''));
      $day = trim((string) ($row['field_day_of_week'] ?? ''));
      $start = trim((string) ($row['field_start_time'] ?? ''));

      if ($title === '' || $venue === '') {
        return FALSE;
      }

      if (spotdeals_append_source_exists_in_map($migration_id, [$title, $venue, $day, $start])) {
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
 * Gets the configured migration source path.
 */
function spotdeals_append_get_migration_source_path(string $migration_id): string {
  $config_name = 'migrate_plus.migration.' . $migration_id;
  $source = \Drupal::config($config_name)->get('source') ?? [];

  if (!is_array($source)) {
    throw new RuntimeException("Migration {$migration_id} has invalid source config.");
  }

  $path = (string) ($source['path'] ?? '');
  if ($path === '') {
    throw new RuntimeException("Migration {$migration_id} does not have a source.path value.");
  }

  return $path;
}

/**
 * Changes a migration source path.
 */
function spotdeals_append_set_migration_source_path(string $migration_id, string $path): void {
  $config_name = 'migrate_plus.migration.' . $migration_id;
  $config = \Drupal::configFactory()->getEditable($config_name);
  $source = $config->get('source') ?? [];

  if (!is_array($source)) {
    throw new RuntimeException("Migration {$migration_id} has invalid source config.");
  }

  if (($source['path'] ?? NULL) === $path) {
    print "Migration {$migration_id} source path already set to {$path}\n";
    return;
  }

  $source['path'] = $path;
  $config->set('source', $source);
  $config->save();

  spotdeals_append_clear_migration_plugin_cache();

  print "Set {$migration_id} source path to {$path}\n";
}

/**
 * Writes the source-path restoration plan.
 */
function spotdeals_append_write_plan(string $plan_file, array $plan): void {
  $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === FALSE) {
    throw new RuntimeException('Unable to encode append restoration plan.');
  }

  if (file_put_contents($plan_file, $json . PHP_EOL, LOCK_EX) === FALSE) {
    throw new RuntimeException("Unable to write append restoration plan: {$plan_file}");
  }
}

/**
 * Creates a ready marker used by the shell wrapper.
 */
function spotdeals_append_touch_ready_file(string $ready_file): void {
  if (file_put_contents($ready_file, "ready\n", LOCK_EX) === FALSE) {
    throw new RuntimeException("Unable to write append ready marker: {$ready_file}");
  }
}

/**
 * Restores migration source paths and removes temporary control files.
 */
function spotdeals_append_restore_migration_source_paths(
  string $plan_file,
  string $venues_ready_file,
  string $deals_ready_file
): void {
  if (!is_readable($plan_file)) {
    @unlink($venues_ready_file);
    @unlink($deals_ready_file);
    print "No append restoration plan found. Nothing to restore.\n";
    return;
  }

  $contents = file_get_contents($plan_file);
  if ($contents === FALSE) {
    throw new RuntimeException("Unable to read append restoration plan: {$plan_file}");
  }

  $plan = json_decode($contents, TRUE);
  if (!is_array($plan) || !isset($plan['migrations']) || !is_array($plan['migrations'])) {
    throw new RuntimeException("Invalid append restoration plan: {$plan_file}");
  }

  foreach ($plan['migrations'] as $migration_id => $original_path) {
    if (!is_string($migration_id) || !is_string($original_path) || $original_path === '') {
      continue;
    }

    spotdeals_append_set_migration_source_path($migration_id, $original_path);
  }

  @unlink($venues_ready_file);
  @unlink($deals_ready_file);
  @unlink($plan_file);

  spotdeals_append_clear_migration_plugin_cache();
  print "Migration source paths restored.\n";
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
 * Gets the database connection.
 */
function spotdeals_append_database(): Connection {
  return \Drupal::database();
}
