<?php

namespace Drupal\spotdeals_search_smart_location\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Provides a computed next occurrence field.
 *
 * @SearchApiProcessor(
 *   id = "spotdeals_next_occurrence",
 *   label = @Translation("SpotDeals next occurrence"),
 *   description = @Translation("Adds a computed next occurrence datetime field."),
 *   stages = {
 *     "add_properties" = 0,
 *     "preprocess_index" = 0
 *   }
 * )
 */
class NextOccurrenceProcessor extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if ($datasource) {
      return $properties;
    }

    $properties['spotdeals_next_occurrence'] = new ProcessorProperty([
      'label' => $this->t('Next occurrence datetime'),
      'description' => $this->t('Computed next occurrence datetime for ranking.'),
      'type' => 'date',
      'processor_id' => $this->getPluginId(),
    ]);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessIndexItems(array $items) {
    foreach ($items as $item) {
      $this->addNextOccurrenceValue($item);
    }
  }

  /**
   * Adds computed next occurrence to the indexed item.
   */
  protected function addNextOccurrenceValue(ItemInterface $item) {
    $original = $item->getOriginalObject();
    if (!$original) {
      return;
    }

    $entity = $original->getValue();
    if (!$entity instanceof EntityInterface) {
      return;
    }

    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'deal') {
      return;
    }

    $next_occurrence = $this->computeNextOccurrence($entity);
    if (!$next_occurrence) {
      return;
    }

    foreach ($item->getFields(FALSE) as $field) {
      if ($field->getPropertyPath() === 'spotdeals_next_occurrence') {
        $field->addValue($next_occurrence->format('c'));
      }
    }
  }

  /**
   * Computes the next occurrence datetime for a deal.
   *
   * Uses:
   * - field_day_of_week taxonomy term labels
   * - field_start_time first time in the range (e.g. "5pm – 9pm")
   *
   * Returns a UTC DateTimeImmutable.
   */
  protected function computeNextOccurrence(EntityInterface $entity) {
    if (
      !$entity->hasField('field_start_time') ||
      $entity->get('field_start_time')->isEmpty() ||
      !$entity->hasField('field_day_of_week') ||
      $entity->get('field_day_of_week')->isEmpty()
    ) {
      return NULL;
    }

    $raw_start_time = (string) $entity->get('field_start_time')->value;
    $time_parts = $this->parseStartTime($raw_start_time);
    $allowed_weekdays = $this->getAllowedWeekdays($entity);

    $timezone_name = \Drupal::config('system.date')->get('timezone.default');
    if (empty($timezone_name)) {
      $timezone_name = date_default_timezone_get() ?: 'UTC';
    }

    $site_timezone = new \DateTimeZone($timezone_name);
    $utc_timezone = new \DateTimeZone('UTC');
    $now = new \DateTimeImmutable('now', $site_timezone);

    if (!$time_parts || !$allowed_weekdays) {
      return NULL;
    }

    // Search the next 14 days for the first valid occurrence.
    for ($offset = 0; $offset <= 13; $offset++) {
      $candidate_date = $now->modify('+' . $offset . ' day');
      $weekday = (int) $candidate_date->format('N');

      if (!in_array($weekday, $allowed_weekdays, TRUE)) {
        continue;
      }

      $candidate = $candidate_date->setTime(
        $time_parts['hour'],
        $time_parts['minute'],
        0
      );

      if ($candidate > $now) {
        return $candidate->setTimezone($utc_timezone);
      }
    }

    return NULL;
  }

  /**
   * Parses the first time from a start/end string like "5pm – 9pm".
   *
   * Returns:
   * - ['hour' => 17, 'minute' => 0]
   * - or NULL if no parsable start time exists
   */
  protected function parseStartTime($value) {
    $value = trim(mb_strtolower($value));

    // Normalize common dash variants and spacing.
    $value = str_replace(['–', '—'], '-', $value);
    $value = preg_replace('/\s+/', ' ', $value);

    // Safe fallback mappings for vague schedule text.
    $fallbacks = [
      'regular hours' => ['hour' => 11, 'minute' => 0],
      'open to close' => ['hour' => 11, 'minute' => 0],
      'all day' => ['hour' => 9, 'minute' => 0],
      'all-day' => ['hour' => 9, 'minute' => 0],
    ];

    if (isset($fallbacks[$value])) {
      return $fallbacks[$value];
    }

    // "Close" by itself is too vague to infer a start.
    if ($value === 'close') {
      return NULL;
    }

    if (!preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/', $value, $matches)) {
      return NULL;
    }

    $hour = (int) $matches[1];
    $minute = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
    $ampm = $matches[3];

    if ($hour === 12) {
      $hour = 0;
    }

    if ($ampm === 'pm') {
      $hour += 12;
    }

    return [
      'hour' => $hour,
      'minute' => $minute,
    ];
  }

  /**
   * Resolves allowed ISO weekdays (1=Mon ... 7=Sun) from taxonomy labels.
   */
  protected function getAllowedWeekdays(EntityInterface $entity) {
    $target_ids = [];
    foreach ($entity->get('field_day_of_week')->getValue() as $item) {
      if (!empty($item['target_id'])) {
        $target_ids[] = (int) $item['target_id'];
      }
    }

    if (!$target_ids) {
      return [];
    }

    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadMultiple($target_ids);

    if (!$terms) {
      return [];
    }

    $weekdays = [];

    foreach ($terms as $term) {
      $label = $this->normalizeLabel($term->label());

      switch ($label) {
        case 'daily':
        case 'every day':
        case 'all days':
        case 'from monday to sunday':
          $weekdays = array_merge($weekdays, [1, 2, 3, 4, 5, 6, 7]);
          break;

        case 'weekdays':
        case 'from monday to friday':
          $weekdays = array_merge($weekdays, [1, 2, 3, 4, 5]);
          break;

        case 'weekends':
        case 'weekends only':
          $weekdays = array_merge($weekdays, [6, 7]);
          break;

        case 'monday':
          $weekdays[] = 1;
          break;

        case 'tuesday':
          $weekdays[] = 2;
          break;

        case 'wednesday':
          $weekdays[] = 3;
          break;

        case 'thursday':
          $weekdays[] = 4;
          break;

        case 'friday':
          $weekdays[] = 5;
          break;

        case 'saturday':
          $weekdays[] = 6;
          break;

        case 'sunday':
          $weekdays[] = 7;
          break;
      }
    }

    $weekdays = array_values(array_unique($weekdays));
    sort($weekdays);

    return $weekdays;
  }

  /**
   * Normalizes taxonomy labels for matching.
   */
  protected function normalizeLabel($label) {
    $label = mb_strtolower(trim($label));
    $label = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $label);
    $label = preg_replace('/\s+/', ' ', $label);
    return trim($label);
  }

}
