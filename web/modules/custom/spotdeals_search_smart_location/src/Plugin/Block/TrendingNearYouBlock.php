<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\spotdeals_search_smart_location\Service\DealActivityLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a Trending Near You block.
 *
 * @Block(
 *   id = "spotdeals_search_smart_location_trending_near_you",
 *   admin_label = @Translation("SpotDeals trending near you"),
 *   category = @Translation("SpotDeals")
 * )
 */
final class TrendingNearYouBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Cache max-age for the block in seconds.
   */
  private const CACHE_MAX_AGE = 60;

  /**
   * Default local radius for trending results.
   */
  private const DEFAULT_RADIUS_KM = 25.0;

  /**
   * Constructs the block.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DealActivityLogger $dealActivityLogger,
    private readonly RequestStack $requestStack,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('spotdeals_search_smart_location.deal_activity_logger'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $origin = $this->resolveOrigin();
    $originLat = $origin['lat'] ?? NULL;
    $originLon = $origin['lon'] ?? NULL;
    $currentDealNid = $this->resolveCurrentDealNid();

    // Request one extra row so excluding the current deal does not reduce the
    // visible list size when enough nearby trending rows are available.
    $trending = $this->dealActivityLogger->getTrendingDeals($originLat, $originLon, 7, 14, self::DEFAULT_RADIUS_KM);
    $trending = $this->excludeCurrentDeal($trending, $currentDealNid);
    $isLocalTrending = $originLat !== NULL && $originLon !== NULL && $trending !== [];

    // If the searched/nearby area has no activity yet, keep the block useful but
    // label it honestly as global trending instead of local trending.
    if ($trending === [] && ($originLat !== NULL || $originLon !== NULL)) {
      $originLat = NULL;
      $originLon = NULL;
      $trending = $this->dealActivityLogger->getTrendingDeals(NULL, NULL, 7, 14, self::DEFAULT_RADIUS_KM);
      $trending = $this->excludeCurrentDeal($trending, $currentDealNid);
      $isLocalTrending = FALSE;
    }

    $blockTitle = $isLocalTrending ? $this->t('Trending Near You') : $this->t('Trending Deals');

    if ($trending === []) {
      return [
        '#cache' => [
          'max-age' => self::CACHE_MAX_AGE,
          'contexts' => $this->getCacheContexts(),
        ],
      ];
    }

    $dealNids = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['deal_nid'] ?? 0), $trending)));
    $deals = $dealNids !== [] ? $this->entityTypeManager->getStorage('node')->loadMultiple($dealNids) : [];

    $items = [];
    foreach ($trending as $row) {
      $dealNid = (int) ($row['deal_nid'] ?? 0);
      $deal = $deals[$dealNid] ?? NULL;
      if (!$deal instanceof NodeInterface || !$deal->access('view')) {
        continue;
      }

      $link = Link::fromTextAndUrl($deal->label(), Url::fromRoute('entity.node.canonical', ['node' => $dealNid]))->toRenderable();
      $link['#attributes'] = [
        'class' => ['spotdeals-trending-near-you-link'],
        'data-deal-nid' => (string) $dealNid,
        'data-venue-nid' => (string) ((int) ($row['venue_nid'] ?? 0)),
      ];
      $items[] = $link;
    }

    if ($items === []) {
      return [
        '#cache' => [
          'max-age' => self::CACHE_MAX_AGE,
          'contexts' => $this->getCacheContexts(),
        ],
      ];
    }

    return [
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#attributes' => [
          'class' => ['block-title'],
        ],
        '#value' => $blockTitle,
      ],
      'items' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => [
          'class' => ['spotdeals-trending-near-you'],
        ],
      ],
      '#cache' => [
        'max-age' => self::CACHE_MAX_AGE,
        'contexts' => $this->getCacheContexts(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return self::CACHE_MAX_AGE;
  }

  /**
   * Resolves the current deal node ID so the block does not recommend itself.
   */
  private function resolveCurrentDealNid(): int {
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface && $node->bundle() === 'deal') {
      return (int) $node->id();
    }

    return 0;
  }

  /**
   * Removes the current deal from trending rows and limits visible results.
   *
   * @param array<int, array<string, mixed>> $trending
   *   Trending rows.
   * @param int $currentDealNid
   *   Current deal node ID.
   *
   * @return array<int, array<string, mixed>>
   *   Filtered rows.
   */
  private function excludeCurrentDeal(array $trending, int $currentDealNid): array {
    if ($currentDealNid > 0) {
      $trending = array_values(array_filter($trending, static function (array $row) use ($currentDealNid): bool {
        return (int) ($row['deal_nid'] ?? 0) !== $currentDealNid;
      }));
    }

    return array_slice($trending, 0, 6);
  }

  /**
   * Cache contexts used by location/search-sensitive block output.
   *
   * @return string[]
   *   Cache context IDs.
   */
  public function getCacheContexts(): array {
    return [
      'route',
      'url.path',
      'url.query_args:search_deals',
      'url.query_args:search_api_fulltext',
      'url.query_args:search_clean',
      'url.query_args:search_raw',
      'url.query_args:postal_code_exact',
      'url.query_args:locality_exact',
      'url.query_args:origin_lat',
      'url.query_args:origin_lon',
      'url.query_args:search_origin_mode',
    ];
  }

  /**
   * Resolves the best available local origin for the block.
   *
   * Query-string origin wins. When unavailable, use the current deal/venue page
   * coordinates so the block remains local instead of falling back to global
   * activity across all markets.
   *
   * @return array{lat:float,lon:float}|array{}
   *   Coordinates or an empty array.
   */
  private function resolveOrigin(): array {
    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL) {
      $explicitOrigin = $this->resolveExplicitSearchOrigin();
      if ($explicitOrigin !== []) {
        return $explicitOrigin;
      }

      $lat = $request->query->get('origin_lat');
      $lon = $request->query->get('origin_lon');
      if (is_numeric($lat) && is_numeric($lon)) {
        $lat = (float) $lat;
        $lon = (float) $lon;
        if ($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
          return ['lat' => $lat, 'lon' => $lon];
        }
      }
    }

    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface) {
      return [];
    }

    if ($node->bundle() === 'deal') {
      $venue = $this->loadDealVenue($node);
      if ($venue instanceof NodeInterface) {
        return $this->getNodeCoordinates($venue) ?? [];
      }
    }

    if ($node->bundle() === 'venue') {
      return $this->getNodeCoordinates($node) ?? [];
    }

    return [];
  }

  /**
   * Resolves a typed city/ZIP search origin before browser coordinates.
   *
   * @return array{lat:float,lon:float}|array{}
   *   Coordinates or an empty array.
   */
  private function resolveExplicitSearchOrigin(): array {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return [];
    }

    $explicitZip = trim((string) $request->query->get('postal_code_exact', ''));
    $explicitCity = trim((string) $request->query->get('locality_exact', ''));

    if ($explicitZip !== '' || $explicitCity !== '') {
      $parsed = [
        'zip' => $explicitZip,
        'city' => $this->normalizeCityQueryValue($explicitCity),
      ];
      $origin = $this->resolveParsedOrigin($parsed);
      if ($origin !== []) {
        return $origin;
      }
    }

    $rawSearch = $this->firstNonEmptyQueryValue([
      'search_clean',
      'search_deals',
      'search_api_fulltext',
      'search_raw',
    ]);

    if ($rawSearch === '') {
      return [];
    }

    $parsed = $this->parseSearchLocation($rawSearch);
    if (($parsed['zip'] ?? '') === '' && ($parsed['city'] ?? '') === '') {
      return [];
    }

    return $this->resolveParsedOrigin($parsed);
  }

  /**
   * Returns the first non-empty query-string value from a list of keys.
   *
   * @param string[] $keys
   *   Query-string keys.
   */
  private function firstNonEmptyQueryValue(array $keys): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return '';
    }

    foreach ($keys as $key) {
      $value = trim((string) $request->query->get($key, ''));
      if ($value !== '') {
        return $value;
      }
    }

    return '';
  }

  /**
   * Parses city/ZIP intent from a search phrase.
   *
   * @return array{keywords:string,zip:?string,city:?string}
   *   Parsed search parts.
   */
  private function parseSearchLocation(string $search): array {
    if (function_exists('\_spotdeals_search_smart_location_parse')) {
      return \_spotdeals_search_smart_location_parse($search);
    }

    $normalized = strtolower(trim($search));
    $normalized = preg_replace('/\s+/', ' ', $normalized ?? '') ?? '';

    $zip = NULL;
    if (preg_match('/\b(\d{5})(?:-\d{4})?\b/', $normalized, $matches)) {
      $zip = $matches[1];
    }

    return [
      'keywords' => $normalized,
      'zip' => $zip,
      'city' => NULL,
    ];
  }

  /**
   * Resolves parsed city/ZIP to coordinates.
   *
   * @param array<string, mixed> $parsed
   *   Parsed search parts.
   *
   * @return array{lat:float,lon:float}|array{}
   *   Coordinates or an empty array.
   */
  private function resolveParsedOrigin(array $parsed): array {
    if (function_exists('\_spotdeals_search_smart_location_resolve_origin_from_venues')) {
      return \_spotdeals_search_smart_location_resolve_origin_from_venues($parsed) ?? [];
    }

    return [];
  }

  /**
   * Normalizes an exact city query value into the parser's city slug form.
   */
  private function normalizeCityQueryValue(string $city): string {
    $city = strtolower(trim($city));
    $city = preg_replace('/[^a-z0-9\s]+/', ' ', $city) ?? '';
    $city = preg_replace('/\s+/', ' ', $city) ?? '';
    return trim($city);
  }

  /**
   * Loads a deal's venue.
   */
  private function loadDealVenue(NodeInterface $deal): ?NodeInterface {
    if (!$deal->hasField('field_venue') || $deal->get('field_venue')->isEmpty()) {
      return NULL;
    }

    $venue = $deal->get('field_venue')->entity;
    return $venue instanceof NodeInterface ? $venue : NULL;
  }

  /**
   * Returns node coordinates when available.
   *
   * @return array{lat:float,lon:float}|null
   *   Coordinates or NULL.
   */
  private function getNodeCoordinates(NodeInterface $node): ?array {
    if (
      !$node->hasField('field_latitude') ||
      $node->get('field_latitude')->isEmpty() ||
      !$node->hasField('field_longitude') ||
      $node->get('field_longitude')->isEmpty()
    ) {
      return NULL;
    }

    $lat = (float) $node->get('field_latitude')->value;
    $lon = (float) $node->get('field_longitude')->value;

    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
      return NULL;
    }

    return ['lat' => $lat, 'lon' => $lon];
  }

}
