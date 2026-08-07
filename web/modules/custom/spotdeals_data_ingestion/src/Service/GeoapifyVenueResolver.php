<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

/**
 * Resolves a Geoapify amenity from a SpotDeals venue title.
 */
final class GeoapifyVenueResolver {

  private const MINIMUM_SCORE = 0.90;

  private const MINIMUM_SCORE_MARGIN = 0.10;

  public function __construct(
    private readonly GeoapifyClient $geoapifyClient,
  ) {}

  /**
   * Resolves one unambiguous Geoapify venue feature.
   *
   * @param string $apiKey
   *   Geoapify API key.
   * @param string $venueTitle
   *   SpotDeals venue title in "Venue name - Location" format.
   * @param string $category
   *   Expected Geoapify category.
   *
   * @return array<string, mixed>
   *   The selected Geoapify GeoJSON feature.
   */
  public function resolve(
    string $apiKey,
    string $venueTitle,
    string $category,
  ): array {
    [$venueName, $location] = $this->parseVenueTitle($venueTitle);

    $features = $this->geoapifyClient->searchAmenities(
      $apiKey,
      $venueName,
      $location,
      'US',
      10,
    );

    $ranked = [];
    foreach ($features as $feature) {
      $score = $this->scoreFeature($feature, $venueName, $location, $category);
      if ($score > 0.0) {
        $ranked[] = [
          'feature' => $feature,
          'score' => $score,
        ];
      }
    }

    usort(
      $ranked,
      static fn(array $left, array $right): int => $right['score'] <=> $left['score'],
    );

    if ($ranked === [] || $ranked[0]['score'] < self::MINIMUM_SCORE) {
      throw new \RuntimeException(
        sprintf(
          'No high-confidence Geoapify match was found for "%s".',
          $venueTitle,
        ),
      );
    }

    if (
      isset($ranked[1])
      && ($ranked[0]['score'] - $ranked[1]['score']) < self::MINIMUM_SCORE_MARGIN
    ) {
      throw new \RuntimeException(
        sprintf(
          'Geoapify returned multiple plausible matches for "%s".',
          $venueTitle,
        ),
      );
    }

    return $ranked[0]['feature'];
  }

  /**
   * Parses a SpotDeals venue title into venue name and location.
   *
   * @return array{0: string, 1: string}
   *   Venue name and location.
   */
  private function parseVenueTitle(string $venueTitle): array {
    $venueTitle = trim($venueTitle);
    $separatorPosition = strrpos($venueTitle, ' - ');

    if ($separatorPosition === FALSE) {
      throw new \InvalidArgumentException(
        'Automatic Geoapify resolution requires "Venue name - Location" format.',
      );
    }

    $venueName = trim(substr($venueTitle, 0, $separatorPosition));
    $location = trim(substr($venueTitle, $separatorPosition + 3));

    if ($venueName === '' || $location === '') {
      throw new \InvalidArgumentException(
        'Automatic Geoapify resolution requires both a venue name and location.',
      );
    }

    return [$venueName, $location];
  }

  /**
   * Scores one Geoapify geocoding feature conservatively.
   */
  private function scoreFeature(
    array $feature,
    string $venueName,
    string $location,
    string $category,
  ): float {
    $properties = $feature['properties'] ?? [];
    if (!is_array($properties)) {
      return 0.0;
    }

    $placeId = trim((string) ($properties['place_id'] ?? ''));
    $resultType = trim((string) ($properties['result_type'] ?? ''));
    if ($placeId === '' || $resultType !== 'amenity') {
      return 0.0;
    }

    $candidateName = $this->normalize((string) ($properties['name'] ?? ''));
    $expectedName = $this->normalize($venueName);
    if ($candidateName === '' || $candidateName !== $expectedName) {
      return 0.0;
    }

    $candidateLocations = array_values(array_filter(array_map(
      fn(string $value): string => $this->normalize($value),
      [
        (string) ($properties['city'] ?? ''),
        (string) ($properties['town'] ?? ''),
        (string) ($properties['village'] ?? ''),
        (string) ($properties['municipality'] ?? ''),
        (string) ($properties['suburb'] ?? ''),
      ],
    )));

    $expectedLocation = $this->normalize($location);
    if (!in_array($expectedLocation, $candidateLocations, TRUE)) {
      return 0.0;
    }

    $countryCode = strtolower(trim((string) ($properties['country_code'] ?? '')));
    if ($countryCode !== '' && $countryCode !== 'us') {
      return 0.0;
    }

    $score = 0.95;

    $candidateCategory = trim((string) ($properties['category'] ?? ''));
    if ($candidateCategory !== '' && $category !== '') {
      if ($candidateCategory === $category) {
        $score += 0.05;
      }
      elseif (!$this->categoriesAreCompatible($candidateCategory, $category)) {
        return 0.0;
      }
    }

    $confidence = (float) ($properties['rank']['confidence'] ?? 0.0);
    if ($confidence > 0.0) {
      $score += min(0.05, $confidence * 0.05);
    }

    return min(1.0, $score);
  }

  /**
   * Determines whether two Geoapify categories share a useful root.
   */
  private function categoriesAreCompatible(string $candidate, string $expected): bool {
    $candidateRoot = explode('.', $candidate, 2)[0] ?? '';
    $expectedRoot = explode('.', $expected, 2)[0] ?? '';

    return $candidateRoot !== '' && $candidateRoot === $expectedRoot;
  }

  /**
   * Normalizes names and locations for conservative exact comparison.
   */
  private function normalize(string $value): string {
    $value = mb_strtolower(trim($value));
    $value = str_replace(['’', '`'], "'", $value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
  }

}
