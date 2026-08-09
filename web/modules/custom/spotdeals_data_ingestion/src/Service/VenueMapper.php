<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

/**
 * Maps Geoapify features into normalized SpotDeals venue data.
 */
final class VenueMapper {

  public function __construct(
    private readonly VenueTypeResolver $venueTypeResolver,
  ) {}

  /**
   * Maps one Geoapify GeoJSON feature.
   *
   * @param array<string, mixed> $feature
   *   Geoapify GeoJSON feature.
   * @param string|array<string, mixed> $intent
   *   Either a legacy requested Geoapify category string or a resolved search
   *   intent containing categories and preferred taxonomy term IDs.
   *
   * @return array<string, mixed>
   *   Normalized venue data and validation information.
   */
  public function map(array $feature, string|array $intent): array {
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
    $sourceCategories = is_array($properties['categories'] ?? NULL)
      ? array_values(array_filter(array_map('strval', $properties['categories'])))
      : [];

    $requestedCategories = [];
    $preferredTermIds = [];

    if (is_string($intent)) {
      $requestedCategories = array_values(array_filter(array_map(
        'trim',
        explode(',', $intent),
      )));
    }
    else {
      $requestedCategories = is_array($intent['categories'] ?? NULL)
        ? array_values(array_filter(array_map('strval', $intent['categories'])))
        : [];
      $preferredTermIds = is_array($intent['term_ids'] ?? NULL)
        ? array_values(array_map('intval', $intent['term_ids']))
        : [];
    }

    $venueType = $this->venueTypeResolver->resolveVenueType(
      $sourceCategories,
      $preferredTermIds,
      $requestedCategories,
    );

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

    if ($venueType === NULL) {
      $errors[] = 'unmapped_venue_type';
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
      'venue_type_tid' => $venueType['tid'] ?? NULL,
      'venue_type_name' => $venueType['name'] ?? '',
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
      'source_categories' => $sourceCategories,
    ];
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
