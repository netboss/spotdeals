<?php

/**
 * SpotDeals Search API / Views query audit.
 *
 * Usage:
 *   ddev drush php:script scripts/spotdeals_search_query_audit.php -- "happy hour"
 *   ddev drush php:script scripts/spotdeals_search_query_audit.php -- tacos
 */

use Drupal\views\Views;

$args = $_SERVER['argv'] ?? [];
$query = '';
$collect_next = FALSE;
foreach ($args as $arg) {
  if ($arg === '--') {
    $collect_next = TRUE;
    continue;
  }
  if ($collect_next && $arg !== '') {
    $query = $arg;
    break;
  }
}
if ($query === '') {
  foreach (array_reverse($args) as $arg) {
    if ($arg !== '' && $arg !== '--' && !str_ends_with($arg, '.php')) {
      $query = $arg;
      break;
    }
  }
}
if ($query === '') {
  $query = 'happy hour';
}

$view_id = 'deals_search_solr';
$display_id = 'page_1';
$index_id = 'deals_solr';

print "SpotDeals Search Query Audit\n";
print "query={$query}\n\n";

$config_factory = \Drupal::configFactory();

print "== Search API index config: {$index_id} ==\n";
$index_config = $config_factory->get("search_api.index.{$index_id}");
if ($index_config->isNew()) {
  print "Missing config search_api.index.{$index_id}\n\n";
}
else {
  print "server: " . (string) $index_config->get('server') . "\n";
  print "status: " . ((bool) $index_config->get('status') ? 'enabled' : 'disabled') . "\n";
  print "datasources: " . implode(', ', array_keys((array) $index_config->get('datasource_settings'))) . "\n\n";

  $fields = (array) $index_config->get('field_settings');
  ksort($fields);
  print "Indexed fields likely relevant to text search:\n";
  foreach ($fields as $field_id => $settings) {
    $label = (string) ($settings['label'] ?? '');
    $type = (string) ($settings['type'] ?? '');
    $property = (string) ($settings['property_path'] ?? '');
    if (
      str_contains($field_id, 'title') ||
      str_contains($field_id, 'body') ||
      str_contains($field_id, 'category') ||
      str_contains($field_id, 'cuisine') ||
      str_contains($field_id, 'tag') ||
      str_contains($field_id, 'venue') ||
      str_contains($property, 'title') ||
      str_contains($property, 'body') ||
      str_contains($property, 'field_')
    ) {
      print "- {$field_id} | type={$type} | label={$label} | property={$property}\n";
    }
  }
  print "\n";
}

print "== View config: {$view_id} / {$display_id} ==\n";
$view_config = $config_factory->get("views.view.{$view_id}");
if ($view_config->isNew()) {
  print "Missing config views.view.{$view_id}\n\n";
}
else {
  $display = $view_config->get("display.{$display_id}.display_options");
  if (!is_array($display)) {
    print "Missing display {$display_id}\n\n";
  }
  else {
    print "pager: " . json_encode($display['pager'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";

    print "filters:\n";
    foreach ((array) ($display['filters'] ?? []) as $id => $filter) {
      print "- {$id}: plugin=" . ($filter['plugin_id'] ?? '') . " expose=" . json_encode($filter['expose'] ?? NULL, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . " value=" . json_encode($filter['value'] ?? NULL, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
    print "\n";

    print "sorts:\n";
    foreach ((array) ($display['sorts'] ?? []) as $id => $sort) {
      print "- {$id}: plugin=" . ($sort['plugin_id'] ?? '') . " order=" . ($sort['order'] ?? '') . " field=" . ($sort['field'] ?? '') . "\n";
    }
    print "\n";
  }
}

print "== Executed View result sample ==\n";
$view = Views::getView($view_id);
if (!$view) {
  print "Could not load view {$view_id}\n";
  exit(1);
}

$request = \Drupal::request();
$request->query->set('search_deals', $query);
$request->query->set('search_clean', $query);
$request->query->set('search_raw', $query);
$request->query->set('origin_lat', '29.0210019');
$request->query->set('origin_lon', '-80.9772265');
$request->query->set('search_origin_mode', 'browser');
$request->query->set('spotdeals_debug_search', '1');

$view->setDisplay($display_id);
$view->setExposedInput([
  'search_deals' => $query,
  'search_clean' => $query,
  'search_raw' => $query,
  'origin_lat' => '29.0210019',
  'origin_lon' => '-80.9772265',
  'search_origin_mode' => 'browser',
]);
$view->preExecute();
$view->execute();

print "total_rows=" . (string) ($view->total_rows ?? 'unknown') . " result_count=" . count($view->result) . "\n";

$rows = [];
foreach (array_slice($view->result, 0, 20) as $position => $row) {
  $entity = $row->_entity ?? NULL;
  if (!$entity && isset($row->nid)) {
    $entity = \Drupal::entityTypeManager()->getStorage('node')->load((int) $row->nid);
  }
  $rows[] = [
    'pos' => $position + 1,
    'nid' => $entity ? (int) $entity->id() : NULL,
    'title' => $entity ? (string) $entity->label() : '',
    'type' => $entity ? (string) $entity->bundle() : '',
  ];
}
print json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";

print "== Recent spotdeals_search_debug rows ==\n";
$database = \Drupal::database();
if ($database->schema()->tableExists('watchdog')) {
  $result = $database->select('watchdog', 'w')
    ->fields('w', ['wid', 'message', 'variables'])
    ->condition('type', 'spotdeals_search_debug')
    ->orderBy('wid', 'DESC')
    ->range(0, 5)
    ->execute();

  foreach ($result as $record) {
    print "wid={$record->wid}\n";
    print "message={$record->message}\n";
    $vars = @unserialize($record->variables, ['allowed_classes' => FALSE]);
    if (is_array($vars)) {
      foreach (['@ranked_sample', '@rows', '@before', '@after', '@ranked', '@not_ranked', '@total_ranked_rows', '@visible_limit', '@candidate_pool'] as $key) {
        if (array_key_exists($key, $vars)) {
          $value = (string) $vars[$key];
          if (strlen($value) > 1200) {
            $value = substr($value, 0, 1200) . '...';
          }
          print "{$key}: {$value}\n";
        }
      }
    }
    print "\n";
  }
}
else {
  print "watchdog table not available.\n";
}
