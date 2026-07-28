<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

/**
 * Maps Geoapify features into normalized SpotDeals venue data.
 */
final class VenueMapper {

  /**
   * Canonical SpotDeals venue type terms.
   */
  private const VENUE_TYPE_MAP = [
    'catering.restaurant' => [
      'tid' => 41,
      'name' => 'Restaurant',
    ],
    'catering.bar' => [
      'tid' => 42,
      'name' => 'Bar',
    ],
    'production.brewery' => [
      'tid' => 43,
      'name' => 'Brewery',
    ],
    'catering.cafe' => [
      'tid' => 44,
      'name' => 'Cafe',
    ],
    'commercial.food_and_drink.bakery' => [
      'tid' => 131,
      'name' => 'Bakery',
    ],
  ];

  /**
   * Maps one Geoapify GeoJSON feature.
   *
   * @return array<string, mixed>
   *   Normalized venue data and validation information.
   */
  public function map(array $feature, string $requestedCategory): array {
    $properties = is_array($feature['properties'] ?? NULL)
      ? $feature['properties']
      : [];

    $geometry = is_array($feature['geometry'] ?? NULL)
      ? $feature['geometry']
      : [];

    $coordinates = is_array($geometry['coordinates'] ?? NULL)
      ? $geometry['coordinates']
      : [];

    $longitude = $this->decimalOrNull($properties['lon'] ?? $coordinates[0] ?? NULL);
    $latitude = $this->decimalOrNull($properties['lat'] ?? $coordinates[1] ?? NULL);

    $name = trim((string) ($properties['name'] ?? ''));
    $addressLine1 = trim((string) ($properties['address_line1'] ?? ''));

    // Preserve a valid Geoapify address_line1. Fall back to structured street
    // data only when it is missing or duplicates the venue name.
    if (
      $addressLine1 === ''
      || ($name !== '' && strcasecmp($addressLine1, $name) === 0)
    ) {
      $houseNumber = trim((string) ($properties['housenumber'] ?? ''));
      $street = trim((string) ($properties['street'] ?? ''));
      $addressLine1 = trim($houseNumber . ' ' . $street);
    }

    // When no usable street address is available, use the first component of
    // address_line2 as a final fallback.
    if ($addressLine1 === '') {
      $addressLine2 = trim((string) ($properties['address_line2'] ?? ''));
      if ($addressLine2 !== '') {
        $addressLine1 = trim((string) strtok($addressLine2, ','));
      }
    }

    $city = trim((string) (
      $properties['city']
      ?? $properties['town']
      ?? $properties['village']
      ?? ''
    ));

    $stateCode = strtoupper(trim((string) (
      $properties['state_code']
      ?? $properties['state']
      ?? ''
    )));

    $postalCode = trim((string) ($properties['postcode'] ?? ''));

    $neighborhood = trim((string) (
      $properties['suburb']
      ?? $properties['district']
      ?? $properties['neighbourhood']
      ?? $properties['neighborhood']
      ?? ''
    ));

    $street = trim((string) ($properties['street'] ?? ''));
    $countryCode = strtoupper(trim((string) ($properties['country_code'] ?? 'US')));

    $website = trim((string) ($properties['website'] ?? ''));
    $phone = trim((string) (
      $properties['contact']['phone']
      ?? $properties['phone']
      ?? ''
    ));

    $email = trim((string) (
      $properties['contact']['email']
      ?? $properties['email']
      ?? ''
    ));

    $placeId = trim((string) ($properties['place_id'] ?? ''));

    $venueType = $this->resolveVenueType($requestedCategory, $properties);

    $errors = [];

    if ($name === '') {
      $errors[] = 'missing_name';
    }

    if ($addressLine1 === '') {
      $errors[] = 'missing_address_line1';
    }
    elseif (!$this->isLikelyStreetAddress($addressLine1)) {
      $errors[] = 'invalid_street_address';
    }

    if ($city === '') {
      $errors[] = 'missing_city';
    }

    if ($stateCode === '') {
      $errors[] = 'missing_state';
    }

    if ($latitude === NULL || $longitude === NULL) {
      $errors[] = 'missing_coordinates';
    }

    return [
      'valid' => $errors === [],
      'errors' => $errors,
      'external_provider' => 'geoapify',
      'external_id' => $placeId,
      'title' => $city !== '' ? sprintf('%s - %s', $name, $city) : $name,
      'source_title' => $name,
      'neighborhood' => $neighborhood,
      'street' => $street,
      'venue_type_tid' => $venueType['tid'],
      'venue_type_name' => $venueType['name'],
      'address' => [
        'country_code' => $countryCode !== '' ? $countryCode : 'US',
        'administrative_area' => $stateCode,
        'locality' => $city,
        'postal_code' => $postalCode,
        'address_line1' => $addressLine1,
        'address_line2' => '',
      ],
      'latitude' => $latitude,
      'longitude' => $longitude,
      'coordinates_wkt' => $longitude !== NULL && $latitude !== NULL
        ? sprintf('POINT (%s %s)', $longitude, $latitude)
        : NULL,
      'website' => $website,
      'phone' => $phone,
      'email' => $email,
      'formatted_address' => trim((string) ($properties['formatted'] ?? '')),
      'source_categories' => is_array($properties['categories'] ?? NULL)
        ? $properties['categories']
        : [],
    ];
  }

  /**
   * Resolves a Geoapify category to a canonical SpotDeals venue type.
   *
   * @return array{tid: int, name: string}
   */
  private function resolveVenueType(
    string $requestedCategory,
    array $properties,
  ): array {
    if (isset(self::VENUE_TYPE_MAP[$requestedCategory])) {
      return self::VENUE_TYPE_MAP[$requestedCategory];
    }

    $categories = is_array($properties['categories'] ?? NULL)
      ? $properties['categories']
      : [];

    foreach (self::VENUE_TYPE_MAP as $category => $venueType) {
      if (in_array($category, $categories, TRUE)) {
        return $venueType;
      }
    }

    return self::VENUE_TYPE_MAP['catering.restaurant'];
  }


  /**
   * Checks whether a value resembles a usable street address.
   */
  private function isLikelyStreetAddress(string $address): bool {
    $address = trim($address);

    if ($address === '') {
      return FALSE;
    }

    // Most complete addresses contain a street number.
    if (preg_match('/\d/', $address) === 1) {
      return TRUE;
    }

    // Some valid street names have no building number.
    return preg_match(
      '/\b(?:street|st|avenue|ave|road|rd|boulevard|blvd|drive|dr|lane|ln|'
      . 'court|ct|place|pl|parkway|pkwy|highway|hwy|terrace|ter|trail|trl|'
      . 'way|circle|cir|plaza|square|sq)\b\.?$/i',
      $address,
    ) === 1;
  }

  private function decimalOrNull(mixed $value): ?float {
    if ($value === NULL || $value === '' || !is_numeric($value)) {
      return NULL;
    }

    return (float) $value;
  }

}
