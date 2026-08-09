<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Client for the Geoapify Places API.
 */
final class GeoapifyClient {

  private const PLACES_ENDPOINT = 'https://api.geoapify.com/v2/places';

  private const PLACE_DETAILS_ENDPOINT = 'https://api.geoapify.com/v2/place-details';

  private const GEOCODING_SEARCH_ENDPOINT = 'https://api.geoapify.com/v1/geocode/search';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
  ) {}


  /**
   * Searches one page of places for the hybrid venue-search endpoint.
   *
   * Exactly one geographic filter must be supplied: a Geoapify place ID or
   * a latitude/longitude pair.
   *
   * @return array<int, array<string, mixed>>
   *   GeoJSON feature records.
   */
  public function searchPlaces(
    string $apiKey,
    string $categories,
    ?string $placeId = NULL,
    ?float $latitude = NULL,
    ?float $longitude = NULL,
    int $radius = 10000,
    int $limit = 20,
  ): array {
    $apiKey = trim($apiKey);
    $categories = trim($categories);
    $placeId = trim((string) $placeId);

    if ($apiKey === '') {
      throw new \InvalidArgumentException('The Geoapify API key is required.');
    }

    if ($categories === '') {
      throw new \InvalidArgumentException('At least one Geoapify category is required.');
    }

    if (preg_match('/^[a-z0-9_.,]+$/', $categories) !== 1) {
      throw new \InvalidArgumentException('Geoapify categories contain invalid characters.');
    }

    $hasPlaceFilter = $placeId !== '';
    $hasCoordinateFilter = $latitude !== NULL && $longitude !== NULL;

    if ($hasPlaceFilter === $hasCoordinateFilter) {
      throw new \InvalidArgumentException(
        'Provide either a Geoapify place ID or a latitude/longitude pair.',
      );
    }

    $limit = max(1, min(50, $limit));
    $radius = max(100, min(50000, $radius));

    $filter = $hasPlaceFilter
      ? 'place:' . $placeId
      : sprintf('circle:%s,%s,%d', $longitude, $latitude, $radius);

    $query = [
      'categories' => $categories,
      'filter' => $filter,
      'limit' => $limit,
      'apiKey' => $apiKey,
    ];

    if ($hasCoordinateFilter) {
      $query['bias'] = sprintf('proximity:%s,%s', $longitude, $latitude);
    }

    try {
      $response = $this->httpClient->request('GET', self::PLACES_ENDPOINT, [
        'query' => $query,
        'headers' => [
          'Accept' => 'application/json',
        ],
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $exception) {
      $this->logger->error(
        'Geoapify hybrid search failed for categories {categories}: {message}',
        [
          'categories' => $categories,
          'message' => $exception->getMessage(),
        ],
      );

      throw new \RuntimeException(
        'Geoapify venue search failed: ' . $exception->getMessage(),
        0,
        $exception,
      );
    }

    $decoded = json_decode((string) $response->getBody(), TRUE);

    if (!is_array($decoded)) {
      throw new \RuntimeException('Geoapify returned invalid JSON.');
    }

    $features = $decoded['features'] ?? [];

    if (!is_array($features)) {
      throw new \RuntimeException('Geoapify returned an invalid features collection.');
    }

    return array_values(array_filter($features, 'is_array'));
  }

  /**
   * Searches Geoapify amenities by business name and city.
   *
   * @return array<int, array<string, mixed>>
   *   GeoJSON feature records.
   */
  public function searchAmenities(
    string $apiKey,
    string $name,
    string $city,
    string $countryCode = 'US',
    int $limit = 10,
  ): array {
    $apiKey = trim($apiKey);
    $name = trim($name);
    $city = trim($city);
    $countryCode = strtolower(trim($countryCode));

    if ($apiKey === '') {
      throw new \InvalidArgumentException('The Geoapify API key is required.');
    }

    if ($name === '' || $city === '') {
      throw new \InvalidArgumentException(
        'Geoapify amenity search requires both a venue name and city.',
      );
    }

    $limit = max(1, min(20, $limit));

    $query = [
      'name' => $name,
      'city' => $city,
      'type' => 'amenity',
      'lang' => 'en',
      'limit' => $limit,
      'format' => 'geojson',
      'apiKey' => $apiKey,
    ];

    if ($countryCode !== '') {
      $query['filter'] = 'countrycode:' . $countryCode;
    }

    try {
      $response = $this->httpClient->request('GET', self::GEOCODING_SEARCH_ENDPOINT, [
        'query' => $query,
        'headers' => [
          'Accept' => 'application/json',
        ],
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $exception) {
      $this->logger->error(
        'Geoapify amenity search failed for {name} in {city}: {message}',
        [
          'name' => $name,
          'city' => $city,
          'message' => $exception->getMessage(),
        ],
      );

      throw new \RuntimeException(
        'Geoapify amenity search failed: ' . $exception->getMessage(),
        0,
        $exception,
      );
    }

    $decoded = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException('Geoapify returned invalid amenity-search JSON.');
    }

    $features = $decoded['features'] ?? [];
    if (!is_array($features)) {
      throw new \RuntimeException(
        'Geoapify returned an invalid amenity-search feature collection.',
      );
    }

    return array_values(array_filter($features, 'is_array'));
  }


  /**
   * Loads one exact Geoapify place by its unique place ID.
   *
   * @return array<string, mixed>
   *   The exact GeoJSON feature.
   */
  public function getPlaceDetails(string $apiKey, string $placeId): array {
    $apiKey = trim($apiKey);
    $placeId = trim($placeId);

    if ($apiKey === '') {
      throw new \InvalidArgumentException('The Geoapify API key is required.');
    }

    if ($placeId === '') {
      throw new \InvalidArgumentException('The Geoapify place ID is required.');
    }

    try {
      $response = $this->httpClient->request('GET', self::PLACE_DETAILS_ENDPOINT, [
        'query' => [
          'id' => $placeId,
          'features' => 'details',
          'apiKey' => $apiKey,
        ],
        'headers' => [
          'Accept' => 'application/json',
        ],
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $exception) {
      $this->logger->error(
        'Geoapify place-details request failed for place ID {place_id}: {message}',
        [
          'place_id' => $placeId,
          'message' => $exception->getMessage(),
        ],
      );

      throw new \RuntimeException(
        'Geoapify place-details request failed: ' . $exception->getMessage(),
        0,
        $exception,
      );
    }

    $decoded = json_decode((string) $response->getBody(), TRUE);

    if (!is_array($decoded)) {
      throw new \RuntimeException('Geoapify returned invalid place-details JSON.');
    }

    $features = $decoded['features'] ?? [];

    if (!is_array($features) || $features === []) {
      throw new \RuntimeException('Geoapify did not return details for the requested place ID.');
    }

    foreach ($features as $feature) {
      if (!is_array($feature)) {
        continue;
      }

      $properties = $feature['properties'] ?? [];
      if (!is_array($properties)) {
        continue;
      }

      if (($properties['feature_type'] ?? '') !== 'details') {
        continue;
      }

      $returnedId = trim((string) ($properties['place_id'] ?? ''));
      if ($returnedId !== '' && $returnedId !== $placeId) {
        $this->logger->warning(
          'Geoapify place-details returned place ID {returned_id} for requested place ID {requested_id}. The requested ID will remain the canonical external identity.',
          [
            'returned_id' => $returnedId,
            'requested_id' => $placeId,
          ],
        );
      }

      $feature['properties']['place_id'] = $placeId;
      return $feature;
    }

    throw new \RuntimeException('Geoapify place-details response did not contain a details feature.');
  }

  /**
   * Fetches all available venue pages for a place/category combination.
   *
   * @return array<int, array<string, mixed>>
   *   GeoJSON feature records.
   */
  public function fetchPlaces(
    string $apiKey,
    string $placeId,
    string $category,
    int $pageSize = 100,
    int $maxPages = 50,
  ): array {
    $apiKey = trim($apiKey);
    $placeId = trim($placeId);
    $category = trim($category);

    if ($apiKey === '') {
      throw new \InvalidArgumentException('The Geoapify API key is required.');
    }

    if ($placeId === '') {
      throw new \InvalidArgumentException('The Geoapify place ID is required.');
    }

    if ($category === '') {
      throw new \InvalidArgumentException('The Geoapify category is required.');
    }

    $pageSize = max(1, min(500, $pageSize));
    $maxPages = max(1, $maxPages);

    $features = [];

    for ($page = 0; $page < $maxPages; $page++) {
      $offset = $page * $pageSize;

      try {
        $response = $this->httpClient->request('GET', self::PLACES_ENDPOINT, [
          'query' => [
            'categories' => $category,
            'filter' => 'place:' . $placeId,
            'limit' => $pageSize,
            'offset' => $offset,
            'apiKey' => $apiKey,
          ],
          'headers' => [
            'Accept' => 'application/json',
          ],
          'timeout' => 30,
        ]);
      }
      catch (GuzzleException $exception) {
        $this->logger->error(
          'Geoapify request failed for category {category}, offset {offset}: {message}',
          [
            'category' => $category,
            'offset' => $offset,
            'message' => $exception->getMessage(),
          ],
        );

        throw new \RuntimeException(
          sprintf(
            'Geoapify request failed at offset %d: %s',
            $offset,
            $exception->getMessage(),
          ),
          0,
          $exception,
        );
      }

      $decoded = json_decode((string) $response->getBody(), TRUE);

      if (!is_array($decoded)) {
        throw new \RuntimeException(
          sprintf('Geoapify returned invalid JSON at offset %d.', $offset),
        );
      }

      $pageFeatures = $decoded['features'] ?? [];

      if (!is_array($pageFeatures)) {
        throw new \RuntimeException(
          sprintf('Geoapify returned an invalid features collection at offset %d.', $offset),
        );
      }

      foreach ($pageFeatures as $feature) {
        if (is_array($feature)) {
          $features[] = $feature;
        }
      }

      if (count($pageFeatures) < $pageSize) {
        break;
      }
    }

    return $features;
  }

}
