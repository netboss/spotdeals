<?php

declare(strict_types=1);

namespace Drupal\spotdeals_seo_landing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds SEO-friendly landing pages for deals by city and category.
 */
final class DealsSeoLandingController extends ControllerBase {

  /**
   * Deals view machine name.
   */
  private const DEALS_VIEW_ID = 'deals_search_solr';

  /**
   * Deals view display for /deals/{city}.
   *
   * Update if your actual display ID differs.
   */
  private const DEALS_CITY_DISPLAY_ID = 'block_2';

  /**
   * Deals view display for /deals/{city}/{category}.
   *
   * Update if your actual display ID differs.
   */
  private const DEALS_CITY_CATEGORY_DISPLAY_ID = 'block_1';

  /**
   * Deal category vocabulary machine name.
   */
  private const DEAL_CATEGORY_VOCABULARY = 'deal_category';

  /**
   * Database connection.
   */
  private Connection $seoLandingDatabase;

  /**
   * Entity type manager.
   */
  private EntityTypeManagerInterface $seoLandingEntityTypeManager;

  /**
   * Constructs the controller.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->seoLandingDatabase = $database;
    $this->seoLandingEntityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Builds /deals/{city}.
   */
  public function cityPage(string $city): array {
    return $this->buildLandingPage($city, NULL);
  }

  /**
   * Builds /deals/{city}/{category}.
   */
  public function cityCategoryPage(string $city, string $category): array {
    return $this->buildLandingPage($city, $category);
  }

  /**
   * Title callback for /deals/{city}.
   */
  public function cityTitle(string $city): string {
    $city_label = $this->resolveCityLabel($city);
    if ($city_label === NULL) {
      throw new NotFoundHttpException();
    }

    return sprintf('Deals in %s', $city_label);
  }

  /**
   * Title callback for /deals/{city}/{category}.
   */
  public function cityCategoryTitle(string $city, string $category): string {
    $city_label = $this->resolveCityLabel($city);
    $category_label = $this->resolveDealCategoryLabel($category);

    if ($city_label === NULL || $category_label === NULL) {
      throw new NotFoundHttpException();
    }

    return sprintf('%s in %s', $category_label, $city_label);
  }

  /**
   * Builds the landing page render array.
   */
  private function buildLandingPage(string $city, ?string $category = NULL): array {
    $city_label = $this->resolveCityLabel($city);
    if ($city_label === NULL) {
      throw new NotFoundHttpException('City not found.');
    }

    $category_label = NULL;
    if ($category !== NULL) {
      $category_label = $this->resolveDealCategoryLabel($category);
      if ($category_label === NULL) {
        throw new NotFoundHttpException('Deal category not found.');
      }
    }

    $deals_build = $this->buildDealsResults($city_label, $category_label);

    $page_title = $category_label !== NULL
      ? sprintf('%s in %s', $category_label, $city_label)
      : sprintf('Deals in %s', $city_label);

    $intro_text = $category_label !== NULL
      ? sprintf(
        'Find %s in %s, including current local offers, food and drink specials, and nearby places worth checking out.',
        $category_label,
        $city_label
      )
      : sprintf(
        'Browse current local deals in %s, including restaurant specials, food offers, and nearby places worth checking out.',
        $city_label
      );

    $meta_description = $category_label !== NULL
      ? sprintf(
        'Find %s in %s on SpotDeals. Browse current local specials, restaurant deals, and nearby offers.',
        $category_label,
        $city_label
      )
      : sprintf(
        'Find local deals in %s on SpotDeals. Browse current restaurant specials, food deals, and nearby offers.',
        $city_label
      );

    $canonical_url = $category_label !== NULL
      ? Url::fromRoute('spotdeals_seo_landing.deals_city_category', [
        'city' => $city,
        'category' => $category,
      ], ['absolute' => TRUE])->toString()
      : Url::fromRoute('spotdeals_seo_landing.deals_city', [
        'city' => $city,
      ], ['absolute' => TRUE])->toString();

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['spotdeals-seo-landing'],
      ],
      'content_layout' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['spotdeals-seo-landing__layout'],
        ],
        'main' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['spotdeals-seo-landing__main'],
          ],
          'header' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['spotdeals-seo-landing__header'],
            ],
            'intro' => [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => $intro_text,
              '#attributes' => [
                'class' => ['spotdeals-seo-landing__intro'],
              ],
            ],
          ],
          'results' => $deals_build,
        ],
      ],
      '#cache' => [
        'contexts' => [
          'url.path',
          'url.query_args',
        ],
        'tags' => [
          'config:views.view.' . self::DEALS_VIEW_ID,
          'taxonomy_term_list',
          'node_list',
        ],
      ],
      '#attached' => [
        'html_head' => [
          [
            [
              '#tag' => 'meta',
              '#attributes' => [
                'name' => 'description',
                'content' => $meta_description,
              ],
            ],
            'spotdeals_seo_landing_meta_description',
          ],
          [
            [
              '#tag' => 'link',
              '#attributes' => [
                'rel' => 'canonical',
                'href' => $canonical_url,
              ],
            ],
            'spotdeals_seo_landing_canonical',
          ],
          [
            [
              '#tag' => 'meta',
              '#attributes' => [
                'property' => 'og:title',
                'content' => $page_title,
              ],
            ],
            'spotdeals_seo_landing_og_title',
          ],
        ],
      ],
    ];
  }

  /**
   * Builds the deals results block.
   */
  private function buildDealsResults(string $cityLabel, ?string $categoryLabel = NULL): array {
    if ($categoryLabel !== NULL) {
      $display_id = self::DEALS_CITY_CATEGORY_DISPLAY_ID;
      $arguments = [$cityLabel, $categoryLabel];
    }
    else {
      $display_id = self::DEALS_CITY_DISPLAY_ID;
      $arguments = [$cityLabel];
    }

    $result = $this->buildViewDisplay(self::DEALS_VIEW_ID, $display_id, $arguments, TRUE);

    return $result['build'];
  }

  /**
   * Builds a view display with arguments and executes it explicitly.
   *
   * @return array{build: array, has_results: bool}
   *   The rendered build and whether the executed view returned rows.
   */
  private function buildViewDisplay(
    string $viewId,
    string $displayId,
    array $arguments,
    bool $throwOnMissing = FALSE,
  ): array {
    $view = Views::getView($viewId);
    if (!$view instanceof ViewExecutable) {
      if ($throwOnMissing) {
        throw new NotFoundHttpException(sprintf('View %s not found.', $viewId));
      }

      return [
        'build' => ['#markup' => ''],
        'has_results' => FALSE,
      ];
    }

    $view->setDisplay($displayId);
    $view->setArguments($arguments);
    $view->preExecute($arguments);
    $view->execute();

    $has_results = !empty($view->result);

    $build = $view->buildRenderable($displayId, $arguments, FALSE);
    if (!is_array($build)) {
      if ($throwOnMissing) {
        throw new NotFoundHttpException(sprintf('View display %s on %s could not be built.', $displayId, $viewId));
      }

      return [
        'build' => ['#markup' => ''],
        'has_results' => FALSE,
      ];
    }

    return [
      'build' => $build,
      'has_results' => $has_results,
    ];
  }

  /**
   * Resolves a city slug like "new-smyrna-beach" to the stored locality label.
   */
  private function resolveCityLabel(string $citySlug): ?string {
    $target = $this->normalizeForComparison($citySlug);

    $query = $this->seoLandingDatabase->select('node__field_address', 'fa');
    $query->addField('fa', 'field_address_locality');
    $query->isNotNull('fa.field_address_locality');
    $query->condition('fa.field_address_locality', '', '<>');
    $query->distinct();
    $query->orderBy('fa.field_address_locality', 'ASC');

    $localities = $query->execute()->fetchCol();

    foreach ($localities as $locality) {
      if (!is_string($locality)) {
        continue;
      }

      if ($this->normalizeForComparison($locality) === $target) {
        return trim($locality);
      }
    }

    return NULL;
  }

  /**
   * Resolves a category slug like "happy-hour" to the taxonomy term label.
   */
  private function resolveDealCategoryLabel(string $categorySlug): ?string {
    $target = $this->normalizeForComparison($categorySlug);

    $term_storage = $this->seoLandingEntityTypeManager->getStorage('taxonomy_term');
    $term_ids = $term_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::DEAL_CATEGORY_VOCABULARY)
      ->sort('name', 'ASC')
      ->execute();

    if (empty($term_ids)) {
      return NULL;
    }

    /** @var \Drupal\taxonomy\TermInterface[] $terms */
    $terms = $term_storage->loadMultiple($term_ids);

    foreach ($terms as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }

      $name = $term->label();
      if ($this->normalizeForComparison($name) === $target) {
        return $name;
      }
    }

    return NULL;
  }

  /**
   * Normalizes a slug or label for reliable comparison.
   */
  private function normalizeForComparison(string $value): string {
    $value = trim($value);
    $value = mb_strtolower($value, 'UTF-8');
    $value = str_replace(['-', '_'], ' ', $value);
    $value = preg_replace('/[^[:alnum:]\s]+/u', ' ', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';

    return trim($value);
  }

}
