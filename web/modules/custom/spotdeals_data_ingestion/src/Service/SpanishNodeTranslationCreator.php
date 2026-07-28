<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use Drupal\pathauto\PathautoGeneratorInterface;

/**
 * Creates Spanish node translations by copying the English source values.
 */
final class SpanishNodeTranslationCreator {

  private const SOURCE_LANGCODE = 'en';
  private const TARGET_LANGCODE = 'es';

  private const EXCLUDED_FIELDS = [
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
  ];

  public function __construct(
    private readonly TimeInterface $time,
    private readonly PathautoGeneratorInterface $pathautoGenerator,
  ) {}

  /**
   * Adds the Spanish translation when it does not already exist.
   *
   * The translation intentionally copies the English field values unchanged.
   * Venue owners can translate the content later. The path field is excluded
   * so Drupal/Pathauto can generate a language-specific alias.
   *
   * @return bool
   *   TRUE when a translation was created, FALSE when it already existed.
   */
  public function ensureSpanishTranslation(NodeInterface $node): bool {
    if ($node->hasTranslation(self::TARGET_LANGCODE)) {
      return FALSE;
    }

    $source = $node->hasTranslation(self::SOURCE_LANGCODE)
      ? $node->getTranslation(self::SOURCE_LANGCODE)
      : $node;

    $values = [];

    foreach ($source->getFields() as $fieldName => $field) {
      $definition = $field->getFieldDefinition();

      if ($definition->isComputed() || $definition->isReadOnly()) {
        continue;
      }

      if (in_array($fieldName, self::EXCLUDED_FIELDS, TRUE)) {
        continue;
      }

      $values[$fieldName] = $field->getValue();
    }

    $translation = $node->addTranslation(self::TARGET_LANGCODE, $values);
    $translation->setTitle($source->label());

    if ($translation->hasField('content_translation_source')) {
      $translation->set('content_translation_source', self::SOURCE_LANGCODE);
    }

    if ($translation->hasField('content_translation_outdated')) {
      $translation->set('content_translation_outdated', 0);
    }

    if ($translation->hasField('content_translation_uid')) {
      $translation->set('content_translation_uid', 0);
    }

    if ($translation->hasField('content_translation_created')) {
      $translation->set(
        'content_translation_created',
        $this->time->getRequestTime(),
      );
    }

    $node->setNewRevision(FALSE);
    $node->save();

    // Generate the alias explicitly for the new Spanish translation. The
    // normal node save only generated the source-language alias in testing.
    $this->pathautoGenerator->updateEntityAlias(
      $node->getTranslation(self::TARGET_LANGCODE),
      'insert',
    );

    return TRUE;
  }

}
