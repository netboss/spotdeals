<?php

/**
 * SpotDeals - Create missing Spanish node translations.
 *
 * Run AFTER importing venues and deals.
 *
 * Local:
 *   ddev drush php:script scripts/spotdeals_create_missing_es_node_translations.php
 *
 * Production:
 *   drush php:script scripts/spotdeals_create_missing_es_node_translations.php
 *
 * Safer production example:
 *   drush php:script scripts/spotdeals_create_missing_es_node_translations.php -- --chunk-size=10 --progress-every=10 --min-free-gb=10 --max-runtime=5400
 */

declare(strict_types=1);

use Drupal\node\NodeInterface;

$source_langcode = 'en';
$target_langcode = 'es';

$args = $_SERVER['argv'] ?? [];

$has_option = static function (string $name) use ($args): bool {
  return in_array($name, $args, TRUE);
};

$get_option_value = static function (string $name, ?string $default = NULL) use ($args): ?string {
  foreach ($args as $arg) {
    if (str_starts_with($arg, $name . '=')) {
      return substr($arg, strlen($name) + 1);
    }
  }

  return $default;
};

$dry_run = $has_option('--dry-run');
$disable_mail = !$has_option('--allow-mail');
$purge_binlogs = $has_option('--purge-binlogs');

$limit_option = $get_option_value('--limit');
$limit = $limit_option === NULL ? NULL : (int) $limit_option;
$chunk_size = (int) ($get_option_value('--chunk-size', '10') ?? 10);
$progress_every = (int) ($get_option_value('--progress-every', '10') ?? 10);
$binlog_maintenance_every = (int) ($get_option_value('--binlog-maintenance-every', '0') ?? 0);
$start_after = (int) ($get_option_value('--start-after', '0') ?? 0);
$max_runtime = (int) ($get_option_value('--max-runtime', '0') ?? 0);
$min_free_gb = (float) ($get_option_value('--min-free-gb', '5') ?? 5);
$sleep_seconds = (int) ($get_option_value('--sleep', '0') ?? 0);
$type = $get_option_value('--type');

if ($limit !== NULL && $limit < 1) {
  throw new \InvalidArgumentException('Invalid --limit value.');
}

if ($chunk_size < 1 || $chunk_size > 50) {
  $chunk_size = 10;
}

if ($progress_every < 1) {
  $progress_every = 10;
}

if ($binlog_maintenance_every < 0) {
  $binlog_maintenance_every = 0;
}

if ($max_runtime < 0) {
  $max_runtime = 0;
}

if ($min_free_gb < 1) {
  $min_free_gb = 5;
}

if ($sleep_seconds < 0 || $sleep_seconds > 30) {
  $sleep_seconds = 0;
}

if ($type !== NULL && !in_array($type, ['deal', 'venue'], TRUE)) {
  throw new \InvalidArgumentException('Invalid --type value. Allowed values: deal, venue.');
}

$storage = \Drupal::entityTypeManager()->getStorage('node');
$database = \Drupal::database();
$config_factory = \Drupal::configFactory();
$state = \Drupal::state();

$mail_config = $config_factory->getEditable('system.mail');
$original_mail_interface = $mail_config->get('interface') ?? [];

$bulk_state_keys = [
  'spotdeals_bulk_operation_active',
  'spotdeals_create_missing_es_translations_active',
  'spotdeals_suppress_admin_notifications',
  'spotdeals_suppress_owner_notifications',
  'spotdeals_disable_admin_notifications',
];

$original_state_values = [];
foreach ($bulk_state_keys as $key) {
  $original_state_values[$key] = $state->get($key);
}

if ($disable_mail && !$dry_run) {
  $mail_config
    ->set('interface.default', 'test_mail_collector')
    ->save();

  foreach ($bulk_state_keys as $key) {
    $state->set($key, TRUE);
  }

  echo "Mail disabled during script: yes\n";
  echo "Bulk/suppression states enabled: yes\n";
}
else {
  echo "Mail disabled during script: no\n";
  echo "Bulk/suppression states enabled: no\n";
}

$restore_state = static function () use (
  $disable_mail,
  $dry_run,
  $config_factory,
  $original_mail_interface,
  $state,
  $bulk_state_keys,
  $original_state_values,
): void {
  if ($disable_mail && !$dry_run) {
    $config_factory
      ->getEditable('system.mail')
      ->set('interface', $original_mail_interface)
      ->save();

    foreach ($bulk_state_keys as $key) {
      if ($original_state_values[$key] === NULL) {
        $state->delete($key);
      }
      else {
        $state->set($key, $original_state_values[$key]);
      }
    }

    echo "\nMail configuration restored.\n";
    echo "Bulk/suppression states restored.\n";
  }
};

register_shutdown_function($restore_state);

$get_free_gb = static function (): float {
  $free_bytes = disk_free_space('/');
  if ($free_bytes === FALSE) {
    return 0.0;
  }

  return $free_bytes / 1024 / 1024 / 1024;
};

$assert_disk_space = static function () use ($get_free_gb, $min_free_gb): void {
  $free_gb = $get_free_gb();

  if ($free_gb < $min_free_gb) {
    throw new \RuntimeException(sprintf(
      'Stopping safely: free disk space is %.2f GB, below required %.2f GB.',
      $free_gb,
      $min_free_gb
    ));
  }
};

$run_binlog_maintenance = static function () use ($purge_binlogs): void {
  if (!$purge_binlogs) {
    return;
  }

  echo "Running MySQL binlog maintenance...\n";

  $current_binlog = trim((string) shell_exec("mysql -N -e \"SHOW MASTER STATUS\" | awk '{print $1}'"));

  if ($current_binlog === '') {
    echo "WARNING: Could not determine current MySQL binlog. Skipping purge.\n";
    return;
  }

  $command = 'mysql -e ' . escapeshellarg("PURGE BINARY LOGS TO '{$current_binlog}';");
  passthru($command, $exit_code);

  if ($exit_code === 0) {
    echo "MySQL binlog purge completed up to {$current_binlog}.\n";
  }
  else {
    echo "WARNING: MySQL binlog purge failed with exit code {$exit_code}. Continuing.\n";
  }
};

$count_missing = static function () use ($database, $source_langcode, $target_langcode, $type, $start_after): int {
  $query = $database->select('node_field_data', 'n');
  $query->leftJoin('node_field_data', 'e', 'e.nid = n.nid AND e.langcode = :target_langcode', [
    ':target_langcode' => $target_langcode,
  ]);

  $query
    ->condition('n.langcode', $source_langcode)
    ->isNull('e.nid')
    ->condition('n.nid', $start_after, '>');

  if ($type !== NULL) {
    $query->condition('n.type', $type);
  }

  return (int) $query
    ->countQuery()
    ->execute()
    ->fetchField();
};

$load_next_nids = static function (int $range) use ($database, $source_langcode, $target_langcode, $type, $start_after): array {
  $query = $database->select('node_field_data', 'n');
  $query->leftJoin('node_field_data', 'e', 'e.nid = n.nid AND e.langcode = :target_langcode', [
    ':target_langcode' => $target_langcode,
  ]);

  $query
    ->fields('n', ['nid'])
    ->condition('n.langcode', $source_langcode)
    ->isNull('e.nid')
    ->condition('n.nid', $start_after, '>')
    ->orderBy('n.nid', 'ASC')
    ->range(0, $range);

  if ($type !== NULL) {
    $query->condition('n.type', $type);
  }

  return array_map('intval', $query->execute()->fetchCol());
};

$total_missing = $count_missing();
$created = 0;
$skipped = 0;
$failed = 0;
$processed = 0;
$started_at = microtime(TRUE);

echo "Source language: {$source_langcode}\n";
echo "Target language: {$target_langcode}\n";
echo "Dry run: " . ($dry_run ? 'yes' : 'no') . "\n";
echo "Type filter: " . ($type ?? 'all') . "\n";
echo "Start after nid: {$start_after}\n";
echo "Limit: " . ($limit === NULL ? 'all' : (string) $limit) . "\n";
echo "Chunk size: {$chunk_size}\n";
echo "Progress every: {$progress_every}\n";
echo "Min free disk GB: {$min_free_gb}\n";
echo "Max runtime seconds: " . ($max_runtime === 0 ? 'unlimited' : (string) $max_runtime) . "\n";
echo "Sleep seconds between chunks: {$sleep_seconds}\n";
echo "Purge binlogs: " . ($purge_binlogs ? 'yes' : 'no') . "\n";
echo "Binlog maintenance every: {$binlog_maintenance_every} processed node(s)\n";
echo "Total missing before this run: {$total_missing}\n\n";

if ($total_missing === 0) {
  echo "Nothing to process.\n";
  return;
}

try {
  $assert_disk_space();

  while (TRUE) {
    if ($limit !== NULL && $processed >= $limit) {
      echo "Reached --limit={$limit}. Stopping.\n";
      break;
    }

    if ($max_runtime > 0 && (microtime(TRUE) - $started_at) >= $max_runtime) {
      echo "Reached --max-runtime={$max_runtime}. Stopping safely.\n";
      break;
    }

    $remaining_limit = $limit === NULL ? $chunk_size : max(0, min($chunk_size, $limit - $processed));
    if ($remaining_limit < 1) {
      break;
    }

    $assert_disk_space();

    $nids = $load_next_nids($remaining_limit);

    if ($nids === []) {
      echo "No more missing translations found.\n";
      break;
    }

    $nodes = $storage->loadMultiple($nids);

    foreach ($nids as $nid) {
      $node = $nodes[$nid] ?? NULL;

      if (!$node instanceof NodeInterface) {
        $failed++;
        $processed++;
        echo "FAIL {$nid}: node could not be loaded\n";
        continue;
      }

      $title = $node->label();

      if ($node->hasTranslation($target_langcode)) {
        $skipped++;
        $processed++;
        echo "SKIP {$nid}: already has {$target_langcode} translation - {$title}\n";
        continue;
      }

      try {
        if (!$dry_run) {
          $source = $node->hasTranslation($source_langcode)
            ? $node->getTranslation($source_langcode)
            : $node;

          $values = [];

          foreach ($source->getFields() as $field_name => $field) {
            $definition = $field->getFieldDefinition();

            if ($definition->isComputed() || $definition->isReadOnly()) {
              continue;
            }

            if (in_array($field_name, [
              'nid',
              'vid',
              'uuid',
              'langcode',
              'revision_id',
              'revision_translation_affected',
              'revision_created',
              'revision_user',
              'revision_log',
              'content_translation_source',
              'content_translation_outdated',
              'content_translation_uid',
              'content_translation_created',
              'path',
            ], TRUE)) {
              continue;
            }

            $values[$field_name] = $field->getValue();
          }

          $translation = $node->addTranslation($target_langcode, $values);
          $translation->setTitle($source->label());

          if ($translation->hasField('content_translation_source')) {
            $translation->set('content_translation_source', $source_langcode);
          }

          if ($translation->hasField('content_translation_outdated')) {
            $translation->set('content_translation_outdated', 0);
          }

          if ($translation->hasField('content_translation_uid')) {
            $translation->set('content_translation_uid', 0);
          }

          if ($translation->hasField('content_translation_created')) {
            $translation->set('content_translation_created', \Drupal::time()->getRequestTime());
          }

          $node->setNewRevision(FALSE);
          $node->save();
        }

        $created++;
        echo "CREATE {$nid}: {$target_langcode} translation - {$title}\n";
      }
      catch (\Throwable $e) {
        $failed++;
        echo "FAIL {$nid}: {$title} - {$e->getMessage()}\n";
      }

      $processed++;

      if ($processed % $progress_every === 0) {
        $elapsed = max(1, (int) (microtime(TRUE) - $started_at));
        $free_gb = $get_free_gb();
        $remaining = $count_missing();

        echo "PROGRESS {$processed} processed; created={$created}; skipped={$skipped}; failed={$failed}; remaining={$remaining}; free_disk_gb=" . number_format($free_gb, 2) . "; elapsed={$elapsed}s\n";
      }

      if (!$dry_run && $binlog_maintenance_every > 0 && $processed % $binlog_maintenance_every === 0) {
        $run_binlog_maintenance();
      }

      if ($max_runtime > 0 && (microtime(TRUE) - $started_at) >= $max_runtime) {
        echo "Reached --max-runtime={$max_runtime}. Stopping safely after current node.\n";
        break 2;
      }
    }

    $storage->resetCache($nids);
    gc_collect_cycles();

    if ($sleep_seconds > 0) {
      sleep($sleep_seconds);
    }
  }
}
catch (\Throwable $e) {
  echo "\nSTOPPED: {$e->getMessage()}\n";
}

$remaining = $count_missing();
$elapsed = max(1, (int) (microtime(TRUE) - $started_at));
$free_gb = $get_free_gb();

echo "\nDone.\n";
echo "Total missing before this run: {$total_missing}\n";
echo "Processed: {$processed}\n";
echo "Created: {$created}\n";
echo "Skipped: {$skipped}\n";
echo "Failed: {$failed}\n";
echo "Remaining missing after this run: {$remaining}\n";
echo "Free disk GB: " . number_format($free_gb, 2) . "\n";
echo "Elapsed seconds: {$elapsed}\n";
