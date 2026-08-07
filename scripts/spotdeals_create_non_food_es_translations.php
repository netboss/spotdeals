<?php

/**
 * Creates missing Spanish translations for non-food CSV migration nodes.
 *
 * This script is intentionally limited to destination node IDs recorded by:
 * - spotdeals_non_food_venues
 * - spotdeals_non_food_deals
 *
 * Run locally:
 *   ddev drush php:script scripts/spotdeals_create_non_food_es_translations.php
 *
 * Run in production:
 *   drush php:script scripts/spotdeals_create_non_food_es_translations.php
 */

declare(strict_types=1);

use Drupal\node\NodeInterface;
use Drupal\spotdeals_data_ingestion\Service\SpanishNodeTranslationCreator;

$database = \Drupal::database();
$entity_type_manager = \Drupal::entityTypeManager();
$state = \Drupal::state();
$config_factory = \Drupal::configFactory();

/** @var \Drupal\spotdeals_data_ingestion\Service\SpanishNodeTranslationCreator $translation_creator */
$translation_creator = \Drupal::service(
  SpanishNodeTranslationCreator::class,
);

$migration_ids = [
  'spotdeals_non_food_venues',
  'spotdeals_non_food_deals',
];

$suppression_state_keys = [
  'spotdeals_bulk_operation_active',
  'spotdeals_create_missing_es_translations_active',
  'spotdeals_suppress_admin_notifications',
  'spotdeals_suppress_owner_notifications',
  'spotdeals_disable_admin_notifications',
];

$original_state_values = [];
foreach ($suppression_state_keys as $key) {
  $original_state_values[$key] = $state->get($key);
  $state->set($key, TRUE);
}

$mail_config = $config_factory->getEditable('system.mail');
$original_mail_interface = $mail_config->get('interface') ?? [];
$mail_config
  ->set('interface.default', 'test_mail_collector')
  ->save();

$restored = FALSE;

$restore_environment = static function () use (
  &$restored,
  $state,
  $suppression_state_keys,
  $original_state_values,
  $config_factory,
  $original_mail_interface,
): void {
  if ($restored) {
    return;
  }

  $config_factory
    ->getEditable('system.mail')
    ->set('interface', $original_mail_interface)
    ->save();

  foreach ($suppression_state_keys as $key) {
    if ($original_state_values[$key] === NULL) {
      $state->delete($key);
    }
    else {
      $state->set($key, $original_state_values[$key]);
    }
  }

  $restored = TRUE;
};

register_shutdown_function($restore_environment);

$node_storage = $entity_type_manager->getStorage('node');

$created = 0;
$already_exists = 0;
$missing_nodes = 0;
$failed = 0;

print "Creating Spanish translations for non-food migration nodes...\n\n";

try {
  foreach ($migration_ids as $migration_id) {
    $map_table = 'migrate_map_' . $migration_id;

    if (!$database->schema()->tableExists($map_table)) {
      throw new RuntimeException(
        "Required migration map table does not exist: {$map_table}",
      );
    }

    $nids = array_values(array_unique(array_map(
      'intval',
      $database->select($map_table, 'm')
        ->fields('m', ['destid1'])
        ->isNotNull('destid1')
        ->execute()
        ->fetchCol(),
    )));

    sort($nids, SORT_NUMERIC);

    print "{$migration_id}: " . count($nids) . " mapped node(s)\n";

    foreach ($nids as $nid) {
      $node = $node_storage->load($nid);

      if (!$node instanceof NodeInterface) {
        $missing_nodes++;
        print "  MISSING node {$nid}\n";
        continue;
      }

      if ($node->hasTranslation('es')) {
        $already_exists++;
        print "  EXISTS {$nid} | {$node->label()}\n";
        continue;
      }

      try {
        $translation_creator->ensureSpanishTranslation($node);
        $created++;
        print "  CREATED {$nid} | {$node->label()}\n";
      }
      catch (Throwable $exception) {
        $failed++;
        print "  FAILED {$nid} | {$node->label()} | {$exception->getMessage()}\n";
      }
    }

    print "\n";
  }
}
finally {
  $restore_environment();
}

print "Summary\n";
print "-------\n";
print "Created: {$created}\n";
print "Already existed: {$already_exists}\n";
print "Missing nodes: {$missing_nodes}\n";
print "Failed: {$failed}\n";

if ($failed > 0 || $missing_nodes > 0) {
  throw new RuntimeException(
    'Spanish translation creation completed with errors.',
  );
}

print "\nSpanish translation creation completed successfully.\n";
