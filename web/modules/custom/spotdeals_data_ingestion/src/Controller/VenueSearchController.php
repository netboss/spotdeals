<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Drupal\spotdeals_data_ingestion\Service\GeoapifyClient;
use Drupal\spotdeals_data_ingestion\Service\VenueLocalMatcher;
use Drupal\spotdeals_data_ingestion\Service\VenueMapper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides read-only Geoapify venue search for the hybrid architecture.
 */
final class VenueSearchController extends ControllerBase {

  private const API_KEY_STATE_NAME =
    'spotdeals_data_ingestion.geoapify_api_key';

  public function __construct(
    private readonly GeoapifyClient $geoapifyClient,
    private readonly VenueMapper $venueMapper,
    private readonly VenueLocalMatcher $venueLocalMatcher,
    private readonly StateInterface $state,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_data_ingestion.geoapify_client'),
      $container->get('spotdeals_data_ingestion.venue_mapper'),
      $container->get('spotdeals_data_ingestion.venue_local_matcher'),
      $container->get('state'),
    );
  }

  /**
   * Returns normalized venues enriched with local SpotDeals match metadata.
   */
  public function search(Request $request): JsonResponse {
    $category = trim((string) $request->query->get('category', ''));
    $placeId = trim((string) $request->query->get('place_id', ''));
    $latitude = $this->nullableFloat($request->query->get('lat'));
    $longitude = $this->nullableFloat($request->query->get('lon'));
    $radius = (int) $request->query->get('radius', 10000);
    $limit = (int) $request->query->get('limit', 20);

    if ($category === '' || preg_match('/^[a-z0-9_.]+$/', $category) !== 1) {
      return $this->error(
        'A valid Geoapify category is required.',
        400,
      );
    }

    $hasPlaceFilter = $placeId !== '';
    $hasAnyCoordinate = $latitude !== NULL || $longitude !== NULL;
    $hasCoordinateFilter = $latitude !== NULL && $longitude !== NULL;

    if ($hasAnyCoordinate && !$hasCoordinateFilter) {
      return $this->error('Both lat and lon are required together.', 400);
    }

    if ($hasPlaceFilter === $hasCoordinateFilter) {
      return $this->error(
        'Provide either place_id or a lat/lon pair.',
        400,
      );
    }

    if ($latitude !== NULL && ($latitude < -90 || $latitude > 90)) {
      return $this->error('lat must be between -90 and 90.', 400);
    }

    if ($longitude !== NULL && ($longitude < -180 || $longitude > 180)) {
      return $this->error('lon must be between -180 and 180.', 400);
    }

    $apiKey = trim((string) $this->state->get(
      self::API_KEY_STATE_NAME,
      '',
    ));

    if ($apiKey === '') {
      return $this->error('Geoapify is not configured.', 503);
    }

    try {
      $features = $this->geoapifyClient->searchPlaces(
        apiKey: $apiKey,
        category: $category,
        placeId: $placeId !== '' ? $placeId : NULL,
        latitude: $latitude,
        longitude: $longitude,
        radius: $radius,
        limit: $limit,
      );
    }
    catch (\InvalidArgumentException $exception) {
      return $this->error($exception->getMessage(), 400);
    }
    catch (\Throwable) {
      return $this->error('Venue search is temporarily unavailable.', 502);
    }

    $results = [];
    $invalidCount = 0;
    $matchedCount = 0;

    foreach ($features as $feature) {
      $venue = $this->venueMapper->map($feature, $category);

      if (!($venue['valid'] ?? FALSE)) {
        $invalidCount++;
        continue;
      }

      $venue['spotdeals'] = $this->venueLocalMatcher->match($venue);

      if ($venue['spotdeals']['exists']) {
        $matchedCount++;
      }

      $results[] = $venue;
    }

    return $this->response([
      'ok' => TRUE,
      'data' => [
        'results' => $results,
        'meta' => [
          'source' => 'geoapify',
          'mode' => 'hybrid',
          'category' => $category,
          'returned' => count($results),
          'discarded_invalid' => $invalidCount,
          'matched_local' => $matchedCount,
          'unmatched_external' => count($results) - $matchedCount,
          'local_enrichment_applied' => TRUE,
        ],
      ],
    ]);
  }

  private function nullableFloat(mixed $value): ?float {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    return is_numeric($value) ? (float) $value : NULL;
  }

  private function error(string $message, int $status): JsonResponse {
    return $this->response([
      'ok' => FALSE,
      'error' => [
        'message' => $message,
      ],
    ], $status);
  }

  private function response(array $payload, int $status = 200): JsonResponse {
    $response = new JsonResponse($payload, $status);
    $response->headers->set('Cache-Control', 'no-store, max-age=0');
    return $response;
  }

}
