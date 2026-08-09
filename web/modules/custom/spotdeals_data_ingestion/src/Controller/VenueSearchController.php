<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Drupal\spotdeals_data_ingestion\Service\ExternalVenueReportStorage;
use Drupal\spotdeals_data_ingestion\Service\GeoapifyClient;
use Drupal\spotdeals_data_ingestion\Service\VenueDealPresenceMatcher;
use Drupal\spotdeals_data_ingestion\Service\VenueLocalMatcher;
use Drupal\spotdeals_data_ingestion\Service\VenueMapper;
use Drupal\spotdeals_data_ingestion\Service\VenueSearchDeduplicator;
use Drupal\spotdeals_data_ingestion\Service\VenueSearchResultBuilder;
use Drupal\spotdeals_data_ingestion\Service\VenueTypeResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides read-only Geoapify venue search for the hybrid architecture.
 */
final class VenueSearchController extends ControllerBase {

  private const CONTRACT_VERSION = '1.0';

  private const API_KEY_STATE_NAME =
    'spotdeals_data_ingestion.geoapify_api_key';

  public function __construct(
    private readonly GeoapifyClient $geoapifyClient,
    private readonly VenueMapper $venueMapper,
    private readonly VenueLocalMatcher $venueLocalMatcher,
    private readonly VenueDealPresenceMatcher $venueDealPresenceMatcher,
    private readonly VenueSearchDeduplicator $venueSearchDeduplicator,
    private readonly VenueSearchResultBuilder $venueSearchResultBuilder,
    private readonly VenueTypeResolver $venueTypeResolver,
    private readonly ExternalVenueReportStorage $externalVenueReportStorage,
    private readonly StateInterface $state,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_data_ingestion.geoapify_client'),
      $container->get('spotdeals_data_ingestion.venue_mapper'),
      $container->get('spotdeals_data_ingestion.venue_local_matcher'),
      $container->get('spotdeals_data_ingestion.venue_deal_presence_matcher'),
      $container->get('spotdeals_data_ingestion.venue_search_deduplicator'),
      $container->get('spotdeals_data_ingestion.venue_search_result_builder'),
      $container->get('spotdeals_data_ingestion.venue_type_resolver'),
      $container->get('spotdeals_data_ingestion.external_venue_report_storage'),
      $container->get('state'),
    );
  }

  /**
   * Returns normalized venues enriched with local SpotDeals match metadata.
   */
  public function search(Request $request): JsonResponse {
    $searchQuery = trim((string) $request->query->get('query', ''));
    $legacyCategory = trim((string) $request->query->get('category', ''));
    $placeId = trim((string) $request->query->get('place_id', ''));
    $latitude = $this->nullableFloat($request->query->get('lat'));
    $longitude = $this->nullableFloat($request->query->get('lon'));
    $radius = (int) $request->query->get('radius', 10000);
    $limit = (int) $request->query->get('limit', 20);

    $intent = NULL;

    if ($searchQuery !== '') {
      $intent = $this->venueTypeResolver->resolveSearchIntent($searchQuery);

      // Fail closed when the search cannot be mapped to taxonomy-owned
      // Geoapify categories. This prevents unrelated location-only venues.
      if ($intent === NULL || $intent['categories'] === []) {
        return $this->response([
          'ok' => TRUE,
          'data' => [
            'contract_version' => self::CONTRACT_VERSION,
            'results' => [],
            'meta' => [
              'source' => 'geoapify',
              'mode' => 'hybrid',
              'query' => $searchQuery,
              'intent_resolved' => FALSE,
              'categories' => [],
              'matched_venue_types' => [],
              'fallback_applied' => FALSE,
              'fallback_categories' => [],
              'returned' => 0,
              'discarded_invalid' => 0,
              'discarded_duplicates' => 0,
              'discarded_existing_deal_venues' => 0,
              'discarded_excluded' => 0,
              'matched_local' => 0,
              'unmatched_external' => 0,
              'local_enrichment_applied' => TRUE,
            ],
          ],
        ]);
      }
    }
    elseif ($legacyCategory !== '') {
      if (preg_match('/^[a-z0-9_.]+$/', $legacyCategory) !== 1) {
        return $this->error('A valid Geoapify category is required.', 400);
      }

      // Backwards compatibility for existing direct endpoint and Drush tests.
      // No taxonomy term is guessed from the category here; VenueMapper will
      // resolve it from taxonomy-owned provider mappings.
      $intent = [
        'query' => '',
        'categories' => [$legacyCategory],
        'term_ids' => [],
        'term_names' => [],
        'matched_phrases' => [],
      ];
    }
    else {
      return $this->error('A search query is required.', 400);
    }

    $categories = implode(',', $intent['categories']);

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

    $fallbackApplied = FALSE;
    $fallbackCategories = [];

    try {
      $features = $this->geoapifyClient->searchPlaces(
        apiKey: $apiKey,
        categories: $categories,
        placeId: $placeId !== '' ? $placeId : NULL,
        latitude: $latitude,
        longitude: $longitude,
        radius: $radius,
        limit: $limit,
      );

      // Geoapify documents its category system as hierarchical: specific
      // category keys narrow results while parent keys broaden them. When a
      // valid specific intent resolves but the provider has no results for
      // those leaf categories, retry only their immediate parent categories.
      //
      // The broader response is then filtered back down using the original
      // category leaf tokens against provider categories and venue names.
      // This prevents a parent retry from becoming a generic restaurant or
      // location-only fallback.
      if ($features === [] && $searchQuery !== '') {
        $fallbackCategories = $this->parentCategories($intent['categories']);
        if ($fallbackCategories !== []) {
          $fallbackFeatures = $this->geoapifyClient->searchPlaces(
            apiKey: $apiKey,
            categories: implode(',', $fallbackCategories),
            placeId: $placeId !== '' ? $placeId : NULL,
            latitude: $latitude,
            longitude: $longitude,
            radius: $radius,
            limit: min(50, max(20, $limit * 3)),
          );

          $features = array_slice(
            $this->filterParentFallbackFeatures(
              $fallbackFeatures,
              $intent['categories'],
            ),
            0,
            max(1, $limit),
          );
          $fallbackApplied = TRUE;
        }
      }
    }
    catch (\InvalidArgumentException $exception) {
      return $this->error($exception->getMessage(), 400);
    }
    catch (\Throwable) {
      return $this->error('Venue search is temporarily unavailable.', 502);
    }

    $candidates = [];
    $invalidCount = 0;
    $existingDealVenueCount = 0;
    $excludedVenueCount = 0;

    foreach ($features as $feature) {
      $venue = $this->venueMapper->map($feature, $intent);

      if (!($venue['valid'] ?? FALSE)) {
        $invalidCount++;
        continue;
      }

      // Geoapify's place filter represents the containing place boundary and
      // may return nearby venues. In place_id mode, expose only the exact
      // requested venue so the public contract has deterministic semantics.
      if ($hasPlaceFilter
        && (string) ($venue['external_id'] ?? '') !== $placeId) {
        continue;
      }

      if ($this->externalVenueReportStorage->isExcluded(
        (string) ($venue['source'] ?? 'geoapify'),
        (string) ($venue['external_id'] ?? ''),
      )) {
        $excludedVenueCount++;
        continue;
      }

      $match = $this->venueLocalMatcher->match($venue);

      // Nearby venues supplement deal discovery. Do not return an external
      // venue when a published SpotDeals deal already represents that
      // business, even when provider and local titles/addresses vary.
      if ($this->venueDealPresenceMatcher->isRepresentedByDeal($venue)) {
        $existingDealVenueCount++;
        continue;
      }

      $candidates[] = [
        'venue' => $venue,
        'match' => $match,
      ];
    }

    $deduplication = $this->venueSearchDeduplicator->deduplicate($candidates);
    $results = [];
    $matchedCount = 0;

    foreach ($deduplication['candidates'] as $candidate) {
      if (!empty($candidate['match']['exists'])) {
        $matchedCount++;
      }

      $results[] = $this->venueSearchResultBuilder->build(
        $candidate['venue'],
        $candidate['match'],
      );
    }

    return $this->response([
      'ok' => TRUE,
      'data' => [
        'contract_version' => self::CONTRACT_VERSION,
        'results' => $results,
        'meta' => [
          'source' => 'geoapify',
          'mode' => 'hybrid',
          'query' => $searchQuery,
          'intent_resolved' => TRUE,
          'categories' => $intent['categories'],
          'matched_venue_types' => $intent['term_names'],
          'fallback_applied' => $fallbackApplied,
          'fallback_categories' => $fallbackCategories,
          'returned' => count($results),
          'discarded_invalid' => $invalidCount,
          'discarded_duplicates' => $deduplication['discarded'],
          'discarded_existing_deal_venues' => $existingDealVenueCount,
          'discarded_excluded' => $excludedVenueCount,
          'matched_local' => $matchedCount,
          'unmatched_external' => count($results) - $matchedCount,
          'local_enrichment_applied' => TRUE,
        ],
      ],
    ]);
  }

  /**
   * Derives immediate parent categories from specific provider categories.
   *
   * Only categories with at least three hierarchy levels are widened. This
   * avoids turning already-broad categories into top-level searches.
   *
   * @param array<int, string> $categories
   *
   * @return array<int, string>
   */
  private function parentCategories(array $categories): array {
    $parents = [];

    foreach ($categories as $category) {
      $segments = array_values(array_filter(explode('.', trim((string) $category))));
      if (count($segments) < 3) {
        continue;
      }

      array_pop($segments);
      if (count($segments) < 2) {
        continue;
      }

      $parents[] = implode('.', $segments);
    }

    $parents = array_values(array_unique($parents));
    sort($parents, SORT_STRING);
    return $parents;
  }

  /**
   * Keeps only parent-fallback features that still express the leaf intent.
   *
   * Relevance is accepted when either:
   * - the provider feature itself carries one of the original specific
   *   categories (or a descendant of it), or
   * - the venue name/category tokens contain a semantic leaf token from the
   *   original requested categories.
   *
   * @param array<int, array<string, mixed>> $features
   * @param array<int, string> $requestedCategories
   *
   * @return array<int, array<string, mixed>>
   */
  private function filterParentFallbackFeatures(
    array $features,
    array $requestedCategories,
  ): array {
    $intentTokens = $this->categoryLeafTokens($requestedCategories);
    if ($intentTokens === []) {
      return [];
    }

    $filtered = [];
    foreach ($features as $feature) {
      $properties = is_array($feature['properties'] ?? NULL)
        ? $feature['properties']
        : [];

      $providerCategories = is_array($properties['categories'] ?? NULL)
        ? array_values(array_filter(array_map(
          static fn (mixed $value): string => trim((string) $value),
          $properties['categories'],
        )))
        : [];

      $hasSpecificCategory = FALSE;
      foreach ($requestedCategories as $requestedCategory) {
        foreach ($providerCategories as $providerCategory) {
          if (
            $providerCategory === $requestedCategory
            || str_starts_with($providerCategory, $requestedCategory . '.')
          ) {
            $hasSpecificCategory = TRUE;
            break 2;
          }
        }
      }

      if ($hasSpecificCategory) {
        $filtered[] = $feature;
        continue;
      }

      $searchable = trim((string) ($properties['name'] ?? ''));
      if ($providerCategories !== []) {
        $searchable .= ' ' . implode(' ', $providerCategories);
      }

      $featureTokens = $this->semanticTokens($searchable);
      if (array_intersect($intentTokens, $featureTokens) !== []) {
        $filtered[] = $feature;
      }
    }

    return $filtered;
  }

  /**
   * @param array<int, string> $categories
   *
   * @return array<int, string>
   */
  private function categoryLeafTokens(array $categories): array {
    $tokens = [];

    foreach ($categories as $category) {
      $segments = explode('.', trim((string) $category));
      $leaf = (string) end($segments);
      $tokens = array_merge($tokens, $this->semanticTokens($leaf));
    }

    return array_values(array_unique($tokens));
  }

  /**
   * @return array<int, string>
   */
  private function semanticTokens(string $value): array {
    $value = mb_strtolower(str_replace(['_', '.', '-'], ' ', $value));
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';
    $parts = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $tokens = [];
    foreach ($parts as $part) {
      if (strlen($part) > 4 && str_ends_with($part, 'ies')) {
        $part = substr($part, 0, -3) . 'y';
      }
      elseif (
        strlen($part) > 3
        && str_ends_with($part, 's')
        && !str_ends_with($part, 'ss')
      ) {
        $part = substr($part, 0, -1);
      }

      if ($part !== '') {
        $tokens[] = $part;
      }
    }

    return array_values(array_unique($tokens));
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
