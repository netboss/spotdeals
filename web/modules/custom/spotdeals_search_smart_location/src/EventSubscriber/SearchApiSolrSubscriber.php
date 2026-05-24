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
   * Maximum number of ranked deal IDs to constrain in Solr.
   */
  private const MAX_RANKED_NIDS = 250;

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
   * Applies a hard Solr geofilt for explicit-location and browser near-me searches.
   */
  public function preQuery(PreQueryEvent $event): void {
    $query = $event->getSearchApiQuery();

    if ($query->getIndex()->id() !== 'deals_solr') {
      return;
    }

    $request = \Drupal::request();

    $recommendation_mode = (bool) $request->attributes->get(
      'spotdeals_search_smart_location.recommendation_mode',
      FALSE
    );

    $recommended_nids = $request->attributes->get('spotdeals_search_smart_location.recommended_deal_nids');
    $recommended_nids = is_array($recommended_nids)
      ? array_values(array_unique(array_filter(array_map('intval', $recommended_nids))))
      : [];


    $origin_mode = (string) $request->query->get('search_origin_mode', '');
    $origin_lat = $request->query->get('origin_lat');
    $origin_lon = $request->query->get('origin_lon');
    $parsed_origin = $request->attributes->get('spotdeals_search_smart_location.origin');
    $is_browser_near_me = (bool) $request->attributes->get('spotdeals_search_smart_location.browser_near_me', FALSE);
    $is_explicit_location = (bool) $request->attributes->get('spotdeals_search_smart_location.explicit_location', FALSE);
    $is_browser_origin_request = $origin_mode === 'browser' && is_numeric($origin_lat) && is_numeric($origin_lon);

    $lat = NULL;
    $lon = NULL;
    $source = NULL;

    if (
      $is_explicit_location &&
      is_array($parsed_origin) &&
      isset($parsed_origin['lat'], $parsed_origin['lon']) &&
      is_numeric($parsed_origin['lat']) &&
      is_numeric($parsed_origin['lon'])
    ) {
      $lat = (float) $parsed_origin['lat'];
      $lon = (float) $parsed_origin['lon'];
      $source = 'explicit_location';
    }
    elseif ($origin_mode === 'browser' && is_numeric($origin_lat) && is_numeric($origin_lon)) {
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

    if (!$is_browser_near_me && !$is_explicit_location && !$is_browser_origin_request) {
      \Drupal::logger('spotdeals_search_smart_location')->notice(
        'SMART LOCATION subscriber skipped geofilt at PRE_QUERY: source="@source" near_me="0" explicit_location="0" browser_origin_request="0" recommendation_mode="@recommendation_mode" lat="@lat" lon="@lon"',
        [
          '@source' => $source ?? '',
          '@recommendation_mode' => $recommendation_mode ? '1' : '0',
          '@lat' => (string) $lat,
          '@lon' => (string) $lon,
        ]
      );
      return;
    }

    $ranked_nids = $request->attributes->get('spotdeals_search_smart_location.ranked_deal_nids');
    $ranked_nids = is_array($ranked_nids)
      ? array_values(array_unique(array_filter(array_map('intval', $ranked_nids))))
      : [];

    if (!$recommendation_mode && !empty($ranked_nids)) {
      $ranked_nids = array_slice($ranked_nids, 0, self::MAX_RANKED_NIDS);
      $operator = count($ranked_nids) > 1 ? 'IN' : '=';
      $value = count($ranked_nids) > 1 ? $ranked_nids : $ranked_nids[0];
      $query->addCondition('nid', $value, $operator);

      \Drupal::logger('spotdeals_search_smart_location')->notice(
        'SMART LOCATION subscriber constrained location query to ranked deal IDs at PRE_QUERY: nids_count="@count" operator="@operator"',
        [
          '@count' => (string) count($ranked_nids),
          '@operator' => $operator,
        ]
      );
    }

    if ($recommendation_mode && !empty($recommended_nids)) {
      \Drupal::logger('spotdeals_search_smart_location')->notice(
        'SMART LOCATION subscriber left recommendation mode unconstrained at PRE_QUERY because direct recommendation rendering is active: source="@source" lat="@lat" lon="@lon" recommended_nids="@nids"',
        [
          '@source' => $source ?? '',
          '@lat' => (string) $lat,
          '@lon' => (string) $lon,
          '@nids' => implode(',', $recommended_nids),
        ]
      );
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

    $solarium_query->addParam('sfield', self::SOLR_GEO_FIELD);
    $solarium_query->addParam('pt', $pt);

    // Keep the hard geo filter, but do not force geodist() sorting here.
    //
    // Browser near-me searches already compute a keyword-aware ranked NID list
    // in NearMeRanker. Forcing Solr to sort by geodist() after constraining to
    // those NIDs makes nearby weak matches outrank stronger deal matches before
    // Views/pre-render can apply the custom rank order.
    //
    // Leaving Search API/Solr's normal relevance order intact gives the View a
    // more relevant page of candidates, while the pre-render layer still applies
    // the deterministic NearMeRanker order among returned rows.
    \Drupal::logger('spotdeals_search_smart_location')->notice(
      'SMART LOCATION subscriber applied PRE_QUERY geofilt without forced Solr distance sort: source="@source" near_me="@near_me" explicit_location="@explicit_location" recommendation_mode="@recommendation_mode" lat="@lat" lon="@lon" radius_km="@radius" search_api_geo_field="@search_api_geo_field" solr_geo_field="@solr_geo_field" fq="@fq" sort="@sort" ranked_constraint_count="@ranked_count"',
      [
        '@source' => $source ?? '',
        '@near_me' => ($is_browser_near_me || $is_browser_origin_request) ? '1' : '0',
        '@explicit_location' => $is_explicit_location ? '1' : '0',
        '@recommendation_mode' => $recommendation_mode ? '1' : '0',
        '@lat' => (string) $lat,
        '@lon' => (string) $lon,
        '@radius' => (string) self::DEFAULT_RADIUS_KM,
        '@search_api_geo_field' => self::SEARCH_API_GEO_FIELD,
        '@solr_geo_field' => self::SOLR_GEO_FIELD,
        '@fq' => $geo_filter,
        '@sort' => 'unchanged',
        '@ranked_count' => (string) count($ranked_nids),
      ]
    );
  }

}
