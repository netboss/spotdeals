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
    string $category,
    ?string $placeId = NULL,
    ?float $latitude = NULL,
    ?float $longitude = NULL,
    int $radius = 10000,
    int $limit = 20,
  ): array {
    $apiKey = trim($apiKey);
    $category = trim($category);
    $placeId = trim((string) $placeId);

    if ($apiKey === '') {
      throw new \InvalidArgumentException('The Geoapify API key is required.');
    }

    if ($category === '') {
      throw new \InvalidArgumentException('The Geoapify category is required.');
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
      'categories' => $category,
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
        'Geoapify hybrid search failed for category {category}: {message}',
        [
          'category' => $category,
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
