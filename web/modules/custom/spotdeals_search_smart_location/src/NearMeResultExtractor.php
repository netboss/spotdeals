<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location;

use Drupal\node\NodeInterface;

/**
 * Extracts normalized venue location data for near-me ranking.
 */
final class NearMeResultExtractor {

  /**
   * Extracts [lat, lon] from a Venue node.
   *
   * @return array<int, float>|null
   *   Coordinates or NULL.
   */
  public function extractVenueCoords(NodeInterface $node): ?array {
    if ($node->hasField('field_coordinates') && !$node->get('field_coordinates')->isEmpty()) {
      $raw = trim((string) $node->get('field_coordinates')->value);

      if (preg_match('/POINT\s*\(\s*(-?[0-9.]+)\s+(-?[0-9.]+)\s*\)/i', $raw, $matches)) {
        $lon = (float) $matches[1];
        $lat = (float) $matches[2];
        return [$lat, $lon];
      }
    }

    if (
      $node->hasField('field_latitude') &&
      !$node->get('field_latitude')->isEmpty() &&
      $node->hasField('field_longitude') &&
      !$node->get('field_longitude')->isEmpty()
    ) {
      $lat = $node->get('field_latitude')->value;
      $lon = $node->get('field_longitude')->value;

      if (is_numeric($lat) && is_numeric($lon)) {
        return [(float) $lat, (float) $lon];
      }
    }

    return NULL;
  }

}
