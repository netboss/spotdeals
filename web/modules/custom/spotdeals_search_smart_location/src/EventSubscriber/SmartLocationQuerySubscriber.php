<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location\EventSubscriber;

use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes the one-box deals search location-aware.
 */
final class SmartLocationQuerySubscriber implements EventSubscriberInterface {

  /**
   * Exact city phrases we want to detect.
   *
   * Key = phrase to detect in normalized input.
   * Value = exact value stored in the Search API field.
   */
  private const CITY_MAP = [
    'daytona beach shores' => 'Daytona Beach Shores',
    'new smyrna beach' => 'New Smyrna Beach',
    'daytona beach' => 'Daytona Beach',
    'ormond beach' => 'Ormond Beach',
    'port orange' => 'Port Orange',
    'orange city' => 'Orange City',
    'south daytona' => 'South Daytona',
    'ponce inlet' => 'Ponce Inlet',
    'holly hill' => 'Holly Hill',
    'oak hill' => 'Oak Hill',
    'debary' => 'DeBary',
    'debary fl' => 'DeBary',
    'deland' => 'DeLand',
    'de land' => 'DeLand',
    'deltona' => 'Deltona',
    'edgewater' => 'Edgewater',
  ];

  /**
   * Optional aliases.
   */
  private const CITY_ALIASES = [
    'nsb' => 'New Smyrna Beach',
    'ob' => 'Ormond Beach',
    'po' => 'Port Orange',
    'db' => 'Daytona Beach',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiEvents::QUERY_PRE_EXECUTE => 'onQueryPreExecute',
    ];
  }

  /**
   * Alters the deals query before Solr executes it.
   */
  public function onQueryPreExecute(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();

    // Only touch the Deals Solr index.
    if ($query->getIndex()->id() !== 'deals_solr') {
      return;
    }

    $keys = $query->getKeys();

    // Only handle plain string searches.
    if (!is_string($keys) || trim($keys) === '') {
      return;
    }

    $parsed = $this->parseLocationAwareQuery($keys);

    // If nothing location-like was found, do nothing.
    if ($parsed['zip'] === NULL && $parsed['city'] === NULL) {
      return;
    }

    // Apply exact filters.
    if ($parsed['zip'] !== NULL) {
      $query->addCondition('postal_code_exact', $parsed['zip']);
    }

    if ($parsed['city'] !== NULL) {
      $query->addCondition('locality_exact', $parsed['city']);
    }

    // Keep only the non-location keywords in the free-text query.
    $remaining = trim($parsed['keywords']);

    if ($remaining !== '') {
      $query->keys($remaining);
    }
    else {
      // If user only searched a city or ZIP, rely on filters only.
      $query->keys(NULL);
    }
  }

  /**
   * Splits the one-box query into keywords + optional ZIP + optional city.
   */
  private function parseLocationAwareQuery(string $input): array {
    $working = trim($input);
    $normalized = $this->normalize($working);

    $zip = NULL;
    $city = NULL;

    // 1) ZIP code detection.
    if (preg_match('/\b(\d{5})\b/', $working, $matches)) {
      $zip = $matches[1];
      $working = preg_replace('/\b' . preg_quote($zip, '/') . '\b/i', ' ', $working, 1) ?? $working;
      $normalized = $this->normalize($working);
    }

    // 2) Full city phrases first, longest first.
    $city_map = self::CITY_MAP;
    uksort($city_map, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($city_map as $needle => $canonical_city) {
      if (preg_match('/\b' . preg_quote($needle, '/') . '\b/i', $normalized)) {
        $city = $canonical_city;
        $working = preg_replace('/\b' . preg_quote($needle, '/') . '\b/i', ' ', $working, 1) ?? $working;
        $normalized = $this->normalize($working);
        break;
      }
    }

    // 3) Short aliases like NSB, PO, DB if no full city already matched.
    if ($city === NULL) {
      foreach (self::CITY_ALIASES as $alias => $canonical_city) {
        if (preg_match('/\b' . preg_quote($alias, '/') . '\b/i', $normalized)) {
          $city = $canonical_city;
          $working = preg_replace('/\b' . preg_quote($alias, '/') . '\b/i', ' ', $working, 1) ?? $working;
          break;
        }
      }
    }

    $keywords = trim(preg_replace('/\s+/', ' ', $working) ?? '');

    return [
      'keywords' => $keywords,
      'zip' => $zip,
      'city' => $city,
    ];
  }

  /**
   * Normalizes text for matching.
   */
  private function normalize(string $value): string {
    $value = mb_strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
  }

}
