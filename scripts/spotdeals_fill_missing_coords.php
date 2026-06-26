<?php

declare(strict_types=1);

/**
 * Fill missing latitude/longitude in SpotDeals venues.csv.
 *
 * Default behavior is DRY RUN. The CSV is only changed when --write is passed.
 *
 * Usage:
 *   ddev drush php:script scripts/spotdeals_fill_missing_coords.php
 *   ddev drush php:script scripts/spotdeals_fill_missing_coords.php -- --write
 *   ddev drush php:script scripts/spotdeals_fill_missing_coords.php -- --write --email=you@example.com
 *   ddev drush php:script scripts/spotdeals_fill_missing_coords.php -- --limit=5
 *
 * Override CSV:
 *   scripts/spotdeals_geocode_overrides.csv
 *
 * Override CSV fields:
 *   title,field_address_address_line1,field_address_locality,field_address_administrative_area,field_address_postal_code,field_latitude,field_longitude
 *
 * The script matches overrides by address/locality/state/postal code.
 * The title column is included for human readability.
 */

$options = spotdeals_geocode_parse_options(spotdeals_geocode_get_argv());

$root = dirname(__DIR__);
$venues_path = $options['file'] ?? ($root . '/web/modules/custom/spotdeals_import/data/venues.csv');
$overrides_path = $options['overrides'] ?? ($root . '/scripts/spotdeals_geocode_overrides.csv');
$missing_path = $options['missing'] ?? ($root . '/scripts/spotdeals_geocode_missing.csv');

$write = (bool) ($options['write'] ?? FALSE);
$backup = !(bool) ($options['no-backup'] ?? FALSE);
$limit = isset($options['limit']) ? max(0, (int) $options['limit']) : 0;
$email = trim((string) ($options['email'] ?? getenv('SPOTDEALS_GEOCODER_EMAIL') ?: ''));

if (!is_file($venues_path)) {
  fwrite(STDERR, "Missing venues CSV: {$venues_path}\n");
  exit(1);
}

$required_columns = [
  'title',
  'field_address_address_line1',
  'field_address_locality',
  'field_address_administrative_area',
  'field_address_postal_code',
  'field_latitude',
  'field_longitude',
];

$overrides = spotdeals_geocode_load_overrides($overrides_path);
$csv = spotdeals_geocode_read_csv($venues_path, $required_columns);

$header = $csv['header'];
$rows = $csv['rows'];
$indexes = $csv['indexes'];

$total_missing = 0;
$checked = 0;
$would_update = 0;
$updated = 0;
$skipped = [];
$changes = [];
$missing_rows = [];

foreach ($rows as $row_index => &$row) {
  $title = spotdeals_geocode_cell($row, $indexes, 'title');
  $lat = spotdeals_geocode_cell($row, $indexes, 'field_latitude');
  $lon = spotdeals_geocode_cell($row, $indexes, 'field_longitude');

  if ($title === '' || ($lat !== '' && $lon !== '')) {
    continue;
  }

  $total_missing++;

  if ($limit > 0 && $checked >= $limit) {
    continue;
  }

  $checked++;
  $line_number = $row_index + 2;

  $address = spotdeals_geocode_cell($row, $indexes, 'field_address_address_line1');
  $city = spotdeals_geocode_cell($row, $indexes, 'field_address_locality');
  $state = spotdeals_geocode_cell($row, $indexes, 'field_address_administrative_area');
  $zip = spotdeals_geocode_cell($row, $indexes, 'field_address_postal_code');

  echo "[{$checked}] line {$line_number}: {$title}\n";

  $key = spotdeals_geocode_key($address, $city, $state, $zip);

  if (isset($overrides[$key])) {
    $new_lat = $overrides[$key]['field_latitude'];
    $new_lon = $overrides[$key]['field_longitude'];
    $source = 'override';

    if ($overrides[$key]['title'] !== '' && $overrides[$key]['title'] !== $title) {
      echo "  ! override title differs: {$overrides[$key]['title']}\n";
    }

    echo "  OK {$source}: {$new_lat},{$new_lon}\n";
  }
  else {
    if ($address === '' || $city === '' || $state === '' || $zip === '') {
      $skipped[] = "line {$line_number} ({$title}): incomplete address";
      $missing_rows[] = spotdeals_geocode_missing_row($title, $address, $city, $state, $zip);
      echo "  SKIP incomplete address\n";
      continue;
    }

    $query = "{$address}, {$city}, {$state} {$zip}, USA";
    $coords = spotdeals_geocode_nominatim($query, $email);

    if ($coords === NULL) {
      $skipped[] = "line {$line_number} ({$title}): geocode failed for {$query}";
      $missing_rows[] = spotdeals_geocode_missing_row($title, $address, $city, $state, $zip);
      echo "  FAIL Nominatim\n";
      continue;
    }

    [$new_lat, $new_lon] = $coords;
    $source = 'Nominatim';
    echo "  OK {$source}: {$new_lat},{$new_lon}\n";

    usleep(1100000);
  }

  $would_update++;
  $changes[] = "line {$line_number} ({$title}): {$lat},{$lon} => {$new_lat},{$new_lon} [{$source}]";

  if ($write) {
    $row[$indexes['field_latitude']] = $new_lat;
    $row[$indexes['field_longitude']] = $new_lon;
    $updated++;
  }
}
unset($row);

if ($missing_rows) {
  spotdeals_geocode_write_report_csv($missing_path, $missing_rows);
}

if ($write && $updated > 0) {
  if ($backup) {
    $backup_path = $venues_path . '.bak-' . date('Ymd-His');
    if (!copy($venues_path, $backup_path)) {
      fwrite(STDERR, "Could not create backup: {$backup_path}\n");
      exit(1);
    }
  }

  spotdeals_geocode_write_csv($venues_path, $header, $rows);
}

if (!$write) {
  echo "\nDRY RUN: no file changes were written. Pass --write to update venues.csv.\n";
}

echo "\nSummary\n";
echo "-------\n";
echo "Rows missing coordinates found: {$total_missing}\n";
echo "Rows checked this run: {$checked}\n";
echo ($write ? "Updated rows: {$updated}\n" : "Would update rows: {$would_update}\n");

if ($write && $updated > 0 && isset($backup_path)) {
  echo "Backup created: {$backup_path}\n";
}

if ($missing_rows) {
  echo "Missing-coordinate report written: {$missing_path}\n";
}

if ($changes) {
  echo "\nChanges\n";
  echo "-------\n";
  foreach ($changes as $change) {
    echo "- {$change}\n";
  }
}

if ($skipped) {
  echo "\nSkipped\n";
  echo "-------\n";
  foreach ($skipped as $item) {
    echo "- {$item}\n";
  }
}

function spotdeals_geocode_get_argv(): array {
  if (isset($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
    return $GLOBALS['argv'];
  }

  if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
    return $_SERVER['argv'];
  }

  return [__FILE__];
}

function spotdeals_geocode_parse_options(array $argv): array {
  $options = [];

  foreach (array_slice($argv, 1) as $arg) {
    if (!is_string($arg) || $arg === '--') {
      continue;
    }

    if ($arg === '--write') {
      $options['write'] = TRUE;
      continue;
    }

    if ($arg === '--dry-run') {
      $options['write'] = FALSE;
      continue;
    }

    if ($arg === '--no-backup') {
      $options['no-backup'] = TRUE;
      continue;
    }

    foreach (['file', 'overrides', 'missing', 'email', 'limit'] as $name) {
      $prefix = '--' . $name . '=';
      if (str_starts_with($arg, $prefix)) {
        $options[$name] = substr($arg, strlen($prefix));
        continue 2;
      }
    }
  }

  return $options;
}

function spotdeals_geocode_read_csv(string $path, array $required_columns): array {
  $handle = fopen($path, 'rb');
  if (!$handle) {
    fwrite(STDERR, "Could not open CSV: {$path}\n");
    exit(1);
  }

  $header = fgetcsv($handle);
  if (!$header) {
    fwrite(STDERR, "CSV is empty: {$path}\n");
    exit(1);
  }

  $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
  $indexes = array_flip($header);

  foreach ($required_columns as $column) {
    if (!isset($indexes[$column])) {
      fwrite(STDERR, "Missing required column {$column} in {$path}\n");
      exit(1);
    }
  }

  $rows = [];
  while (($row = fgetcsv($handle)) !== FALSE) {
    $rows[] = $row;
  }

  fclose($handle);

  return [
    'header' => $header,
    'indexes' => $indexes,
    'rows' => $rows,
  ];
}

function spotdeals_geocode_load_overrides(string $path): array {
  $required_columns = [
    'title',
    'field_address_address_line1',
    'field_address_locality',
    'field_address_administrative_area',
    'field_address_postal_code',
    'field_latitude',
    'field_longitude',
  ];

  if (!is_file($path)) {
    spotdeals_geocode_write_report_csv($path, []);
  }

  $csv = spotdeals_geocode_read_csv($path, $required_columns);
  $overrides = [];

  foreach ($csv['rows'] as $line_index => $row) {
    $title = spotdeals_geocode_cell($row, $csv['indexes'], 'title');
    $address = spotdeals_geocode_cell($row, $csv['indexes'], 'field_address_address_line1');
    $city = spotdeals_geocode_cell($row, $csv['indexes'], 'field_address_locality');
    $state = spotdeals_geocode_cell($row, $csv['indexes'], 'field_address_administrative_area');
    $zip = spotdeals_geocode_cell($row, $csv['indexes'], 'field_address_postal_code');
    $lat = spotdeals_geocode_cell($row, $csv['indexes'], 'field_latitude');
    $lon = spotdeals_geocode_cell($row, $csv['indexes'], 'field_longitude');

    if ($address === '' && $city === '' && $state === '' && $zip === '' && $lat === '' && $lon === '') {
      continue;
    }

    if ($address === '' || $city === '' || $state === '' || $zip === '' || $lat === '' || $lon === '') {
      $line = $line_index + 2;
      fwrite(STDERR, "Invalid override row {$line} in {$path}: address/city/state/ZIP/latitude/longitude are required.\n");
      exit(1);
    }

    $overrides[spotdeals_geocode_key($address, $city, $state, $zip)] = [
      'title' => $title,
      'field_latitude' => $lat,
      'field_longitude' => $lon,
    ];
  }

  echo "Loaded overrides: " . count($overrides) . "\n";

  return $overrides;
}

function spotdeals_geocode_cell(array $row, array $indexes, string $column): string {
  if (!isset($indexes[$column])) {
    return '';
  }

  return trim((string) ($row[$indexes[$column]] ?? ''));
}

function spotdeals_geocode_key(string $address, string $city, string $state, string $zip): string {
  return implode('|', [
    spotdeals_geocode_normalize_key_part($address),
    spotdeals_geocode_normalize_key_part($city),
    spotdeals_geocode_normalize_key_part($state),
    spotdeals_geocode_normalize_key_part($zip),
  ]);
}

function spotdeals_geocode_normalize_key_part(string $value): string {
  $value = strtolower(trim($value));
  $value = preg_replace('/\s+/', ' ', $value);
  $value = str_replace('.', '', (string) $value);

  return (string) $value;
}

function spotdeals_geocode_missing_row(string $title, string $address, string $city, string $state, string $zip): array {
  return [
    'title' => $title,
    'field_address_address_line1' => $address,
    'field_address_locality' => $city,
    'field_address_administrative_area' => $state,
    'field_address_postal_code' => $zip,
    'field_latitude' => '',
    'field_longitude' => '',
  ];
}

function spotdeals_geocode_write_report_csv(string $path, array $rows): void {
  $header = [
    'title',
    'field_address_address_line1',
    'field_address_locality',
    'field_address_administrative_area',
    'field_address_postal_code',
    'field_latitude',
    'field_longitude',
  ];

  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0775, TRUE);
  }

  $handle = fopen($path, 'wb');
  if (!$handle) {
    fwrite(STDERR, "Could not write CSV: {$path}\n");
    exit(1);
  }

  fputcsv($handle, $header);
  foreach ($rows as $row) {
    fputcsv($handle, [
      $row['title'] ?? '',
      $row['field_address_address_line1'] ?? '',
      $row['field_address_locality'] ?? '',
      $row['field_address_administrative_area'] ?? '',
      $row['field_address_postal_code'] ?? '',
      $row['field_latitude'] ?? '',
      $row['field_longitude'] ?? '',
    ]);
  }

  fclose($handle);
}

function spotdeals_geocode_write_csv(string $path, array $header, array $rows): void {
  $handle = fopen($path, 'wb');
  if (!$handle) {
    fwrite(STDERR, "Could not write CSV: {$path}\n");
    exit(1);
  }

  fputcsv($handle, $header);
  foreach ($rows as $row) {
    fputcsv($handle, $row);
  }

  fclose($handle);
}

function spotdeals_geocode_nominatim(string $query, string $email = ''): ?array {
  if (!function_exists('curl_init')) {
    fwrite(STDERR, "PHP cURL extension is required for geocoding.\n");
    exit(1);
  }

  $params = [
    'format' => 'jsonv2',
    'limit' => '1',
    'addressdetails' => '1',
    'countrycodes' => 'us',
    'q' => $query,
  ];

  $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);

  $user_agent = 'SpotDealsCoordinateFiller/1.0 (https://spotdeals.app)';
  if ($email !== '') {
    $user_agent .= ' ' . $email;
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_USERAGENT => $user_agent,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_FAILONERROR => FALSE,
  ]);

  $response = curl_exec($ch);
  $error = curl_error($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($response === FALSE || $status < 200 || $status >= 300) {
    if ($error !== '') {
      echo "  cURL error: {$error}\n";
    }
    elseif ($status > 0) {
      echo "  HTTP status: {$status}\n";
    }
    return NULL;
  }

  $data = json_decode((string) $response, TRUE);
  if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
    return NULL;
  }

  return [
    number_format((float) $data[0]['lat'], 7, '.', ''),
    number_format((float) $data[0]['lon'], 7, '.', ''),
  ];
}
