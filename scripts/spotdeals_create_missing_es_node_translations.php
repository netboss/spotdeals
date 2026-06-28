<?php

declare(strict_types=1);

use Drupal\node\NodeInterface;

$source_langcode = 'en';
$target_langcode = 'es';
$dry_run = in_array('--dry-run', $_SERVER['argv'] ?? [], TRUE);
$disable_mail = !in_array('--allow-mail', $_SERVER['argv'] ?? [], TRUE);

$storage = \Drupal::entityTypeManager()->getStorage('node');
$config_factory = \Drupal::configFactory();
$mail_config = $config_factory->getEditable('system.mail');
$original_mail_interface = $mail_config->get('interface') ?? [];

if ($disable_mail && !$dry_run) {
  $mail_config
    ->set('interface.default', 'test_mail_collector')
    ->save();

  echo "Mail disabled during script: yes\n";
}
else {
  echo "Mail disabled during script: no\n";
}

$restore_mail = static function () use ($disable_mail, $dry_run, $config_factory, $original_mail_interface): void {
  if ($disable_mail && !$dry_run) {
    $config_factory
      ->getEditable('system.mail')
      ->set('interface', $original_mail_interface)
      ->save();

    echo "\nMail configuration restored.\n";
  }
};

register_shutdown_function($restore_mail);

$nids = \Drupal::entityQuery('node')
  ->accessCheck(FALSE)
  ->condition('langcode', $source_langcode)
  ->execute();

$total = count($nids);
$created = 0;
$skipped = 0;
$failed = 0;

echo "Source language: {$source_langcode}\n";
echo "Target language: {$target_langcode}\n";
echo "Dry run: " . ($dry_run ? 'yes' : 'no') . "\n";
echo "Nodes found: {$total}\n\n";

foreach (array_chunk($nids, 25) as $chunk) {
  $nodes = $storage->loadMultiple($chunk);

  foreach ($nodes as $node) {
    if (!$node instanceof NodeInterface) {
      continue;
    }

    $nid = $node->id();
    $title = $node->label();

    if ($node->hasTranslation($target_langcode)) {
      $skipped++;
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
  }

  $storage->resetCache($chunk);
}

echo "\nDone.\n";
echo "Total: {$total}\n";
echo "Created: {$created}\n";
echo "Skipped: {$skipped}\n";
echo "Failed: {$failed}\n";
