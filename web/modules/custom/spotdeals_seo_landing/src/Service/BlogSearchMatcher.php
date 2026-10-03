<?php

declare(strict_types=1);

namespace Drupal\spotdeals_seo_landing\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Finds published Blog guides that explicitly match an SEO deals landing page.
 */
final class BlogSearchMatcher {

  private const BLOG_BUNDLE = 'blog_post';
  private const DEAL_CATEGORY_VOCABULARY = 'deal_category';
  private const MAX_RESULTS = 2;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns guide teaser data for an exact city/category search context.
   *
   * Category pages require an explicit Related deal categories match. City-only
   * pages match only general location guides whose related-category field is
   * empty. This prevents broad guides from leaking into unrelated searches.
   *
   * @return array<int, array<string, string>>
   *   Guide teaser data, newest first.
   */
  public function find(string $locality, ?string $categoryLabel = NULL, int $limit = self::MAX_RESULTS): array {
    $locality = trim($locality);
    if ($locality === '' || $limit < 1) {
      return [];
    }

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', self::BLOG_BUNDLE)
      ->condition('status', 1)
      ->condition('field_address.locality', $locality)
      ->sort('changed', 'DESC')
      ->range(0, $limit);

    if ($categoryLabel !== NULL) {
      $category_id = $this->resolveCategoryId($categoryLabel);
      if ($category_id === NULL) {
        return [];
      }
      $query->condition('field_related_deal_categories.target_id', $category_id);
    }
    else {
      $query->notExists('field_related_deal_categories.target_id');
    }

    $ids = array_map('intval', array_values($query->execute()));
    if ($ids === []) {
      return [];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
    $guides = [];

    foreach ($ids as $id) {
      $node = $nodes[$id] ?? NULL;
      if (!$node instanceof NodeInterface) {
        continue;
      }

      $guides[] = [
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'summary' => $this->summary($node),
      ];
    }

    return $guides;
  }

  /**
   * Resolves the canonical Deal Category term ID from its stored label.
   */
  private function resolveCategoryId(string $categoryLabel): ?int {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => self::DEAL_CATEGORY_VOCABULARY,
      'name' => $categoryLabel,
    ]);

    foreach ($terms as $term) {
      if ($term instanceof TermInterface) {
        return (int) $term->id();
      }
    }

    return NULL;
  }

  /**
   * Builds a compact plain-text teaser from the Blog post summary/body.
   */
  private function summary(NodeInterface $node): string {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return '';
    }

    $body = $node->get('body')->first();
    $source = trim((string) ($body?->get('summary')->getValue() ?? ''));
    if ($source === '') {
      $source = trim((string) ($body?->get('value')->getValue() ?? ''));
    }

    $plain = trim(preg_replace('/\s+/u', ' ', Html::decodeEntities(strip_tags($source))) ?? '');
    if ($plain === '') {
      return '';
    }

    return Unicode::truncate($plain, 220, TRUE, TRUE);
  }

}
