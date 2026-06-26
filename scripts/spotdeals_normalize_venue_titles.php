<?php

/**
 * Normalizes SpotDeals venue titles to "Venue Title - Location" and updates
 * matching deal venue references.
 *
 * Default mode is audit-only. Nothing is changed unless --apply is passed.
 *
 * Usage from project root:
 *   php scripts/spotdeals_normalize_venue_titles.php
 *   php scripts/spotdeals_normalize_venue_titles.php --apply
 *   php scripts/spotdeals_normalize_venue_titles.php --venue-overrides=scripts/spotdeals_venue_title_overrides.csv --deal-overrides=scripts/spotdeals_deal_venue_overrides.csv
 *
 * Optional venue override CSV columns:
 *   row_number,current_title,new_title
 *
 * Optional deal override CSV columns:
 *   row_number,current_field_venue,new_field_venue
 *
 * Backward-compatible venue override CSV columns:
 *   current_title,new_title
 *
 * Notes:
 * - Row numbers are CSV row numbers, including the header as row 1.
 * - The script refuses to apply changes when the resulting venue titles would collide.
 * - The script refuses to apply changes when a deal references an ambiguous old venue title.
 * - Audit CSVs are written to scripts/reports/ by default.
 */

$opts = getopt('', [
  'apply',
  'venues:',
  'deals:',
  'overrides:',
  'venue-overrides:',
  'deal-overrides:',
  'report-dir:',
  'help',
]);

if (isset($opts['help'])) {
  print_help();
  exit(0);
}

$root = dirname(__DIR__);
$venuesPath = isset($opts['venues']) ? (string) $opts['venues'] : $root . '/web/modules/custom/spotdeals_import/data/venues.csv';
$dealsPath = isset($opts['deals']) ? (string) $opts['deals'] : $root . '/web/modules/custom/spotdeals_import/data/deals.csv';
$legacyOverridesPath = isset($opts['overrides']) ? (string) $opts['overrides'] : '';
$venueOverridesPath = isset($opts['venue-overrides']) ? (string) $opts['venue-overrides'] : $legacyOverridesPath;
$dealOverridesPath = isset($opts['deal-overrides']) ? (string) $opts['deal-overrides'] : '';
$reportDir = isset($opts['report-dir']) ? (string) $opts['report-dir'] : $root . '/scripts/reports';
$apply = isset($opts['apply']);

try {
  ensure_readable_file($venuesPath, 'venues CSV');
  ensure_readable_file($dealsPath, 'deals CSV');

  if ($venueOverridesPath !== '') {
    ensure_readable_file($venueOverridesPath, 'venue override CSV');
  }
  if ($dealOverridesPath !== '') {
    ensure_readable_file($dealOverridesPath, 'deal override CSV');
  }

  if (!is_dir($reportDir) && !mkdir($reportDir, 0775, TRUE) && !is_dir($reportDir)) {
    throw new RuntimeException("Unable to create report directory: {$reportDir}");
  }

  $venues = read_csv_assoc($venuesPath);
  $deals = read_csv_assoc($dealsPath);
  $venueOverrides = $venueOverridesPath !== '' ? read_venue_overrides($venueOverridesPath) : ['by_row' => [], 'by_title' => []];
  $dealOverrides = $dealOverridesPath !== '' ? read_deal_overrides($dealOverridesPath) : [];

  $result = build_normalization_plan($venues['rows'], $deals['rows'], $venueOverrides, $dealOverrides);

  $venueAuditPath = rtrim($reportDir, '/') . '/venue_title_normalization_audit.csv';
  $dealAuditPath = rtrim($reportDir, '/') . '/deal_venue_reference_audit.csv';

  write_csv_assoc($venueAuditPath, [
    'row_number',
    'current_title',
    'normalized_title',
    'city',
    'state',
    'status',
    'reason',
  ], $result['venue_audit']);

  write_csv_assoc($dealAuditPath, [
    'row_number',
    'deal_title',
    'current_field_venue',
    'normalized_field_venue',
    'status',
    'reason',
  ], $result['deal_audit']);

  print_summary($result, $venueAuditPath, $dealAuditPath, $apply);

  if (!$apply) {
    exit($result['has_blockers'] ? 2 : 0);
  }

  if ($result['has_blockers']) {
    fwrite(STDERR, "\nRefusing to apply changes because blockers were found. Fix overrides first, then rerun --apply.\n");
    exit(1);
  }

  write_csv_assoc($venuesPath, $venues['header'], $result['updated_venues']);
  write_csv_assoc($dealsPath, $deals['header'], $result['updated_deals']);

  echo "\nApplied venue title normalization.\n";
  echo "Updated venues CSV: {$venuesPath}\n";
  echo "Updated deals CSV: {$dealsPath}\n";
}
catch (Throwable $e) {
  fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
  exit(1);
}

function print_help(): void {
  echo <<<'TXT'
Normalize SpotDeals venue titles and deal venue references.

Commands:
  php scripts/spotdeals_normalize_venue_titles.php
  php scripts/spotdeals_normalize_venue_titles.php --apply

Options:
  --venues=PATH             Venues CSV path.
  --deals=PATH              Deals CSV path.
  --venue-overrides=PATH    Optional venue override CSV with row_number,current_title,new_title columns.
  --deal-overrides=PATH     Optional deal override CSV with row_number,current_field_venue,new_field_venue columns.
  --overrides=PATH          Backward-compatible alias for --venue-overrides.
  --report-dir=PATH         Directory for audit CSV reports. Default: scripts/reports.
  --apply                   Write changes to the venues/deals CSVs.
  --help                    Show this help text.

TXT;
}

function ensure_readable_file(string $path, string $label): void {
  if (!is_file($path)) {
    throw new RuntimeException("Missing {$label}: {$path}");
  }
  if (!is_readable($path)) {
    throw new RuntimeException("Unreadable {$label}: {$path}");
  }
}

/**
 * @return array{header: array<int, string>, rows: array<int, array<string, string>>}
 */
function read_csv_assoc(string $path): array {
  $handle = fopen($path, 'rb');
  if ($handle === FALSE) {
    throw new RuntimeException("Unable to open CSV: {$path}");
  }

  $header = fgetcsv($handle);
  if ($header === FALSE || $header === [NULL]) {
    fclose($handle);
    throw new RuntimeException("CSV is empty: {$path}");
  }

  if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
  }

  $rows = [];
  while (($data = fgetcsv($handle)) !== FALSE) {
    if ($data === [NULL]) {
      continue;
    }

    $row = [];
    foreach ($header as $index => $column) {
      $row[$column] = isset($data[$index]) ? (string) $data[$index] : '';
    }
    $rows[] = $row;
  }

  fclose($handle);

  return [
    'header' => $header,
    'rows' => $rows,
  ];
}

/**
 * @param array<int, string> $header
 * @param array<int, array<string, string>> $rows
 */
function write_csv_assoc(string $path, array $header, array $rows): void {
  $handle = fopen($path, 'wb');
  if ($handle === FALSE) {
    throw new RuntimeException("Unable to write CSV: {$path}");
  }

  fputcsv($handle, $header);
  foreach ($rows as $row) {
    $line = [];
    foreach ($header as $column) {
      $line[] = $row[$column] ?? '';
    }
    fputcsv($handle, $line);
  }

  fclose($handle);
}

/**
 * @return array{by_row: array<int, string>, by_title: array<string, string>}
 */
function read_venue_overrides(string $path): array {
  $csv = read_csv_assoc($path);
  $hasRowOverrideColumns = in_array('row_number', $csv['header'], TRUE)
    && in_array('current_title', $csv['header'], TRUE)
    && in_array('new_title', $csv['header'], TRUE);
  $hasLegacyColumns = in_array('current_title', $csv['header'], TRUE)
    && in_array('new_title', $csv['header'], TRUE);

  if (!$hasRowOverrideColumns && !$hasLegacyColumns) {
    throw new RuntimeException("Venue override CSV must contain either row_number,current_title,new_title or current_title,new_title columns: {$path}");
  }

  $overrides = [
    'by_row' => [],
    'by_title' => [],
  ];

  foreach ($csv['rows'] as $row) {
    $rowNumber = trim($row['row_number'] ?? '');
    $currentTitle = trim($row['current_title'] ?? '');
    $newTitle = trim($row['new_title'] ?? '');

    if ($currentTitle === '' || $newTitle === '') {
      continue;
    }

    if ($rowNumber !== '') {
      if (!ctype_digit($rowNumber)) {
        throw new RuntimeException("Venue override row_number must be numeric: {$rowNumber}");
      }
      $overrides['by_row'][(int) $rowNumber] = $newTitle;
    }
    else {
      $overrides['by_title'][$currentTitle] = $newTitle;
    }
  }

  return $overrides;
}

/**
 * @return array<int, string>
 */
function read_deal_overrides(string $path): array {
  $csv = read_csv_assoc($path);
  foreach (['row_number', 'current_field_venue', 'new_field_venue'] as $requiredColumn) {
    if (!in_array($requiredColumn, $csv['header'], TRUE)) {
      throw new RuntimeException("Deal override CSV must contain '{$requiredColumn}' column: {$path}");
    }
  }

  $overrides = [];
  foreach ($csv['rows'] as $row) {
    $rowNumber = trim($row['row_number'] ?? '');
    $newFieldVenue = trim($row['new_field_venue'] ?? '');

    if ($rowNumber === '' || $newFieldVenue === '') {
      continue;
    }
    if (!ctype_digit($rowNumber)) {
      throw new RuntimeException("Deal override row_number must be numeric: {$rowNumber}");
    }

    $overrides[(int) $rowNumber] = $newFieldVenue;
  }

  return $overrides;
}

/**
 * @param array<int, array<string, string>> $venues
 * @param array<int, array<string, string>> $deals
 * @param array{by_row: array<int, string>, by_title: array<string, string>} $venueOverrides
 * @param array<int, string> $dealOverrides
 * @return array<string, mixed>
 */
function build_normalization_plan(array $venues, array $deals, array $venueOverrides, array $dealOverrides): array {
  require_columns($venues, ['title', 'field_address_locality', 'field_address_administrative_area'], 'venues CSV');
  require_columns($deals, ['title', 'field_venue'], 'deals CSV');

  $oldTitleCounts = [];
  foreach ($venues as $venue) {
    $oldTitle = trim($venue['title'] ?? '');
    if ($oldTitle === '') {
      continue;
    }
    $oldTitleCounts[$oldTitle] = ($oldTitleCounts[$oldTitle] ?? 0) + 1;
  }

  $rawTitleMap = [];
  $duplicateRawTitleMap = [];
  $updatedVenues = [];
  $venueAudit = [];
  $normalizedTitleCounts = [];
  $blockedOldTitles = [];

  foreach ($venues as $index => $venue) {
    $rowNumber = $index + 2;
    $currentTitle = trim($venue['title'] ?? '');
    $city = trim($venue['field_address_locality'] ?? '');
    $state = trim($venue['field_address_administrative_area'] ?? '');
    $normalizedTitle = normalize_title($venue, $venueOverrides, $rowNumber);
    $status = 'ok';
    $reason = '';
    $hasRowOverride = array_key_exists($rowNumber, $venueOverrides['by_row']);
    $hasTitleOverride = array_key_exists($currentTitle, $venueOverrides['by_title']);

    if ($currentTitle === '') {
      $status = 'blocked';
      $reason = 'Missing venue title.';
    }
    elseif ($city === '' && !$hasRowOverride && !$hasTitleOverride) {
      $status = 'blocked';
      $reason = 'Missing venue city/location.';
    }
    elseif (($oldTitleCounts[$currentTitle] ?? 0) > 1 && !$hasRowOverride && !$hasTitleOverride) {
      $status = 'blocked';
      $reason = 'Duplicate current venue title. Add row-specific venue overrides and deal overrides before updating deal references.';
    }
    elseif ($normalizedTitle === $currentTitle) {
      $status = 'unchanged';
      $reason = $hasRowOverride || $hasTitleOverride ? 'Explicit override keeps this title unchanged.' : 'Already normalized.';
    }
    else {
      $status = 'rename';
      $reason = $hasRowOverride || $hasTitleOverride ? 'Will rename using override.' : 'Will rename venue title and update matching deal references.';
    }

    if ($status === 'blocked') {
      $blockedOldTitles[$currentTitle] = TRUE;
    }

    $normalizedTitleCounts[$normalizedTitle] = ($normalizedTitleCounts[$normalizedTitle] ?? 0) + 1;

    if (($oldTitleCounts[$currentTitle] ?? 0) === 1 || $hasTitleOverride) {
      $rawTitleMap[$currentTitle] = $normalizedTitle;
    }
    else {
      $duplicateRawTitleMap[$currentTitle] = TRUE;
    }

    $venue['title'] = $normalizedTitle;
    $updatedVenues[] = $venue;

    $venueAudit[] = [
      'row_number' => (string) $rowNumber,
      'current_title' => $currentTitle,
      'normalized_title' => $normalizedTitle,
      'city' => $city,
      'state' => $state,
      'status' => $status,
      'reason' => $reason,
    ];
  }

  $collisionTitles = [];
  foreach ($normalizedTitleCounts as $title => $count) {
    if ($title !== '' && $count > 1) {
      $collisionTitles[$title] = $count;
    }
  }

  if ($collisionTitles !== []) {
    foreach ($venueAudit as &$auditRow) {
      $normalizedTitle = $auditRow['normalized_title'];
      if (isset($collisionTitles[$normalizedTitle])) {
        $auditRow['status'] = 'blocked';
        $auditRow['reason'] = 'Normalized title collision: ' . $collisionTitles[$normalizedTitle] . ' venues would share this same title.';
        $blockedOldTitles[$auditRow['current_title']] = TRUE;
      }
    }
    unset($auditRow);
  }

  $validNormalizedVenueTitles = [];
  foreach ($updatedVenues as $venue) {
    $title = trim($venue['title'] ?? '');
    if ($title !== '') {
      $validNormalizedVenueTitles[$title] = TRUE;
    }
  }

  $updatedDeals = [];
  $dealAudit = [];
  foreach ($deals as $index => $deal) {
    $rowNumber = $index + 2;
    $dealTitle = trim($deal['title'] ?? '');
    $currentVenue = trim($deal['field_venue'] ?? '');
    $hasDealOverride = array_key_exists($rowNumber, $dealOverrides);
    $normalizedVenue = $hasDealOverride ? $dealOverrides[$rowNumber] : ($rawTitleMap[$currentVenue] ?? $currentVenue);
    $status = 'unchanged';
    $reason = 'No venue title change needed.';

    if ($currentVenue === '') {
      $status = 'blocked';
      $reason = 'Deal has empty field_venue.';
    }
    elseif ($hasDealOverride && !isset($validNormalizedVenueTitles[$normalizedVenue])) {
      $status = 'blocked';
      $reason = 'Deal override points to a venue title that does not exist after normalization.';
    }
    elseif (isset($duplicateRawTitleMap[$currentVenue]) && !$hasDealOverride) {
      $status = 'blocked';
      $reason = 'Deal references a duplicate current venue title. Add a row-specific deal override.';
    }
    elseif (!array_key_exists($currentVenue, $rawTitleMap) && !isset($duplicateRawTitleMap[$currentVenue]) && !$hasDealOverride) {
      $status = 'blocked';
      $reason = 'Deal references a venue title not found in venues.csv.';
    }
    elseif (isset($blockedOldTitles[$currentVenue]) && !$hasDealOverride) {
      $status = 'blocked';
      $reason = 'Deal references a venue title that has a blocked or ambiguous normalization.';
    }
    elseif ($normalizedVenue !== $currentVenue) {
      $status = 'update';
      $reason = $hasDealOverride ? 'Will update deal field_venue using override.' : 'Will update deal field_venue to normalized venue title.';
      $deal['field_venue'] = $normalizedVenue;
    }

    $updatedDeals[] = $deal;
    $dealAudit[] = [
      'row_number' => (string) $rowNumber,
      'deal_title' => $dealTitle,
      'current_field_venue' => $currentVenue,
      'normalized_field_venue' => $normalizedVenue,
      'status' => $status,
      'reason' => $reason,
    ];
  }

  $venueStatusCounts = count_statuses($venueAudit);
  $dealStatusCounts = count_statuses($dealAudit);
  $hasBlockers = (($venueStatusCounts['blocked'] ?? 0) > 0) || (($dealStatusCounts['blocked'] ?? 0) > 0);

  return [
    'updated_venues' => $updatedVenues,
    'updated_deals' => $updatedDeals,
    'venue_audit' => $venueAudit,
    'deal_audit' => $dealAudit,
    'venue_status_counts' => $venueStatusCounts,
    'deal_status_counts' => $dealStatusCounts,
    'has_blockers' => $hasBlockers,
  ];
}

/**
 * @param array<int, array<string, string>> $rows
 * @param array<int, string> $requiredColumns
 */
function require_columns(array $rows, array $requiredColumns, string $label): void {
  if ($rows === []) {
    throw new RuntimeException("{$label} has no data rows.");
  }

  $availableColumns = array_keys($rows[0]);
  foreach ($requiredColumns as $column) {
    if (!in_array($column, $availableColumns, TRUE)) {
      throw new RuntimeException("{$label} is missing required column: {$column}");
    }
  }
}

/**
 * @param array<string, string> $venue
 * @param array{by_row: array<int, string>, by_title: array<string, string>} $overrides
 */
function normalize_title(array $venue, array $overrides, int $rowNumber): string {
  $title = trim($venue['title'] ?? '');

  if (array_key_exists($rowNumber, $overrides['by_row'])) {
    return trim($overrides['by_row'][$rowNumber]);
  }

  if (array_key_exists($title, $overrides['by_title'])) {
    return trim($overrides['by_title'][$title]);
  }

  $city = trim($venue['field_address_locality'] ?? '');
  if ($city === '') {
    return $title;
  }

  if (title_has_location_suffix($title, $city)) {
    return $title;
  }

  return $title . ' - ' . $city;
}

function title_has_location_suffix(string $title, string $city): bool {
  $title = trim($title);
  $city = trim($city);

  if ($title === '' || $city === '') {
    return FALSE;
  }

  $normalizedTitle = normalize_compare_string($title);
  $normalizedCity = normalize_compare_string($city);

  return str_ends_with($normalizedTitle, ' - ' . $normalizedCity);
}

function normalize_compare_string(string $value): string {
  $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $value = mb_strtolower($value, 'UTF-8');
  $value = preg_replace('/\s+/', ' ', $value) ?? $value;
  return trim($value);
}

/**
 * @param array<int, array<string, string>> $rows
 * @return array<string, int>
 */
function count_statuses(array $rows): array {
  $counts = [];
  foreach ($rows as $row) {
    $status = $row['status'] ?? 'unknown';
    $counts[$status] = ($counts[$status] ?? 0) + 1;
  }
  ksort($counts);
  return $counts;
}

/**
 * @param array<string, mixed> $result
 */
function print_summary(array $result, string $venueAuditPath, string $dealAuditPath, bool $apply): void {
  echo "Mode: " . ($apply ? 'apply' : 'audit') . "\n";
  echo "\nVenue audit counts:\n";
  foreach ($result['venue_status_counts'] as $status => $count) {
    echo "  {$status}: {$count}\n";
  }

  echo "\nDeal audit counts:\n";
  foreach ($result['deal_status_counts'] as $status => $count) {
    echo "  {$status}: {$count}\n";
  }

  echo "\nAudit files:\n";
  echo "  {$venueAuditPath}\n";
  echo "  {$dealAuditPath}\n";

  if ($result['has_blockers']) {
    echo "\nBlockers found. Review rows with status=blocked before applying changes.\n";
  }
  elseif (!$apply) {
    echo "\nNo blockers found. Rerun with --apply to update both CSVs.\n";
  }
}
