<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location\EventSubscriber;

use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Alters Search API Solr queries for SpotDeals search.
 */
final class SearchApiSolrSubscriber implements EventSubscriberInterface {

  /**
   * Default radius in kilometers (10 miles).
   */
  private const DEFAULT_RADIUS_KM = 16.09;

  /**
   * Geo field machine name in the Search API index.
   */
  private const GEO_FIELD = 'field_coordinates';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiEvents::QUERY_PRE_EXECUTE => 'onQueryPreExecute',
    ];
  }

  /**
   * Adds next-occurrence boost and dynamic geo origin.
   */
  public function onQueryPreExecute(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();

    if ($query->getIndex()->id() !== 'deals_solr') {
      return;
    }

    $backend = $query->getIndex()->getServerInstance()->getBackend();
    if ($backend->getPluginId() !== 'search_api_solr') {
      return;
    }

    $request = \Drupal::request();

    $origin_mode = (string) $request->query->get('search_origin_mode', '');
    $origin_lat = $request->query->get('origin_lat');
    $origin_lon = $request->query->get('origin_lon');
    $parsed_origin = $request->attributes->get('spotdeals_search_smart_location.origin');

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
      $source = 'parsed_place';
    }

    $solr_options = $query->getOption('search_api_solr_query') ?: [];
    if (empty($solr_options['bf'])) {
      $solr_options['bf'] = [];
    }

    if ($lat !== NULL && $lon !== NULL) {
      $location_options = (array) $query->getOption('search_api_location', []);
      $updated = FALSE;

      foreach ($location_options as &$location_option) {
        if (is_array($location_option) && ($location_option['field'] ?? '') === self::GEO_FIELD) {
          $location_option['lat'] = $lat;
          $location_option['lon'] = $lon;
          $location_option['radius'] = self::DEFAULT_RADIUS_KM;
          $updated = TRUE;
        }
      }
      unset($location_option);

      if (!$updated) {
        $location_options[] = [
          'field' => self::GEO_FIELD,
          'lat' => $lat,
          'lon' => $lon,
          'radius' => self::DEFAULT_RADIUS_KM,
        ];
      }

      $query->setOption('search_api_location', $location_options);

      // Strongly prefer nearer results inside the already-filtered local set.
      // geodist() uses the spatial params generated from search_api_location.
      $solr_options['bf'][] = 'recip(geodist(),1,1000,1000)';

      \Drupal::logger('spotdeals_search_smart_location')->notice(
        'SMART LOCATION subscriber applied geo origin: source="@source" lat="@lat" lon="@lon" geo_boost="on"',
        [
          '@source' => $source,
          '@lat' => (string) $lat,
          '@lon' => (string) $lon,
        ]
      );
    }
    else {
      \Drupal::logger('spotdeals_search_smart_location')->notice(
        'SMART LOCATION subscriber found no geo origin to apply.'
      );
    }

    // Keep fresher / sooner deals favored, but behind the local-distance boost.
    $solr_options['bf'][] = 'recip(ms(NOW,spotdeals_next_occurrence),3.16e-11,1,1)';

    $query->setOption('search_api_solr_query', $solr_options);
  }

}
