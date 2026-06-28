<?php

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

$limit = (int) ($get_option_value('--limit', '100') ?? 100);
$chunk_size = (int) ($get_option_value('--chunk-size', '25') ?? 25);
$progress_every = (int) ($get_option_value('--progress-every', '25') ?? 25);
$start_after = (int) ($get_option_value('--start-after', '0') ?? 0);
$type = $get_option_value('--type');

if ($limit < 1) {
  $limit = 100;
}

if ($chunk_size < 1 || $chunk_size > 100) {
  $chunk_size = 25;
}

if ($progress_every < 1) {
  $progress_every = 25;
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
$original_bulk_state = $state->get('spotdeals_bulk_operation_active');

if ($disable_mail && !$dry_run) {
  $mail_config
    ->set('interface.default', 'test_mail_collector')
    ->save();

  $state->set('spotdeals_bulk_operation_active', TRUE);

  echo "Mail disabled during script: yes\n";
}
else {
  echo "Mail disabled during script: no\n";
}

$restore_state = static function () use (
  $disable_mail,
  $dry_run,
  $config_factory,
  $original_mail_interface,
  $state,
  $original_bulk_state,
): void {
  if ($disable_mail && !$dry_run) {
    $config_factory
      ->getEditable('system.mail')
      ->set('interface', $original_mail_interface)
      ->save();

    if ($original_bulk_state === NULL) {
      $state->delete('spotdeals_bulk_operation_active');
    }
    else {
      $state->set('spotdeals_bulk_operation_active', $original_bulk_state);
    }

    echo "\nMail configuration restored.\n";
  }
};

register_shutdown_function($restore_state);

$count_query = $database->select('node_field_data', 'n');
$count_query->leftJoin('node_field_data', 'e', 'e.nid = n.nid AND e.langcode = :target_langcode', [
  ':target_langcode' => $target_langcode,
]);
$count_query
  ->condition('n.langcode', $source_langcode)
  ->isNull('e.nid')
  ->condition('n.nid', $start_after, '>');

if ($type !== NULL) {
  $count_query->condition('n.type', $type);
}

$total_missing = (int) $count_query
  ->countQuery()
  ->execute()
  ->fetchField();

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
  ->range(0, $limit);

if ($type !== NULL) {
  $query->condition('n.type', $type);
}

$nids = array_map('intval', $query->execute()->fetchCol());

$total_selected = count($nids);
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
echo "Limit: {$limit}\n";
echo "Chunk size: {$chunk_size}\n";
echo "Total missing before this run: {$total_missing}\n";
echo "Selected for this run: {$total_selected}\n\n";

if ($total_selected === 0) {
  echo "Nothing to process.\n";
  return;
}

foreach (array_chunk($nids, $chunk_size) as $chunk) {
  $nodes = $storage->loadMultiple($chunk);

  foreach ($chunk as $nid) {
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
      echo "PROGRESS {$processed}/{$total_selected} processed; created={$created}; skipped={$skipped}; failed={$failed}; elapsed={$elapsed}s\n";
    }
  }

  $storage->resetCache($chunk);
  gc_collect_cycles();
}

echo "\nDone.\n";
echo "Total missing before this run: {$total_missing}\n";
echo "Selected: {$total_selected}\n";
echo "Processed: {$processed}\n";
echo "Created: {$created}\n";
echo "Skipped: {$skipped}\n";
echo "Failed: {$failed}\n";
