<?php

namespace Drupal\spotdeals_search_smart_location\EventSubscriber;

use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Alters Search API Solr queries to add balanced next-occurrence boosting.
 */
class SearchApiSolrSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      SearchApiEvents::QUERY_PRE_EXECUTE => 'onQueryPreExecute',
    ];
  }

  /**
   * Adds a balanced recency boost for deals happening sooner.
   */
  public function onQueryPreExecute(QueryPreExecuteEvent $event) {
    $query = $event->getQuery();

    // Only target the deals index.
    if ($query->getIndex()->id() !== 'deals_solr') {
      return;
    }

    // Only target Solr-backed indexes.
    $backend = $query->getIndex()->getServerInstance()->getBackend();
    if ($backend->getPluginId() !== 'search_api_solr') {
      return;
    }

    $boost_function = 'recip(ms(NOW,spotdeals_next_occurrence),3.16e-11,1,1)';

    $solr_options = $query->getOption('search_api_solr_query') ?: [];

    if (empty($solr_options['bf'])) {
      $solr_options['bf'] = [];
    }

    $solr_options['bf'][] = $boost_function;

    $query->setOption('search_api_solr_query', $solr_options);
  }

}
