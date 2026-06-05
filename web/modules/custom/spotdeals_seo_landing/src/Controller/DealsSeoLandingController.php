<?php

declare(strict_types=1);

namespace Drupal\spotdeals_seo_landing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
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
   * High-value deal categories to cross-link from SEO landing pages.
   */
  private const RELATED_DEAL_CATEGORIES = [
    'happy-hour' => 'Happy Hour',
    'lunch-special' => 'Lunch Specials',
    'daily-special' => 'Daily Special',
    'beer' => 'Beer',
    'food-deals' => 'Food Deals',
  ];

  /**
   * Nearby area suggestions for important Florida markets.
   */
  private const NEARBY_AREAS_BY_CITY = [
    'orlando' => ['Winter Park', 'Kissimmee', 'Maitland'],
    'tampa' => ['Downtown Tampa', 'Ybor City', 'Hyde Park', 'Seminole Heights', 'Brandon'],
    'jacksonville' => ['Downtown Jacksonville', 'Riverside', 'San Marco', 'Jacksonville Beach', 'Orange Park'],
    'st-petersburg' => ['Downtown St. Petersburg', 'Grand Central District', 'Edge District', 'Gulfport', 'Pinellas Park'],
    'new-smyrna-beach' => ['Canal Street', 'Flagler Avenue', 'Edgewater', 'Port Orange', 'Daytona Beach'],
    'daytona-beach' => ['Beachside', 'Downtown Daytona Beach', 'Ormond Beach', 'Port Orange', 'South Daytona'],
  ];

  /**
   * Resolved city labels keyed by normalized slug.
   *
   * @var array<string, string|null>
   */
  private static array $resolvedCityLabels = [];

  /**
   * Resolved deal category labels keyed by normalized slug.
   *
   * @var array<string, string|null>
   */
  private static array $resolvedDealCategoryLabels = [];

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
    $city_label = $this->resolveCityLabel($city) ?? $this->slugToLikelyLabel($city);
    $deal_count = $this->countDeals($city_label);

    return sprintf('%d Deals in %s', $deal_count, $city_label);
  }

  /**
   * Title callback for /deals/{city}/{category}.
   */
  public function cityCategoryTitle(string $city, string $category): string {
    $city_label = $this->resolveCityLabel($city) ?? $this->slugToLikelyLabel($city);
    $category_label = $this->resolveDealCategoryLabel($category) ?? $this->slugToLikelyLabel($category);
    $deal_count = $this->countDeals($city_label, $category_label);

    return sprintf('%d %s in %s', $deal_count, $this->pluralizeLabel($category_label), $city_label);
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

    $landing_data = $this->buildLandingData($city, $city_label, $category, $category_label);
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
          'summary' => $this->buildSummaryCards($landing_data),
          'shell' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['spotdeals-seo-landing__shell'],
            ],
            'left_rail' => [
              '#type' => 'container',
              '#attributes' => [
                'class' => ['spotdeals-seo-landing__left-rail'],
              ],
              'related_deals' => $this->buildRelatedDealLinks($landing_data),
              'nearby_areas' => $this->buildNearbyAreas($landing_data),
            ],
            'results_column' => [
              '#type' => 'container',
              '#attributes' => [
                'class' => ['spotdeals-seo-landing__results-column'],
              ],
              'results' => [
                '#type' => 'container',
                '#attributes' => [
                  'class' => ['spotdeals-seo-landing__results'],
                ],
                'view' => $deals_build,
              ],
              'bottom_cta' => $this->buildBottomCta($landing_data),
              'about' => $this->buildAboutBlock($landing_data),
            ],
          ],
        ],
      ],
      '#cache' => [
        'contexts' => [
          'url.path',
        ],
        'tags' => [
          'config:views.view.' . self::DEALS_VIEW_ID,
          'taxonomy_term_list',
          'node_list',
        ],
        'max-age' => 3600,
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
   * Builds reusable display data for city and city/category landing pages.
   *
   * @return array<string, mixed>
   *   Landing page data used by render helpers.
   */
  private function buildLandingData(
    string $citySlug,
    string $cityLabel,
    ?string $categorySlug,
    ?string $categoryLabel,
  ): array {
    $total_deals = $this->countDeals($cityLabel, $categoryLabel);
    $venue_count = $this->countVenuesWithDeals($cityLabel, $categoryLabel);
    $related_categories = $this->buildRelatedCategoryData($citySlug, $categorySlug, $cityLabel);
    $nearby_areas = $this->buildNearbyAreaData($citySlug);
    $primary_stat_label = $categoryLabel !== NULL ? $categoryLabel : 'Local Deals';

    return [
      'city_slug' => $citySlug,
      'city_label' => $cityLabel,
      'category_slug' => $categorySlug,
      'category_label' => $categoryLabel,
      'total_deals' => $total_deals,
      'venue_count' => $venue_count,
      'primary_stat_label' => $primary_stat_label,
      'related_categories' => $related_categories,
      'nearby_areas' => $nearby_areas,
    ];
  }

  /**
   * Builds inventory summary cards above SEO landing results.
   *
   * @param array<string, mixed> $landingData
   *   Landing page data.
   */
  private function buildSummaryCards(array $landingData): array {
    $city_label = (string) $landingData['city_label'];
    $category_label = $landingData['category_label'] !== NULL ? (string) $landingData['category_label'] : 'Local Deals';
    $total_deals = (int) $landingData['total_deals'];
    $venue_count = (int) $landingData['venue_count'];
    $related_categories = is_array($landingData['related_categories']) ? $landingData['related_categories'] : [];
    $first_related_count = isset($related_categories[0]['count']) ? (int) $related_categories[0]['count'] : 0;
    $first_related_label = isset($related_categories[0]['label']) ? (string) $related_categories[0]['label'] : 'Related Deals';
    $second_related_count = isset($related_categories[1]['count']) ? (int) $related_categories[1]['count'] : 0;
    $second_related_label = isset($related_categories[1]['label']) ? (string) $related_categories[1]['label'] : 'More Deals';

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['spotdeals-seo-summary'],
        'aria-label' => $this->t('Deal summary for @city', ['@city' => $city_label]),
      ],
      'total' => $this->buildSummaryCard('🍽️', (string) $total_deals, $category_label, $this->t('Current local offers')),
      'venues' => $this->buildSummaryCard('🏬', (string) $venue_count, $this->t('Restaurants & Bars'), $this->t('Places with matching deals')),
      'related_one' => $this->buildSummaryCard('🏷️', (string) $first_related_count, $first_related_label, $this->t('Related local specials')),
      'related_two' => $this->buildSummaryCard('🍹', (string) $second_related_count, $second_related_label, $this->t('More ways to save')),
      'updated' => $this->buildSummaryCard('📅', $this->t('Updated'), $this->t('Daily'), $this->t('Fresh local deal listings')),
    ];
  }

  /**
   * Builds one summary card.
   */
  private function buildSummaryCard(string $icon, string|\Stringable $value, string|\Stringable $label, string|\Stringable $description): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['spotdeals-seo-summary__card'],
      ],
      'icon' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $icon,
        '#attributes' => [
          'class' => ['spotdeals-seo-summary__icon'],
          'aria-hidden' => 'true',
        ],
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => (string) $value,
        '#attributes' => [
          'class' => ['spotdeals-seo-summary__value'],
        ],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => (string) $label,
        '#attributes' => [
          'class' => ['spotdeals-seo-summary__label'],
        ],
      ],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => (string) $description,
        '#attributes' => [
          'class' => ['spotdeals-seo-summary__description'],
        ],
      ],
    ];
  }

  /**
   * Builds related deal links for the same city.
   *
   * @param array<string, mixed> $landingData
   *   Landing page data.
   */
  private function buildRelatedDealLinks(array $landingData): array {
    $city_label = (string) $landingData['city_label'];
    $related_categories = is_array($landingData['related_categories']) ? $landingData['related_categories'] : [];

    $links = [];
    foreach ($related_categories as $delta => $item) {
      if (!is_array($item) || empty($item['url']) || empty($item['label'])) {
        continue;
      }

      $links['link_' . $delta] = [
        '#type' => 'link',
        '#title' => [
          '#markup' => '<span class="spotdeals-seo-related__icon" aria-hidden="true">' . $this->categoryIcon((string) $item['slug']) . '</span><span>' . $this->escape((string) $item['label']) . '<small>' . $this->escape($city_label) . '</small></span>',
        ],
        '#url' => $item['url'],
        '#attributes' => [
          'class' => ['spotdeals-seo-related__link'],
        ],
      ];
    }

    if (empty($links)) {
      return ['#markup' => ''];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['spotdeals-seo-related'],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Explore More @city Deals', ['@city' => $city_label]),
        '#attributes' => [
          'class' => ['spotdeals-seo-section-title'],
        ],
      ],
      'links' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['spotdeals-seo-related__links'],
        ],
      ] + $links,
    ];
  }

  /**
   * Builds nearby area links.
   *
   * @param array<string, mixed> $landingData
   *   Landing page data.
   */
  private function buildNearbyAreas(array $landingData): array {
    $nearby_areas = is_array($landingData['nearby_areas']) ? $landingData['nearby_areas'] : [];
    if (empty($nearby_areas)) {
      return ['#markup' => ''];
    }

    $items = [];
    foreach ($nearby_areas as $delta => $area) {
      if (!is_array($area) || empty($area['label']) || empty($area['url'])) {
        continue;
      }

      $items['area_' . $delta] = [
        '#type' => 'link',
        '#title' => (string) $area['label'],
        '#url' => $area['url'],
        '#attributes' => [
          'class' => ['spotdeals-seo-nearby__item'],
        ],
      ];
    }

    if (empty($items)) {
      return ['#markup' => ''];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['spotdeals-seo-nearby'],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Popular Deals Near @city', ['@city' => $landingData['city_label']]),
        '#attributes' => [
          'class' => ['spotdeals-seo-section-title'],
        ],
      ],
      'items' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['spotdeals-seo-nearby__items'],
        ],
      ] + $items,
    ];
  }

  /**
   * Builds bottom conversion CTA.
   *
   * @param array<string, mixed> $landingData
   *   Landing page data.
   */
  private function buildBottomCta(array $landingData): array {
    $city_label = (string) $landingData['city_label'];

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['spotdeals-seo-cta'],
      ],
      'copy' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['spotdeals-seo-cta__copy'],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Can\'t find what you\'re looking for?'),
        ],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Browse all restaurants in @city or use Deals Finder to discover more nearby specials.', ['@city' => $city_label]),
        ],
      ],
      'action' => [
        '#type' => 'link',
        '#title' => $this->t('Open Deals Finder'),
        '#url' => Url::fromUserInput('/'),
        '#attributes' => [
          'class' => ['button', 'spotdeals-seo-cta__button'],
        ],
      ],
    ];
  }

  /**
   * Builds a short local context block below results.
   *
   * @param array<string, mixed> $landingData
   *   Landing page data.
   */
  private function buildAboutBlock(array $landingData): array {
    $city_label = (string) $landingData['city_label'];
    $category_label = $landingData['category_label'] !== NULL ? (string) $landingData['category_label'] : 'local deals';
    $total_deals = (int) $landingData['total_deals'];
    $venue_count = (int) $landingData['venue_count'];

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['spotdeals-seo-about'],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('About @category in @city', [
          '@category' => $category_label,
          '@city' => $city_label,
        ]),
        '#attributes' => [
          'class' => ['spotdeals-seo-section-title'],
        ],
      ],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('SpotDeals tracks @count @category from @venues local restaurants, bars, cafes, breweries, and neighborhood dining spots in @city. Use this page to compare current offers, discover nearby places, and keep exploring more ways to save.', [
          '@count' => $total_deals,
          '@category' => mb_strtolower($category_label, 'UTF-8'),
          '@venues' => $venue_count,
          '@city' => $city_label,
        ]),
      ],
    ];
  }

  /**
   * Builds related category data for a city.
   *
   * @return array<int, array<string, mixed>>
   *   Related category links with counts.
   */
  private function buildRelatedCategoryData(string $citySlug, ?string $currentCategorySlug, string $cityLabel): array {
    $items = [];
    foreach (self::RELATED_DEAL_CATEGORIES as $slug => $label) {
      if ($currentCategorySlug !== NULL && $this->normalizeForComparison($slug) === $this->normalizeForComparison($currentCategorySlug)) {
        continue;
      }

      $resolved_label = $this->resolveDealCategoryLabel($slug);
      if ($resolved_label === NULL) {
        continue;
      }

      $count = $this->countDeals($cityLabel, $resolved_label);
      if ($count < 1) {
        continue;
      }

      $items[] = [
        'slug' => $slug,
        'label' => $label,
        'count' => $count,
        'url' => Url::fromRoute('spotdeals_seo_landing.deals_city_category', [
          'city' => $citySlug,
          'category' => $slug,
        ]),
      ];
    }

    usort($items, static function (array $a, array $b): int {
      return ((int) $b['count']) <=> ((int) $a['count']);
    });

    return array_slice($items, 0, 6);
  }

  /**
   * Builds nearby area data.
   *
   * @return array<int, array<string, mixed>>
   *   Nearby area links.
   */
  private function buildNearbyAreaData(string $citySlug): array {
    $areas = self::NEARBY_AREAS_BY_CITY[$citySlug] ?? [];
    $items = [];

    foreach ($areas as $area) {
      $area_slug = $this->labelToSlug($area);
      if ($this->resolveCityLabel($area_slug) === NULL) {
        continue;
      }

      $items[] = [
        'label' => $area,
        'url' => Url::fromRoute('spotdeals_seo_landing.deals_city', [
          'city' => $area_slug,
        ]),
      ];
    }

    return $items;
  }

  /**
   * Counts active deals for a city, optionally limited to category.
   */
  private function countDeals(string $cityLabel, ?string $categoryLabel = NULL): int {
    $query = $this->seoLandingDatabase->select('node_field_data', 'd');
    $query->addExpression('COUNT(DISTINCT d.nid)', 'deal_count');
    $query->innerJoin('node__field_venue', 'fv', 'fv.entity_id = d.nid AND fv.deleted = 0');
    $query->innerJoin('node_field_data', 'v', 'v.nid = fv.field_venue_target_id AND v.status = 1');
    $query->innerJoin('node__field_address', 'fa', 'fa.entity_id = v.nid AND fa.deleted = 0');
    $query->condition('d.type', 'deal');
    $query->condition('d.status', 1);
    $query->condition('v.type', 'venue');
    $query->condition('fa.field_address_locality', $cityLabel);

    if ($categoryLabel !== NULL) {
      $query->innerJoin('node__field_deal_category', 'fdc', 'fdc.entity_id = d.nid AND fdc.deleted = 0');
      $query->innerJoin('taxonomy_term_field_data', 'tfd', 'tfd.tid = fdc.field_deal_category_target_id');
      $query->condition('tfd.vid', self::DEAL_CATEGORY_VOCABULARY);
      $query->condition('tfd.name', $categoryLabel);
    }

    return (int) $query->execute()->fetchField();
  }

  /**
   * Counts venues with active deals for a city, optionally limited to category.
   */
  private function countVenuesWithDeals(string $cityLabel, ?string $categoryLabel = NULL): int {
    $query = $this->seoLandingDatabase->select('node_field_data', 'v');
    $query->addExpression('COUNT(DISTINCT v.nid)', 'venue_count');
    $query->innerJoin('node__field_address', 'fa', 'fa.entity_id = v.nid AND fa.deleted = 0');
    $query->innerJoin('node__field_venue', 'fv', 'fv.field_venue_target_id = v.nid AND fv.deleted = 0');
    $query->innerJoin('node_field_data', 'd', 'd.nid = fv.entity_id AND d.type = :deal_type AND d.status = 1', [':deal_type' => 'deal']);
    $query->condition('v.type', 'venue');
    $query->condition('v.status', 1);
    $query->condition('fa.field_address_locality', $cityLabel);

    if ($categoryLabel !== NULL) {
      $query->innerJoin('node__field_deal_category', 'fdc', 'fdc.entity_id = d.nid AND fdc.deleted = 0');
      $query->innerJoin('taxonomy_term_field_data', 'tfd', 'tfd.tid = fdc.field_deal_category_target_id');
      $query->condition('tfd.vid', self::DEAL_CATEGORY_VOCABULARY);
      $query->condition('tfd.name', $categoryLabel);
    }

    return (int) $query->execute()->fetchField();
  }

  /**
   * Returns a small icon for a category slug.
   */
  private function categoryIcon(string $slug): string {
    $icons = [
      'happy-hour' => '🍹',
      'lunch-special' => '🍔',
      'taco-tuesday' => '🌮',
      'daily-special' => '🏷️',
      'beer' => '🍺',
      'food-deals' => '🏷️',
    ];

    return $icons[$slug] ?? '🏷️';
  }

  /**
   * Provides a simple plural label for page titles.
   */
  private function pluralizeLabel(string $label): string {
    $normalized = $this->normalizeForComparison($label);
    $known = [
      'daily special' => 'Daily Specials',
      'happy hour' => 'Happy Hours',
      'lunch special' => 'Lunch Specials',
      'drink special' => 'Drink Specials',
      'taco tuesday' => 'Taco Tuesdays',
      'craft beer' => 'Craft Beer Deals',
      'beer' => 'Beer Deals',
      'wine' => 'Wine Deals',
      'coffee' => 'Coffee Deals',
      'food deal' => 'Food Deals',
    ];

    if (isset($known[$normalized])) {
      return $known[$normalized];
    }

    if (str_ends_with($label, 's')) {
      return $label;
    }

    return $label . 's';
  }

  /**
   * Converts a label to a URL slug.
   */
  private function labelToSlug(string $label): string {
    $slug = mb_strtolower(trim($label), 'UTF-8');
    $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug;
  }

  /**
   * Escapes text for small trusted HTML strings in link titles.
   */
  private function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
   * Builds a view display with arguments without executing it twice.
   *
   * @return array{build: array, has_results: bool}
   *   The rendered build and whether the view could be built.
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

    $build['#cache']['max-age'] = $build['#cache']['max-age'] ?? 3600;

    return [
      'build' => $build,
      'has_results' => TRUE,
    ];
  }

  /**
   * Resolves a city slug like "new-smyrna-beach" to the stored locality label.
   */
  private function resolveCityLabel(string $citySlug): ?string {
    $target = $this->normalizeForComparison($citySlug);

    if (array_key_exists($target, self::$resolvedCityLabels)) {
      return self::$resolvedCityLabels[$target];
    }

    $candidate = $this->slugToLikelyLabel($citySlug);

    $query = $this->seoLandingDatabase->select('node__field_address', 'fa');
    $query->addField('fa', 'field_address_locality');
    $query->isNotNull('fa.field_address_locality');
    $query->condition('fa.field_address_locality', '', '<>');
    $query->condition('fa.field_address_locality', $candidate);
    $query->range(0, 1);

    $exact = $query->execute()->fetchField();
    if (is_string($exact) && trim($exact) !== '') {
      self::$resolvedCityLabels[$target] = trim($exact);
      return self::$resolvedCityLabels[$target];
    }

    $query = $this->seoLandingDatabase->select('node__field_address', 'fa');
    $query->addField('fa', 'field_address_locality');
    $query->isNotNull('fa.field_address_locality');
    $query->condition('fa.field_address_locality', '', '<>');
    $query->distinct();
    $query->orderBy('fa.field_address_locality', 'ASC');

    foreach ($query->execute()->fetchCol() as $locality) {
      if (!is_string($locality)) {
        continue;
      }

      if ($this->normalizeForComparison($locality) === $target) {
        self::$resolvedCityLabels[$target] = trim($locality);
        return self::$resolvedCityLabels[$target];
      }
    }

    self::$resolvedCityLabels[$target] = NULL;
    return NULL;
  }

  /**
   * Resolves a category slug like "happy-hour" to the taxonomy term label.
   */
  private function resolveDealCategoryLabel(string $categorySlug): ?string {
    $target = $this->normalizeForComparison($categorySlug);
    $compact_target = $this->compactNormalizeForComparison($categorySlug);

    if (array_key_exists($target, self::$resolvedDealCategoryLabels)) {
      return self::$resolvedDealCategoryLabels[$target];
    }

    $candidate = $this->slugToLikelyLabel($categorySlug);

    $query = $this->seoLandingEntityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::DEAL_CATEGORY_VOCABULARY)
      ->condition('name', $candidate)
      ->range(0, 1);

    $term_ids = $query->execute();
    if (!empty($term_ids)) {
      $term_id = reset($term_ids);
      $term = $this->seoLandingEntityTypeManager->getStorage('taxonomy_term')->load($term_id);
      if ($term !== NULL) {
        self::$resolvedDealCategoryLabels[$target] = $term->label();
        return self::$resolvedDealCategoryLabels[$target];
      }
    }

    $query = $this->seoLandingDatabase->select('taxonomy_term_field_data', 'tfd');
    $query->addField('tfd', 'name');
    $query->condition('tfd.vid', self::DEAL_CATEGORY_VOCABULARY);
    $query->orderBy('tfd.name', 'ASC');

    foreach ($query->execute()->fetchCol() as $name) {
      if (!is_string($name)) {
        continue;
      }

      if (
        $this->normalizeForComparison($name) === $target ||
        $this->compactNormalizeForComparison($name) === $compact_target
      ) {
        self::$resolvedDealCategoryLabels[$target] = $name;
        return self::$resolvedDealCategoryLabels[$target];
      }
    }

    self::$resolvedDealCategoryLabels[$target] = NULL;
    return NULL;
  }

  /**
   * Converts a slug to a likely stored label.
   */
  private function slugToLikelyLabel(string $slug): string {
    $label = str_replace(['-', '_'], ' ', trim($slug));
    $label = preg_replace('/\s+/u', ' ', $label) ?? $label;

    return mb_convert_case(trim($label), MB_CASE_TITLE, 'UTF-8');
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

  /**
   * Normalizes a slug or label without spaces for loose comparisons.
   */
  private function compactNormalizeForComparison(string $value): string {
    return str_replace(' ', '', $this->normalizeForComparison($value));
  }

}
