<?php

declare(strict_types=1);

namespace Drupal\spotdeals_seo_landing\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Supplies taxonomy landing-page data that belongs outside the theme layer.
 */
final class TaxonomyLandingData {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * Builds all backend-derived data used by a taxonomy landing page.
   */
  public function build(TermInterface $term): array {
    $is_tag = $term->bundle() === 'tags';

    return [
      'tag_related_content' => $is_tag ? $this->buildTagRelatedContent($term) : [],
      'tag_local_topics' => $is_tag ? $this->buildTagLocalDeals($term) : [],
      'tag_location_deals' => $is_tag ? $this->buildTagLocationDeals($term) : [],
      'stats' => $is_tag ? [] : $this->buildStats($term),
      'related_links' => $is_tag ? [] : $this->buildRelatedLinks($term),
    ];
  }

  /**
   * Counts published content using a Tags term.
   */
  private function buildTagRelatedContent(TermInterface $term): array {
    $query = $this->database->select('taxonomy_index', 'ti');
    $query->join('node_field_data', 'nfd', 'nfd.nid = ti.nid');
    $count = (int) $query
      ->condition('ti.tid', (int) $term->id())
      ->condition('nfd.status', 1)
      ->fields('nfd', ['nid'])
      ->distinct()
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count === 0) {
      return [];
    }

    return [
      'count' => $count,
      'label' => \Drupal::translation()->formatPlural($count, 'Published page', 'Published pages'),
      'icon' => '📄',
    ];
  }

  /**
   * Builds unique Deal links from the Blog topic's exact locality.
   */
  private function buildTagLocalDeals(TermInterface $term): array {
    $locality = $this->resolveTopicLocality($term);
    if ($locality === '') {
      return [];
    }

    $venue_ids = $this->publishedVenueIdsForLocality($locality);
    if ($venue_ids === []) {
      return [];
    }

    // Fetch extra candidates because several Deal nodes can legitimately share
    // the same title. The sidebar should never render repeated link labels.
    $deal_ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'deal')
      ->condition('status', 1)
      ->condition('field_venue.target_id', $venue_ids, 'IN')
      ->sort('changed', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 50)
      ->execute();

    if ($deal_ids === []) {
      return [];
    }

    $deals = $this->entityTypeManager->getStorage('node')->loadMultiple($deal_ids);
    $links = [];
    $seen_titles = [];
    foreach ($deal_ids as $deal_id) {
      $deal = $deals[$deal_id] ?? NULL;
      if (!$deal instanceof NodeInterface) {
        continue;
      }

      $title = trim($deal->label());
      $key = mb_strtolower($title, 'UTF-8');
      if ($title === '' || isset($seen_titles[$key])) {
        continue;
      }

      $seen_titles[$key] = TRUE;
      $links[] = [
        'title' => $title,
        'url' => $deal->toUrl(),
      ];

      if (count($links) === 7) {
        break;
      }
    }

    return $links === [] ? [] : [
      'location' => $locality,
      'links' => $links,
    ];
  }

  /**
   * Builds paged Deal teasers when a Tags term exactly matches a locality.
   */
  private function buildTagLocationDeals(TermInterface $term): array {
    $locality = trim($term->label());
    if ($locality === '') {
      return [];
    }

    $venue_ids = $this->publishedVenueIdsForLocality($locality);
    if ($venue_ids === []) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $base_query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'deal')
      ->condition('status', 1)
      ->condition('field_venue.target_id', $venue_ids, 'IN');

    $count_query = clone $base_query;
    $total = (int) $count_query->count()->execute();
    $pager = $this->pagerManager->createPager($total, 10);

    $deal_ids = $base_query
      ->sort('changed', 'DESC')
      ->sort('nid', 'DESC')
      ->range($pager->getCurrentPage() * 10, 10)
      ->execute();

    $deals = $storage->loadMultiple($deal_ids);
    $ordered_deals = [];
    foreach ($deal_ids as $deal_id) {
      if (($deals[$deal_id] ?? NULL) instanceof NodeInterface) {
        $ordered_deals[] = $deals[$deal_id];
      }
    }

    $view_builder = $this->entityTypeManager->getViewBuilder('node');

    return [
      'location' => $locality,
      'count' => $total,
      'items' => $view_builder->viewMultiple($ordered_deals, 'teaser'),
      'pager' => $total > 10 ? ['#type' => 'pager'] : [],
    ];
  }

  /**
   * Resolves the locality stored on a published Blog post using this tag.
   */
  private function resolveTopicLocality(TermInterface $term): string {
    // taxonomy_index is the authoritative record of content using a taxonomy
    // term regardless of which taxonomy-reference field supplied the term.
    // Restrict the candidates to published Blog posts, then read the locality
    // from the Blog post's structured address.
    $query = $this->database->select('taxonomy_index', 'ti');
    $query->join('node_field_data', 'nfd', 'nfd.nid = ti.nid');
    $query->addField('nfd', 'nid');
    $query->condition('ti.tid', (int) $term->id());
    $query->condition('nfd.type', 'blog_post');
    $query->condition('nfd.status', 1);
    $query->orderBy('nfd.changed', 'DESC');
    $query->range(0, 10);

    $blog_ids = array_map('intval', $query->execute()->fetchCol());
    if ($blog_ids === []) {
      return '';
    }

    $blogs = $this->entityTypeManager->getStorage('node')->loadMultiple($blog_ids);
    foreach ($blog_ids as $blog_id) {
      $blog = $blogs[$blog_id] ?? NULL;
      if (!$blog instanceof NodeInterface || !$blog->hasField('field_address') || $blog->get('field_address')->isEmpty()) {
        continue;
      }

      $locality = trim((string) ($blog->get('field_address')->first()?->get('locality')->getValue() ?? ''));
      if ($locality !== '') {
        return $locality;
      }
    }

    return '';
  }

  /**
   * Returns published venue IDs whose structured locality exactly matches.
   */
  private function publishedVenueIdsForLocality(string $locality): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->condition('status', 1)
      ->condition('field_address.locality', $locality)
      ->execute();

    return array_map('intval', array_values($ids));
  }

  /**
   * Builds related taxonomy links from the same vocabulary.
   */
  private function buildRelatedLinks(TermInterface $term): array {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadTree($term->bundle(), 0, 1, TRUE);
    $links = [];

    foreach ($terms as $related_term) {
      if (!$related_term instanceof TermInterface || (int) $related_term->id() === (int) $term->id()) {
        continue;
      }

      $links[] = [
        'title' => $related_term->label(),
        'url' => $related_term->toUrl(),
        'vocabulary' => $related_term->bundle(),
      ];

      if (count($links) >= 6) {
        break;
      }
    }

    return $links;
  }

  /**
   * Builds taxonomy summary stats for non-Tags vocabularies.
   */
  private function buildStats(TermInterface $term): array {
    $tid = (int) $term->id();

    $deal_count_query = $this->database->select('taxonomy_index', 'ti')
      ->condition('ti.tid', $tid)
      ->condition('nfd.type', 'deal')
      ->condition('nfd.status', 1)
      ->fields('ti', ['nid'])
      ->distinct();
    $deal_count_query->join('node_field_data', 'nfd', 'nfd.nid = ti.nid');
    $deal_count = (int) $deal_count_query->countQuery()->execute()->fetchField();

    $venue_ids = [];
    $query = $this->database->select('taxonomy_index', 'ti')
      ->condition('ti.tid', $tid)
      ->condition('nfd.type', 'deal')
      ->condition('nfd.status', 1);
    $query->join('node_field_data', 'nfd', 'nfd.nid = ti.nid');
    $query->leftJoin('node__field_venue', 'venue_ref', 'venue_ref.entity_id = ti.nid');
    $query->fields('venue_ref', ['field_venue_target_id']);
    foreach ($query->execute()->fetchCol() as $venue_id) {
      if (!empty($venue_id)) {
        $venue_ids[(int) $venue_id] = TRUE;
      }
    }

    return [
      [
        'icon' => '🏷',
        'value' => (string) $deal_count,
        'label' => t('Active deals'),
        'description' => t('Current matching offers'),
      ],
      [
        'icon' => '📍',
        'value' => (string) count($venue_ids),
        'label' => t('Venues'),
        'description' => t('Local places with deals'),
      ],
      [
        'icon' => '🔎',
        'value' => t('Updated'),
        'label' => t('Discoverable'),
        'description' => t('Browse and compare results'),
      ],
    ];
  }

}
