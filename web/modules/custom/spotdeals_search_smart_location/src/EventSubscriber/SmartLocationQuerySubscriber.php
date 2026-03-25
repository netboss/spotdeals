<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location\EventSubscriber;

use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps Search API query execution stable for smart-location search.
 *
 * Smart location parsing is handled in:
 *   spotdeals_search_smart_location.module
 *
 * This subscriber intentionally does nothing so we avoid double parsing and
 * double-filtering the same one-box query in two different places.
 */
final class SmartLocationQuerySubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiEvents::QUERY_PRE_EXECUTE => 'onQueryPreExecute',
    ];
  }

  /**
   * Leaves the Search API query untouched.
   */
  public function onQueryPreExecute(QueryPreExecuteEvent $event): void {
    // Intentionally no-op.
  }

}
