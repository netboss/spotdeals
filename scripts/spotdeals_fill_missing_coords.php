<?php

declare(strict_types=1);

/**
 * Fill missing latitude/longitude in SpotDeals venues.csv.
 *
 * Usage from project root:
 *   ddev drush php:script scripts/spotdeals_fill_missing_coords.php
 *
 * This script only updates rows where BOTH field_latitude and field_longitude
 * are blank. It uses a small manual override list for city-only venues and
 * falls back to OpenStreetMap Nominatim for street-address rows.
 */

$root = dirname(__DIR__);
$venuesPath = $root . '/web/modules/custom/spotdeals_import/data/venues.csv';
$backupPath = $venuesPath . '.bak-' . date('Ymd-His');

if (!is_file($venuesPath)) {
  fwrite(STDERR, "Missing venues.csv at {$venuesPath}\n");
  exit(1);
}

$manual = [
  // City-only/home-business records: approximate Daytona Beach center.
  'Obviously Dessert' => ['29.2108', '-81.0228'],
  'Sweet Dreams Bakery' => ['29.2108', '-81.0228'],
];

$handle = fopen($venuesPath, 'rb');
if (!$handle) {
  fwrite(STDERR, "Could not open {$venuesPath}\n");
  exit(1);
}

$header = fgetcsv($handle);
if (!$header) {
  fwrite(STDERR, "venues.csv is empty\n");
  exit(1);
}
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
$indexes = array_flip($header);
foreach (['title', 'field_address_address_line1', 'field_address_locality', 'field_address_administrative_area', 'field_address_postal_code', 'field_latitude', 'field_longitude'] as $required) {
  if (!isset($indexes[$required])) {
    fwrite(STDERR, "Missing required column: {$required}\n");
    exit(1);
  }
}

$rows = [];
while (($row = fgetcsv($handle)) !== false) {
  $rows[] = $row;
}
fclose($handle);

$updated = 0;
$skipped = [];

foreach ($rows as &$row) {
  $title = trim((string) ($row[$indexes['title']] ?? ''));
  $lat = trim((string) ($row[$indexes['field_latitude']] ?? ''));
  $lon = trim((string) ($row[$indexes['field_longitude']] ?? ''));

  if ($title === '' || $lat !== '' || $lon !== '') {
    continue;
  }

  if (isset($manual[$title])) {
    [$row[$indexes['field_latitude']], $row[$indexes['field_longitude']]] = $manual[$title];
    $updated++;
    continue;
  }

  $address = trim((string) ($row[$indexes['field_address_address_line1']] ?? ''));
  $city = trim((string) ($row[$indexes['field_address_locality']] ?? ''));
  $state = trim((string) ($row[$indexes['field_address_administrative_area']] ?? ''));
  $zip = trim((string) ($row[$indexes['field_address_postal_code']] ?? ''));

  // Do not geocode city-only rows unless they are explicitly in manual map.
  if ($address === '' || strcasecmp($address, $city) === 0 || $zip === '') {
    $skipped[] = "{$title}: insufficient address";
    continue;
  }

  $query = trim("{$address}, {$city}, {$state} {$zip}, USA");
  $coords = geocode($query);
  if (!$coords) {
    $skipped[] = "{$title}: geocode failed for {$query}";
    continue;
  }

  [$row[$indexes['field_latitude']], $row[$indexes['field_longitude']]] = $coords;
  $updated++;

  // Respect Nominatim usage policy.
  usleep(1100000);
}
unset($row);

copy($venuesPath, $backupPath);
$out = fopen($venuesPath, 'wb');
if (!$out) {
  fwrite(STDERR, "Could not write {$venuesPath}\n");
  exit(1);
}
fputcsv($out, $header);
foreach ($rows as $row) {
  fputcsv($out, $row);
}
fclose($out);

echo "Updated missing coordinates: {$updated}\n";
echo "Backup created: {$backupPath}\n";
if ($skipped) {
  echo "Skipped:\n";
  foreach ($skipped as $item) {
    echo "- {$item}\n";
  }
}

function geocode(string $query): ?array {
  $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . rawurlencode($query);
  $context = stream_context_create([
    'http' => [
      'method' => 'GET',
      'header' => "User-Agent: SpotDealsCSVValidator/1.0 (https://spotdeals.app)\r\n",
      'timeout' => 15,
    ],
  ]);

  $json = @file_get_contents($url, false, $context);
  if ($json === false) {
    return null;
  }

  $data = json_decode($json, true);
  if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
    return null;
  }

  return [round((float) $data[0]['lat'], 7), round((float) $data[0]['lon'], 7)];
}
