<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Validates venue candidates and resolves human-readable unique titles.
 */
final class VenueCandidateValidator {

  /** @var array<int, array<string, string>>|null */
  private ?array $existingVenues = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly string $appRoot,
  ) {}

  /**
   * Resolves titles and marks exact duplicates for a complete candidate batch.
   *
   * @param array<int, array<string, mixed>> $venues
   *
   * @return array<int, array<string, mixed>>
   */
  public function validateBatch(array $venues): array {
    $existing = $this->loadExistingVenues();
    $all = $existing;

    foreach ($venues as $venue) {
      if (!($venue['valid'] ?? FALSE)) {
        continue;
      }
      $all[] = $this->referenceFromCandidate($venue);
    }

    $cityGroups = [];
    $neighborhoodGroups = [];
    foreach ($all as $reference) {
      $brandCityKey = $this->brandCityKey($reference);
      if ($brandCityKey === '') {
        continue;
      }

      $cityGroups[$brandCityKey][$reference['address_key']] = TRUE;

      if ($reference['neighborhood_key'] !== '') {
        $neighborhoodKey = $brandCityKey . '|' . $reference['neighborhood_key'];
        $neighborhoodGroups[$neighborhoodKey][$reference['address_key']] = TRUE;
      }
    }

    $seenBatch = [];
    foreach ($venues as &$venue) {
      $venue['existing_duplicate'] = FALSE;
      $venue['batch_duplicate'] = FALSE;

      if (!($venue['valid'] ?? FALSE)) {
        continue;
      }

      $reference = $this->referenceFromCandidate($venue);
      $exactKey = $this->exactVenueKey($reference);

      foreach ($existing as $existingReference) {
        if ($this->exactVenueKey($existingReference) === $exactKey) {
          $venue['existing_duplicate'] = TRUE;
          break;
        }
      }

      if (isset($seenBatch[$exactKey])) {
        $venue['batch_duplicate'] = TRUE;
      }
      else {
        $seenBatch[$exactKey] = TRUE;
      }

      $brandCityKey = $this->brandCityKey($reference);
      $cityCount = isset($cityGroups[$brandCityKey])
        ? count($cityGroups[$brandCityKey])
        : 1;

      $neighborhoodCount = 0;
      if ($reference['neighborhood_key'] !== '') {
        $neighborhoodKey = $brandCityKey . '|' . $reference['neighborhood_key'];
        $neighborhoodCount = isset($neighborhoodGroups[$neighborhoodKey])
          ? count($neighborhoodGroups[$neighborhoodKey])
          : 0;
      }

      $venue['title'] = $this->buildTitle(
        sourceTitle: (string) $venue['source_title'],
        city: (string) $venue['address']['locality'],
        neighborhood: (string) ($venue['neighborhood'] ?? ''),
        street: (string) ($venue['street'] ?? ''),
        addressLine1: (string) $venue['address']['address_line1'],
        duplicateBrandInCity: $cityCount > 1,
        duplicateBrandInNeighborhood: $neighborhoodCount > 1,
      );
    }
    unset($venue);

    return $venues;
  }

  /**
   * Builds the shortest title that distinguishes same-brand locations.
   */
  private function buildTitle(
    string $sourceTitle,
    string $city,
    string $neighborhood,
    string $street,
    string $addressLine1,
    bool $duplicateBrandInCity,
    bool $duplicateBrandInNeighborhood,
  ): string {
    $sourceTitle = trim($sourceTitle);
    $city = trim($city);
    $neighborhood = trim($neighborhood);
    $street = trim($street);
    $addressLine1 = trim($addressLine1);

    if (!$duplicateBrandInCity) {
      return $city !== '' ? "{$sourceTitle} - {$city}" : $sourceTitle;
    }

    if ($neighborhood !== '' && !$duplicateBrandInNeighborhood) {
      return "{$sourceTitle} - {$neighborhood} - {$city}";
    }

    $streetLabel = $street !== '' ? $street : $addressLine1;
    if ($neighborhood !== '') {
      return "{$sourceTitle} - {$streetLabel} - {$neighborhood} - {$city}";
    }

    return "{$sourceTitle} - {$streetLabel} - {$city}";
  }

  /** @return array<int, array<string, string>> */
  private function loadExistingVenues(): array {
    if ($this->existingVenues !== NULL) {
      return $this->existingVenues;
    }

    $references = $this->loadCsvVenues();
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->execute();

    foreach ($storage->loadMultiple($nids) as $node) {
      if (!$node->hasField('field_address') || $node->get('field_address')->isEmpty()) {
        continue;
      }

      $address = $node->get('field_address')->first();
      $title = trim((string) $node->label());
      $city = trim((string) ($address->locality ?? ''));
      $titleParts = $this->titleParts($title, $city);

      $references[] = $this->makeReference(
        sourceTitle: $titleParts['source_title'],
        title: $title,
        address: trim((string) ($address->address_line1 ?? '')),
        city: $city,
        state: trim((string) ($address->administrative_area ?? '')),
        zip: trim((string) ($address->postal_code ?? '')),
        neighborhood: $titleParts['neighborhood'],
      );
    }

    $unique = [];
    foreach ($references as $reference) {
      $unique[$this->exactVenueKey($reference)] = $reference;
    }

    return $this->existingVenues = array_values($unique);
  }

  /** @return array<int, array<string, string>> */
  private function loadCsvVenues(): array {
    $path = $this->appRoot . '/modules/custom/spotdeals_import/data/venues.csv';
    if (!is_file($path)) {
      return [];
    }

    $handle = fopen($path, 'rb');
    if ($handle === FALSE) {
      return [];
    }

    $header = fgetcsv($handle);
    if ($header === FALSE) {
      fclose($handle);
      return [];
    }

    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    $references = [];

    while (($row = fgetcsv($handle)) !== FALSE) {
      if (count($row) !== count($header)) {
        continue;
      }
      $data = array_combine($header, $row);
      if ($data === FALSE) {
        continue;
      }

      $title = trim((string) ($data['title'] ?? ''));
      $city = trim((string) ($data['field_address_locality'] ?? $data['field_city'] ?? ''));
      $titleParts = $this->titleParts($title, $city);

      $references[] = $this->makeReference(
        sourceTitle: $titleParts['source_title'],
        title: $title,
        address: trim((string) ($data['field_address_address_line1'] ?? $data['field_address_line1'] ?? '')),
        city: $city,
        state: trim((string) ($data['field_address_administrative_area'] ?? $data['field_state'] ?? '')),
        zip: trim((string) ($data['field_address_postal_code'] ?? $data['field_zip'] ?? '')),
        neighborhood: $titleParts['neighborhood'],
      );
    }

    fclose($handle);
    return $references;
  }

  /** @return array{source_title: string, neighborhood: string} */
  private function titleParts(string $title, string $city): array {
    $parts = array_values(array_filter(array_map('trim', explode(' - ', $title)), 'strlen'));
    $sourceTitle = $parts[0] ?? $title;
    $neighborhood = '';

    if (count($parts) >= 3 && $this->normalizeStrictKey((string) end($parts)) === $this->normalizeStrictKey($city)) {
      $neighborhood = $parts[count($parts) - 2];
    }

    return ['source_title' => $sourceTitle, 'neighborhood' => $neighborhood];
  }

  /** @return array<string, string> */
  private function referenceFromCandidate(array $venue): array {
    $address = is_array($venue['address'] ?? NULL) ? $venue['address'] : [];
    return $this->makeReference(
      sourceTitle: (string) ($venue['source_title'] ?? ''),
      title: (string) ($venue['title'] ?? ''),
      address: (string) ($address['address_line1'] ?? ''),
      city: (string) ($address['locality'] ?? ''),
      state: (string) ($address['administrative_area'] ?? ''),
      zip: (string) ($address['postal_code'] ?? ''),
      neighborhood: (string) ($venue['neighborhood'] ?? ''),
    );
  }

  /** @return array<string, string> */
  private function makeReference(
    string $sourceTitle,
    string $title,
    string $address,
    string $city,
    string $state,
    string $zip,
    string $neighborhood,
  ): array {
    return [
      'source_title' => trim($sourceTitle),
      'title' => trim($title),
      'brand_key' => $this->normalizeVenueBrandKey($sourceTitle),
      'address_key' => $this->normalizeAddressKey($address, $city, $state, $zip),
      'city_key' => $this->normalizeStrictKey($city),
      'state_key' => $this->normalizeStrictKey($state),
      'neighborhood_key' => $this->normalizeStrictKey($neighborhood),
    ];
  }

  /** @param array<string, string> $reference */
  private function brandCityKey(array $reference): string {
    if ($reference['brand_key'] === '' || $reference['city_key'] === '') {
      return '';
    }
    return $reference['state_key'] . '|' . $reference['city_key'] . '|' . $reference['brand_key'];
  }

  /** @param array<string, string> $reference */
  private function exactVenueKey(array $reference): string {
    return $reference['brand_key'] . '|' . $reference['address_key'];
  }

  private function normalizeVenueBrandKey(string $value): string {
    $value = $this->normalizeStrictKey($value);
    if ($value === '') {
      return '';
    }

    $tokens = preg_split('/\s+/', $value) ?: [];
    if (($tokens[0] ?? '') === 'the') {
      array_shift($tokens);
    }

    $suffixTokens = [
      'restaurant', 'restaurants', 'cafe', 'cafes', 'caf', 'shop', 'shops',
      'ice', 'cream', 'oceanfront', 'mexican', 'bar', 'bars', 'grill',
      'grille', 'kitchen', 'pizzeria', 'pizza', 'pub', 'tavern', 'bistro',
      'company', 'co', 'place', 'bakery', 'bakehouse', 'cantina', 'diner',
      'eatery', 'food', 'foods', 'cuisine', 'lounge', 'sports', 'waterfront',
    ];

    while ($tokens !== [] && in_array(end($tokens), $suffixTokens, TRUE)) {
      array_pop($tokens);
    }

    $brandKey = implode(' ', $tokens);
    return mb_strlen(str_replace(' ', '', $brandKey)) >= 5 ? $brandKey : $value;
  }

  private function normalizeAddressKey(string $address, string $city, string $state, string $zip): string {
    return trim(implode('|', [
      $this->normalizeStrictKey($address),
      $this->normalizeStrictKey($city),
      $this->normalizeStrictKey($state),
      $this->normalizeStrictKey($zip),
    ]), '|');
  }

  private function normalizeStrictKey(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($transliterated !== FALSE) {
      $value = $transliterated;
    }

    $value = mb_strtolower($value);
    $value = str_replace(['&', '+'], ' and ', $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    return trim($value);
  }

}
