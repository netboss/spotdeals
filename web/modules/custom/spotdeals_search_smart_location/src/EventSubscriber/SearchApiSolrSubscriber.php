<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location\EventSubscriber;

use Drupal\search_api_solr\Event\PreQueryEvent;
use Drupal\search_api_solr\Event\SearchApiSolrEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Alters Search API Solr queries for SpotDeals search.
 */
final class SearchApiSolrSubscriber implements EventSubscriberInterface {

  /**
   * Default radius in kilometers.
   */
  private const DEFAULT_RADIUS_KM = 25.0;

  /**
   * Search API field machine name.
   */
  private const SEARCH_API_GEO_FIELD = 'field_coordinates';

  /**
   * Actual Solr geo field name.
   */
  private const SOLR_GEO_FIELD = 'locs_field_coordinates';

  /**
   * Filter query key used on the Solarium query.
   */
  private const GEO_FILTER_KEY = 'spotdeals_near_me_geofilt';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiSolrEvents::PRE_QUERY => 'preQuery',
    ];
  }

  /**
   * Applies a hard Solr geofilt and distance-first sort for browser near-me searches.
   */
  public function preQuery(PreQueryEvent $event): void {
    $query = $event->getSearchApiQuery();

    if ($query->getIndex()->id() !== 'deals_solr') {
      return;
    }

    $request = \Drupal::request();

    $origin_mode = (string) $request->query->get('search_origin_mode', '');
    $origin_lat = $request->query->get('origin_lat');
    $origin_lon = $request->query->get('origin_lon');
    $parsed_origin = $request->attributes->get('spotdeals_search_smart_location.origin');
    $is_browser_near_me = (bool) $request->attributes->get('spotdeals_search_smart_location.browser_near_me', FALSE);

    $lat = NULL;
    $lon = NULL;
    $source = NULL;

    if ($origin_mode === 'browser' && is_numeric($origin_lat) && is_numeric($origin_lon)) {
      $lat = (float) $origin_lat;
      $lon = (float) $origin_lon;
      $source = 'browser';
    }
    elseif (
      is_array($parsed_origin) &&
      isset($parsed_origin['lat'], $parsed_origin['lon']) &&
      is_numeric($parsed_origin['lat']) &&
      is_numeric($parsed_origin['lon'])
    ) {
      $lat = (float) $parsed_origin['lat'];
      $lon = (float) $parsed_origin['lon'];
      $source = $is_browser_near_me ? 'request_attribute_browser' : 'parsed_place';
    }

    if ($lat === NULL || $lon === NULL) {
      \Drupal::logger('spotdeals_search_smart_location')->notice(
        'SMART LOCATION subscriber found no geo origin to apply at PRE_QUERY.'
      );
      return;
    }

    if (!$is_browser_near_me) {
      \Drupal::logger('spotdeals_search_smart_location')->notice(
        'SMART LOCATION subscriber skipped geofilt at PRE_QUERY: source="@source" near_me="0" lat="@lat" lon="@lon"',
        [
          '@source' => $source ?? '',
          '@lat' => (string) $lat,
          '@lon' => (string) $lon,
        ]
      );
      return;
    }

    $solarium_query = $event->getSolariumQuery();

    $pt = sprintf('%F,%F', $lat, $lon);
    $geo_filter = sprintf(
      '{!geofilt sfield=%s pt=%s d=%F}',
      self::SOLR_GEO_FIELD,
      $pt,
      self::DEFAULT_RADIUS_KM
    );

    $solarium_query
      ->createFilterQuery(self::GEO_FILTER_KEY)
      ->setQuery($geo_filter);

    // Also provide global spatial params so geodist() sorting uses the same point/field.
    $solarium_query->addParam('sfield', self::SOLR_GEO_FIELD);
    $solarium_query->addParam('pt', $pt);

    // Force distance-first ordering at Solr level so the final rendered HTML uses
    // the same nearest-first order as the query result.
    if (method_exists($solarium_query, 'clearSorts')) {
      $solarium_query->clearSorts();
    }
    elseif (method_exists($solarium_query, 'setSorts')) {
      $solarium_query->setSorts([]);
    }

    $distance_sort = sprintf('geodist(%s,%F,%F)', self::SOLR_GEO_FIELD, $lat, $lon);
    $solarium_query->addSort($distance_sort, 'asc');
    $solarium_query->addSort('score', 'desc');

    \Drupal::logger('spotdeals_search_smart_location')->notice(
      'SMART LOCATION subscriber applied PRE_QUERY geofilt and forced Solr distance sort: source="@source" near_me="1" lat="@lat" lon="@lon" radius_km="@radius" search_api_geo_field="@search_api_geo_field" solr_geo_field="@solr_geo_field" fq="@fq" sort="@sort"',
      [
        '@source' => $source ?? '',
        '@lat' => (string) $lat,
        '@lon' => (string) $lon,
        '@radius' => (string) self::DEFAULT_RADIUS_KM,
        '@search_api_geo_field' => self::SEARCH_API_GEO_FIELD,
        '@solr_geo_field' => self::SOLR_GEO_FIELD,
        '@fq' => $geo_filter,
        '@sort' => $distance_sort . ' asc, score desc',
      ]
    );
  }

}
