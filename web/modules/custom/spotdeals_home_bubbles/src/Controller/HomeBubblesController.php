<?php

declare(strict_types=1);

namespace Drupal\spotdeals_home_bubbles\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Selects public conversation previews for the three homepage positions.
 */
final class HomeBubblesController extends ControllerBase {

  /**
   * Returns up to three distinct viewable conversations.
   */
  public function feed(Request $request): JsonResponse {
    $storage = $this->entityTypeManager()->getStorage('node');
    // Resolve the same one-box search that the Deals Finder actually uses.
    $city = trim(mb_substr((string) $request->query->get('city', ''), 0, 100));
    if ($city === '' && function_exists('_spotdeals_search_smart_location_parse')) {
      $raw = '';
      foreach (['search_raw', 'search_deals', 'search_api_fulltext', 'search_deals_by_city'] as $key) {
        $value = $request->query->get($key, '');
        if (is_string($value) && trim($value) !== '') {
          $raw = trim($value);
          break;
        }
      }
      if ($raw !== '') {
        $parsed = _spotdeals_search_smart_location_parse($raw);
        $slug = (string) ($parsed['city'] ?? '');
        if ($slug !== '' && function_exists('_spotdeals_search_smart_location_city_to_exact_locality')) {
          $city = (string) (_spotdeals_search_smart_location_city_to_exact_locality($slug) ?? '');
        }
      }
    }
    if ($city === '' && $request->query->get('search_origin_mode') === 'browser'
        && function_exists('_spotdeals_search_smart_location_nearest_city_from_coords')) {
        $lat = $request->query->get('origin_lat');
        $lon = $request->query->get('origin_lon');
        if (is_numeric($lat) && is_numeric($lon)
          && abs((float) $lat) <= 90 && abs((float) $lon) <= 180) {
          $city = (string) (_spotdeals_search_smart_location_nearest_city_from_coords((float) $lat, (float) $lon) ?? '');
        }
    }

    // Never show conversations from arbitrary cities when the visitor's
    // search or browser-location mode has not resolved a city.
    if ($city === '') {
      $response = new JsonResponse(['items' => []]);
      $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
      return $response;
    }

    // Only conversations in the resolved locality are eligible.
    $ids = array_values($storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'conversation')
      ->condition('status', 1)
      ->condition('field_address.locality', $city)
      ->sort('created', 'DESC')
      ->range(0, 150)
      ->execute());
    $db = \Drupal::database();
    $votes = [];
    $replies = [];
    foreach (array_chunk($ids, 200) as $chunk) {
      $result = $db->select('spotdeals_conversation_vote_totals', 'v')
        ->fields('v', ['nid', 'upvotes'])
        ->condition('nid', $chunk, 'IN')->execute();
      foreach ($result as $row) {
        $votes[(int) $row->nid] = (int) $row->upvotes;
      }
      $result = $db->select('comment_entity_statistics', 'c')
        ->fields('c', ['entity_id', 'comment_count'])
        ->condition('entity_type', 'node')
        ->condition('field_name', 'field_conversation_replies')
        ->condition('entity_id', $chunk, 'IN')->execute();
      foreach ($result as $row) {
        $replies[(int) $row->entity_id] = (int) $row->comment_count;
      }
    }
    // Votes and replies are verified engagement signals. Shares are not
    // included until an authoritative per-conversation share counter exists.
    $ids = \Drupal::service('spotdeals_home_bubbles.ranking')->rank($ids, $votes, $replies);

    $nodes = $storage->loadMultiple($ids);
    $eligible = [];
    foreach ($ids as $id) {
      $node = $nodes[$id] ?? NULL;
      if (!$node || !$node->access('view')) {
        continue;
      }
      $eligible[] = $node;
    }
    // Randomize within local and fallback pools, preserving local priority.
    // The front end independently chooses the visual treatment per placement.
    $items = [];
    foreach (array_slice($eligible, 0, 3) as $node) {
      $address = $node->hasField('field_address') && !$node->get('field_address')->isEmpty()
        ? $node->get('field_address')->first()->getValue() : [];
      $body = $node->hasField('field_conversation_body')
        ? (string) ($node->get('field_conversation_body')->value ?? '') : '';
      $items[] = [
        'id' => (int) $node->id(),
        'title' => $node->label(),
        'summary' => mb_strimwidth(trim(strip_tags($body)), 0, 135, '…'),
        'city' => (string) ($address['locality'] ?? ''),
        'url' => $node->toUrl()->toString(),
        'preview' => Url::fromRoute('spotdeals_conversations.inline_preview', ['node' => $node->id()])->toString(),
      ];
    }
    $response = new JsonResponse(['items' => $items]);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    return $response;
  }

}
