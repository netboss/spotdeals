<?php

declare(strict_types=1);

namespace Drupal\spotdeals_seo_landing\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Supplies backend-derived data for Deal pages.
 */
final class DealPageData {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Builds related Deal links for other active deals at the same venue.
   */
  public function buildRelatedDeals(NodeInterface $deal, NodeInterface $venue): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'deal')
      ->condition('status', 1)
      ->condition('field_venue.target_id', $venue->id())
      ->condition('nid', $deal->id(), '<>')
      ->sort('changed', 'DESC')
      ->range(0, 4);

    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', 'deal');
    if (isset($field_definitions['field_active'])) {
      $query->condition('field_active', 1);
    }

    $ids = $query->execute();
    if ($ids === []) {
      return [];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
    $items = [];

    foreach ($ids as $id) {
      $related_deal = $nodes[$id] ?? NULL;
      if (!$related_deal instanceof NodeInterface) {
        continue;
      }

      $summary_parts = [];
      $category_labels = $this->entityReferenceLabels($related_deal, ['field_deal_category']);
      if ($category_labels !== []) {
        $summary_parts[] = implode(', ', $category_labels);
      }

      $days = $this->entityReferenceLabels($related_deal, ['field_day_of_week']);
      if ($days !== []) {
        $summary_parts[] = implode(', ', $days);
      }

      $items[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['spotdeals-related-deals__item']],
        'link' => [
          '#type' => 'link',
          '#title' => $related_deal->label(),
          '#url' => $related_deal->toUrl(),
          '#attributes' => ['class' => ['spotdeals-related-deals__link']],
        ],
        'summary' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => implode(' · ', $summary_parts),
          '#attributes' => ['class' => ['spotdeals-related-deals__summary']],
          '#access' => $summary_parts !== [],
        ],
      ];
    }

    if ($items === []) {
      return [];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['spotdeals-related-deals']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => t('More deals at @venue', ['@venue' => $venue->label()]),
        '#attributes' => ['class' => ['spotdeals-related-deals__title']],
      ],
      'items' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['spotdeals-related-deals__items']],
      ] + $items,
    ];
  }

  /**
   * Gets unique labels from entity-reference fields.
   */
  private function entityReferenceLabels(NodeInterface $node, array $field_names): array {
    $labels = [];
    foreach ($field_names as $field_name) {
      if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
        continue;
      }
      foreach ($node->get($field_name)->referencedEntities() as $entity) {
        $label = trim((string) $entity->label());
        if ($label !== '') {
          $labels[$label] = $label;
        }
      }
    }
    return array_values($labels);
  }

}
