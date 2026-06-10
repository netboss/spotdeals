<?php

declare(strict_types=1);

/**
 * SpotDeals CSV validation script.
 *
 * Usage:
 *   php scripts/spotdeals_csv_validate.php
 *   ddev drush php:script scripts/spotdeals_csv_validate.php
 *
 * Optional:
 *   php scripts/spotdeals_csv_validate.php --strict-format
 */

$root = dirname(__DIR__);
$venuesPath = $root . '/web/modules/custom/spotdeals_import/data/venues.csv';
$dealsPath = $root . '/web/modules/custom/spotdeals_import/data/deals.csv';
$strictFormat = in_array('--strict-format', $argv ?? [], true);

$expectedVenuesHeader = [
  'title',
  'field_address_address_line1',
  'field_address_locality',
  'field_address_administrative_area',
  'field_address_postal_code',
  'field_address_country_code',
  'field_venue_type',
  'field_short_description',
  'field_phone',
  'field_website_uri',
  'field_website_title',
  'field_cuisine',
  'field_claimed_listing',
  'field_image',
  'image_search_hint',
  'field_latitude',
  'field_longitude',
  'field_tags',
  'field_address_line1',
  'field_city',
  'field_state',
  'field_zip',
  'field_country',
  'field_description',
  'field_website',
  'field_source',
  'field_menu_url',
  'field_cta',
  'field_cta_title',
];

$expectedDealsHeader = [
  'title',
  'field_price_offer_text',
  'field_day_of_week',
  'field_start_time',
  'field_deal_category',
  'field_venue',
  'field_active',
  'field_recurring',
  'field_end_time',
  'field_cta',
  'field_cta_title',
];

$errors = [];
$warnings = [];
$review = [];
$format = [];

[$venueRows, $venueTitles] = readCsvFile($venuesPath, $expectedVenuesHeader, 'venues.csv', $errors, $warnings);
[$dealRows] = readCsvFile($dealsPath, $expectedDealsHeader, 'deals.csv', $errors, $warnings);

validateVenues($venueRows, $errors, $warnings, $review);
validateDeals($dealRows, $venueTitles, $errors, $warnings, $review, $format, $strictFormat);

print "\nSpotDeals CSV Validation\n";
print "========================\n";
print "venues.csv rows: " . count($venueRows) . "\n";
print "deals.csv rows: " . count($dealRows) . "\n";
print "Errors: " . count($errors) . "\n";
print "Warnings: " . count($warnings) . "\n";
print "Review: " . count($review) . "\n";
print "Format: " . count($format) . ($strictFormat ? "\n\n" : " (hidden; use --strict-format)\n\n");

printMessages('ERRORS', $errors);
printMessages('WARNINGS', $warnings);
printMessages('REVIEW', $review);
if ($strictFormat) {
  printMessages('FORMAT', $format);
}

if ($errors) {
  exit(1);
}

print "CSV validation passed.\n";
exit(0);

function readCsvFile(string $path, array $expectedHeader, string $label, array &$errors, array &$warnings): array {
  if (!is_file($path)) {
    $errors[] = "{$label}: file not found at {$path}";
    return [[], []];
  }

  $rawLines = file($path, FILE_IGNORE_NEW_LINES);
  if ($rawLines === false) {
    $errors[] = "{$label}: could not read file lines";
    return [[], []];
  }

  foreach ($rawLines as $index => $rawLine) {
    $line = $index + 1;

    if (trim($rawLine) === '') {
      $warnings[] = "{$label}: line {$line} is blank";
      continue;
    }

    if (preg_match('/\r/', $rawLine)) {
      $errors[] = "{$label}: line {$line} contains a carriage return/control character; normalize line endings";
    }

    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $rawLine)) {
      $errors[] = "{$label}: line {$line} contains a non-printable control character";
    }
  }

  $handle = fopen($path, 'rb');
  if (!$handle) {
    $errors[] = "{$label}: could not open file";
    return [[], []];
  }

  $header = fgetcsv($handle);
  if ($header === false) {
    fclose($handle);
    $errors[] = "{$label}: empty file";
    return [[], []];
  }

  $header = removeBom($header);

  if ($header !== $expectedHeader) {
    $errors[] = "{$label}: header does not match expected import header";
  }

  $expectedColumnCount = count($header);
  $rows = [];
  $titles = [];
  $line = 1;

  while (($row = fgetcsv($handle)) !== false) {
    $line++;

    if (count($row) === 1 && trim((string) $row[0]) === '') {
      $warnings[] = "{$label}: line {$line} is blank";
      continue;
    }

    if (count($row) !== $expectedColumnCount) {
      $errors[] = "{$label}: line {$line} has " . count($row) . " columns; expected {$expectedColumnCount}";
      continue;
    }

    $assoc = array_combine($header, $row);
    if ($assoc === false) {
      $errors[] = "{$label}: line {$line} could not be mapped to header";
      continue;
    }

    $assoc['_line'] = $line;
    $rows[] = $assoc;

    if (isset($assoc['title'])) {
      $titles[normalizeStrictKey($assoc['title'])] = $assoc['title'];
    }
  }

  fclose($handle);

  return [$rows, $titles];
}

function validateVenues(array $rows, array &$errors, array &$warnings, array &$review): void {
  $seenExactVenue = [];
  $seenTitle = [];
  $seenAddress = [];

  foreach ($rows as $row) {
    $line = (int) $row['_line'];
    $title = trim((string) $row['title']);
    $address = trim((string) ($row['field_address_address_line1'] ?? ''));
    $city = trim((string) ($row['field_address_locality'] ?? ''));
    $state = trim((string) ($row['field_address_administrative_area'] ?? ''));
    $zip = trim((string) ($row['field_address_postal_code'] ?? ''));
    $lat = trim((string) ($row['field_latitude'] ?? ''));
    $lon = trim((string) ($row['field_longitude'] ?? ''));
    $website = trim((string) ($row['field_website'] ?? ''));
    $menuUrl = trim((string) ($row['field_menu_url'] ?? ''));
    $cta = trim((string) ($row['field_cta'] ?? ''));
    $ctaTitle = trim((string) ($row['field_cta_title'] ?? ''));

    if ($title === '') {
      $errors[] = "venues.csv: line {$line} has empty title";
    }

    $titleKey = normalizeStrictKey($title);
    $addressKey = normalizeAddressKey($address, $city, $state, $zip);
    $exactVenueKey = $titleKey . '|' . $addressKey;

    if ($titleKey !== '' && $addressKey !== '') {
      if (isset($seenExactVenue[$exactVenueKey])) {
        $warnings[] = "venues.csv: exact duplicate venue on line {$line}; first seen on line {$seenExactVenue[$exactVenueKey]}: {$title}";
      }
      else {
        $seenExactVenue[$exactVenueKey] = $line;
      }
    }

    if ($titleKey !== '') {
      $seenTitle[$titleKey][] = [$line, $title, $addressKey];
    }

    if ($addressKey !== '') {
      $seenAddress[$addressKey][] = [$line, $title, $titleKey];
    }

    if ($lat === '' || $lon === '') {
      $warnings[] = "venues.csv: line {$line} missing latitude/longitude: {$title}";
    }
    elseif (!is_numeric($lat) || !is_numeric($lon)) {
      $errors[] = "venues.csv: line {$line} has invalid latitude/longitude: {$title}";
    }
    elseif ((float) $lat < -90 || (float) $lat > 90 || (float) $lon < -180 || (float) $lon > 180) {
      $errors[] = "venues.csv: line {$line} has out-of-range latitude/longitude: {$title}";
    }

    validateOptionalUrl($website, 'field_website', 'venues.csv', $line, $title, $warnings);
    validateOptionalUrl($menuUrl, 'field_menu_url', 'venues.csv', $line, $title, $warnings);
    validateCtaPair($cta, $ctaTitle, 'venues.csv', $line, $title, $warnings);
  }

  foreach ($seenTitle as $titleKey => $items) {
    $addressKeys = array_unique(array_column($items, 2));
    if (count($items) > 1 && count($addressKeys) > 1) {
      $review[] = 'venues.csv: same title at multiple addresses: ' . summarizeReviewItems($items);
    }
  }

  foreach ($seenAddress as $addressKey => $items) {
    $titleKeys = array_unique(array_column($items, 2));
    if (count($items) > 1 && count($titleKeys) > 1) {
      $review[] = 'venues.csv: same address with multiple titles: ' . summarizeReviewItems($items);
    }
  }
}

function validateDeals(array $rows, array $venueTitles, array &$errors, array &$warnings, array &$review, array &$format, bool $strictFormat): void {
  $seenDeal = [];
  $seenOffer = [];
  $seenVenueTitle = [];

  foreach ($rows as $row) {
    $line = (int) $row['_line'];
    $title = trim((string) $row['title']);
    $offer = trim((string) $row['field_price_offer_text']);
    $venue = trim((string) $row['field_venue']);
    $day = trim((string) $row['field_day_of_week']);
    $start = trim((string) $row['field_start_time']);
    $category = trim((string) $row['field_deal_category']);
    $active = trim((string) $row['field_active']);
    $recurring = trim((string) $row['field_recurring']);
    $end = trim((string) $row['field_end_time']);
    $cta = trim((string) $row['field_cta']);
    $ctaTitle = trim((string) $row['field_cta_title']);

    if ($title === '') {
      $errors[] = "deals.csv: line {$line} has empty title";
    }

    if ($offer === '') {
      $warnings[] = "deals.csv: line {$line} has empty field_price_offer_text: {$title} / {$venue}";
    }

    if ($venue === '') {
      $errors[] = "deals.csv: line {$line} has empty field_venue";
    }
    elseif (!isset($venueTitles[normalizeStrictKey($venue)])) {
      $errors[] = "deals.csv: line {$line} references missing venue: {$venue}";
    }

    $dealKey = normalizeStrictKey($title) . '|' . normalizeStrictKey($offer) . '|' . normalizeStrictKey($venue) . '|' . normalizeDayKey($day) . '|' . normalizeTimeKey($start) . '|' . normalizeStrictKey($category) . '|' . normalizeTimeKey($end);
    if (isset($seenDeal[$dealKey])) {
      $warnings[] = "deals.csv: exact duplicate deal on line {$line}; first seen on line {$seenDeal[$dealKey]}: {$title} / {$venue}";
    }
    else {
      $seenDeal[$dealKey] = $line;
    }

    $offerKey = normalizeStrictKey($venue) . '|' . normalizeStrictKey($offer) . '|' . normalizeDayKey($day) . '|' . normalizeTimeKey($start) . '|' . normalizeTimeKey($end);
    if ($offer !== '') {
      if (isset($seenOffer[$offerKey])) {
        $review[] = "deals.csv: same venue/offer/schedule on line {$line}; first seen on line {$seenOffer[$offerKey]}: {$title} / {$venue}";
      }
      else {
        $seenOffer[$offerKey] = $line;
      }
    }

    $venueTitleKey = normalizeStrictKey($venue) . '|' . normalizeStrictKey($title);
    $seenVenueTitle[$venueTitleKey][] = [$line, $title, $offer];

    if (!in_array($active, ['', '0', '1', '0.0', '1.0'], true)) {
      $warnings[] = "deals.csv: line {$line} has non-standard field_active '{$active}': {$title} / {$venue}";
    }

    if (!in_array($recurring, ['', '0', '1', '0.0', '1.0'], true)) {
      $warnings[] = "deals.csv: line {$line} has non-standard field_recurring '{$recurring}': {$title} / {$venue}";
    }

    validateCtaPair($cta, $ctaTitle, 'deals.csv', $line, "{$title} / {$venue}", $warnings);
    validateOptionalUrl($cta, 'field_cta', 'deals.csv', $line, "{$title} / {$venue}", $warnings);

    if ($strictFormat) {
      if ($day !== '' && !isRecognizedDayValue($day)) {
        $format[] = "deals.csv: line {$line} has non-standard field_day_of_week '{$day}': {$title} / {$venue}";
      }
      if ($start !== '' && !isRecognizedTimeValue($start)) {
        $format[] = "deals.csv: line {$line} has non-standard field_start_time '{$start}': {$title} / {$venue}";
      }
      if ($end !== '' && !isRecognizedTimeValue($end)) {
        $format[] = "deals.csv: line {$line} has non-standard field_end_time '{$end}': {$title} / {$venue}";
      }
    }
  }

  foreach ($seenVenueTitle as $items) {
    if (count($items) <= 1) {
      continue;
    }
    $offers = array_unique(array_map(static fn(array $item): string => normalizeStrictKey((string) $item[2]), $items));
    if (count($offers) > 1) {
      $review[] = 'deals.csv: same deal title under same venue with different offers: ' . summarizeReviewItems($items);
    }
  }
}

function printMessages(string $title, array $messages): void {
  if (!$messages) {
    return;
  }

  print "{$title}\n";
  print str_repeat('-', strlen($title)) . "\n";
  foreach ($messages as $message) {
    print "- {$message}\n";
  }
  print "\n";
}

function removeBom(array $header): array {
  if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
  }
  return $header;
}

function normalizeStrictKey(string $value): string {
  $value = mb_strtolower(trim($value));
  $value = str_replace(['&', '+'], ' and ', $value);
  $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
  $value = preg_replace('/\s+/', ' ', $value);
  return trim((string) $value);
}

function normalizeAddressKey(string $address, string $city, string $state, string $zip): string {
  $value = normalizeStrictKey($address) . '|' . normalizeStrictKey($city) . '|' . normalizeStrictKey($state) . '|' . normalizeStrictKey($zip);
  return trim($value, '|');
}

function normalizeDayKey(string $value): string {
  return normalizeStrictKey(str_replace([';', ','], ' ', $value));
}

function normalizeTimeKey(string $value): string {
  return normalizeStrictKey(str_replace(['–', '—'], '-', $value));
}

function validateOptionalUrl(string $value, string $field, string $label, int $line, string $context, array &$warnings): void {
  if ($value === '') {
    return;
  }

  if (!filter_var($value, FILTER_VALIDATE_URL)) {
    $warnings[] = "{$label}: line {$line} has invalid {$field}: {$context}";
  }
}

function validateCtaPair(string $cta, string $ctaTitle, string $label, int $line, string $context, array &$warnings): void {
  if ($cta !== '' && $ctaTitle === '') {
    $warnings[] = "{$label}: line {$line} has field_cta but empty field_cta_title: {$context}";
  }

  if ($cta === '' && $ctaTitle !== '') {
    $warnings[] = "{$label}: line {$line} has field_cta_title but empty field_cta: {$context}";
  }
}

function isRecognizedTimeValue(string $value): bool {
  $value = trim($value);
  if ($value === '') {
    return true;
  }

  if (preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
    return true;
  }

  if (preg_match('/^(?:1[0-2]|0?[1-9])(?::[0-5]\d)?\s*(?:am|pm)$/i', $value)) {
    return true;
  }

  return in_array(mb_strtolower($value), ['morning', 'afternoon', 'evening', 'night', 'regular hours', 'happy hour', 'open', 'close', 'open close'], true);
}

function isRecognizedDayValue(string $value): bool {
  $value = trim($value);
  if ($value === '') {
    return true;
  }

  $normalized = mb_strtolower(str_replace([';', ','], ' ', $value));
  $normalized = preg_replace('/\s+/', ' ', (string) $normalized);
  $tokens = preg_split('/[\s\-]+/', trim((string) $normalized));

  $allowed = [
    'all', 'daily', 'every', 'day', 'nightly', 'weekdays', 'weekday', 'weekend', 'weekends',
    'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
    'mon', 'tue', 'tues', 'wed', 'thu', 'thur', 'thurs', 'fri', 'sat', 'sun',
    'and', 'except', 'week', 'check', 'venue',
  ];

  foreach ($tokens as $token) {
    if ($token !== '' && !in_array($token, $allowed, true)) {
      return false;
    }
  }

  return true;
}

function summarizeReviewItems(array $items): string {
  $parts = [];
  foreach (array_slice($items, 0, 5) as $item) {
    $parts[] = 'line ' . (int) $item[0] . ' (' . (string) $item[1] . ')';
  }

  if (count($items) > 5) {
    $parts[] = '+' . (count($items) - 5) . ' more';
  }

  return implode('; ', $parts);
}
