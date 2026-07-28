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
