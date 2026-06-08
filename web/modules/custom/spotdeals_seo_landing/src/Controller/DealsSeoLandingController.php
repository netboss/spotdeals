<?php

declare(strict_types=1);

namespace Drupal\spotdeals_seo_landing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Url;
use Drupal\spotdeals_search_smart_location\RecommendationService;
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
   * Query flag used to show one local recommendation on SEO deal pages.
   */
  private const LOCAL_RECOMMENDATION_QUERY = 'seo_recommendation';

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
   * Recommendation service.
   */
  private RecommendationService $seoLandingRecommendationService;

  /**
   * Page cache kill switch.
   */
  private KillSwitch $seoLandingPageCacheKillSwitch;

  /**
   * Constructs the controller.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    RecommendationService $recommendationService,
    KillSwitch $pageCacheKillSwitch,
  ) {
    $this->seoLandingDatabase = $database;
    $this->seoLandingEntityTypeManager = $entityTypeManager;
    $this->seoLandingRecommendationService = $recommendationService;
    $this->seoLandingPageCacheKillSwitch = $pageCacheKillSwitch;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('spotdeals_search_smart_location.recommendation_service'),
      $container->get('page_cache_kill_switch'),
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
    $local_recommendation = $this->prepareLocalRecommendation($city_label, $category_label);
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
              'local_recommendation' => $this->buildLocalRecommendationBlock($landing_data, $local_recommendation),
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
          'url.query_args',
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
   * Prepares a local recommendation from the current SEO page result pool.
   *
   * @return array<string,mixed>
   *   Recommendation state used by rendering helpers.
   */
  private function prepareLocalRecommendation(string $cityLabel, ?string $categoryLabel = NULL): array {
    $request = \Drupal::request();
    $action = mb_strtolower(trim((string) $request->query->get('recommendation_action', '')), 'UTF-8');
    $active = $this->queryFlagIsEnabled((string) $request->query->get(self::LOCAL_RECOMMENDATION_QUERY, '')) && $action !== 'reset';
    $search_text = trim((string) $request->query->get('search_deals_by_city', ''));

    $candidate_deal_nids = $this->loadLocalRecommendationDealNids($cityLabel, $categoryLabel, $search_text);
    $recommended_deal_nids = [];

    if ($active && !empty($candidate_deal_nids)) {
      $this->killPageCacheForLocalRecommendation();

      if ($action === 'retry') {
        \_spotdeals_search_smart_location_apply_recommendation_action_to_session($action);
      }

      $excluded_venue_nids = \_spotdeals_search_smart_location_get_recommendation_exclusions();
      $excluded_deal_nids = \_spotdeals_search_smart_location_get_recommendation_deal_exclusions();
      // The visible SEO page filter has already constrained the local pool.
      // Do not pass the keyword as a strict recommendation preference here:
      // generic terms such as "tacos" can fail exact preference matching on
      // otherwise valid Taco Tuesday deals. Pick from the filtered pool instead.
      $recommended_deal_nids = $this->seoLandingRecommendationService->recommendFromDealNids(
        $candidate_deal_nids,
        [],
        $excluded_venue_nids,
        $excluded_deal_nids
      );

      if (empty($recommended_deal_nids)) {
        $recommended_deal_nids = $this->fallbackLocalRecommendationDealNids($candidate_deal_nids, $excluded_deal_nids);
      }

      $direct_recommendation_deal_nid = !empty($recommended_deal_nids[0]) ? (int) $recommended_deal_nids[0] : 0;

      $request->attributes->set('spotdeals_search_smart_location.recommendation_mode', TRUE);
      $request->attributes->set('spotdeals_search_smart_location.raw_query', $search_text);
      $request->attributes->set('spotdeals_search_smart_location.recommendation_action', $action);
      $request->attributes->set('spotdeals_search_smart_location.recommended_deal_nids', $recommended_deal_nids);
      $request->attributes->set('spotdeals_search_smart_location.direct_recommendation_deal_nid', $direct_recommendation_deal_nid);
      $request->attributes->set('spotdeals_search_smart_location.recommendation_nearby_message_added', FALSE);

      \_spotdeals_search_smart_location_store_current_recommendation($recommended_deal_nids);
    }

    return [
      'active' => $active,
      'candidate_count' => count($candidate_deal_nids),
      'recommended_deal_nids' => $recommended_deal_nids,
      'search_text' => $search_text,
    ];
  }

  /**
   * Builds the local recommendation callout above SEO page results.
   *
   * @param array<string, mixed> $landingData
   *   Landing page data.
   * @param array<string, mixed> $localRecommendation
   *   Prepared recommendation state.
   */
  private function buildLocalRecommendationBlock(array $landingData, array $localRecommendation): array {
    $candidate_count = (int) ($localRecommendation['candidate_count'] ?? 0);
    if ($candidate_count <= 1) {
      return ['#markup' => ''];
    }

    $city_label = (string) $landingData['city_label'];
    $category_label = $landingData['category_label'] !== NULL ? (string) $landingData['category_label'] : 'deals';
    $active = !empty($localRecommendation['active']);
    $search_text = trim((string) ($localRecommendation['search_text'] ?? ''));
    $has_recommendation = !empty($localRecommendation['recommended_deal_nids']);

    $title = $active && $has_recommendation
      ? $this->t('Your local pick')
      : $this->t('Need help choosing?');

    if ($active && $has_recommendation) {
      $description = $search_text !== ''
        ? $this->t('Try another pick from these @city @category matching “@search”.', [
          '@city' => $city_label,
          '@category' => mb_strtolower($category_label, 'UTF-8'),
          '@search' => $search_text,
        ])
        : $this->t('Try another pick from these @city @category.', [
          '@city' => $city_label,
          '@category' => mb_strtolower($category_label, 'UTF-8'),
        ]);
    }
    else {
      $description = $search_text !== ''
        ? $this->t('Let SpotDeals pick one from the @count local results matching “@search”.', [
          '@count' => $candidate_count,
          '@search' => $search_text,
        ])
        : $this->t('Let SpotDeals pick one from these @count local results.', [
          '@count' => $candidate_count,
        ]);
    }

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'spotdeals-seo-local-pick',
          $active && $has_recommendation ? 'is-active' : 'is-idle',
        ],
      ],
      'copy' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['spotdeals-seo-local-pick__copy'],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $title,
        ],
        'description' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $description,
        ],
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['spotdeals-seo-local-pick__actions'],
        ],
        'pick' => [
          '#type' => 'link',
          '#title' => $active && $has_recommendation ? $this->t('Try another') : $this->t('Pick one for me'),
          '#url' => $this->buildLocalRecommendationUrl($active && $has_recommendation ? 'retry' : ''),
          '#attributes' => [
            'class' => ['button', 'spotdeals-seo-local-pick__button'],
            'rel' => 'nofollow',
          ],
        ],
      ],
    ];

    if ($active) {
      $build['actions']['reset'] = [
        '#type' => 'link',
        '#title' => $this->t('Show all'),
        '#url' => $this->buildLocalRecommendationResetUrl(),
        '#attributes' => [
          'class' => ['button', 'button--secondary', 'spotdeals-seo-local-pick__reset'],
          'rel' => 'nofollow',
        ],
      ];
    }

    return $build;
  }

  /**
   * Builds a URL that keeps the current local filters and enables local pick mode.
   */
  private function buildLocalRecommendationUrl(string $action = ''): Url {
    $query = \Drupal::request()->query->all();
    $query[self::LOCAL_RECOMMENDATION_QUERY] = '1';
    $query['scroll_results'] = '1';

    if ($action !== '') {
      $query['recommendation_action'] = $action;
    }
    else {
      unset($query['recommendation_action']);
    }

    unset($query['page']);

    return Url::fromRoute('<current>', [], [
      'query' => $query,
    ]);
  }

  /**
   * Builds a URL that disables local pick mode and preserves other filters.
   */
  private function buildLocalRecommendationResetUrl(): Url {
    $query = \Drupal::request()->query->all();
    unset(
      $query[self::LOCAL_RECOMMENDATION_QUERY],
      $query['recommendation_action'],
      $query['help_me_choose'],
      $query['recommendation_cuisines'],
      $query['origin_lat'],
      $query['origin_lon'],
      $query['search_origin_mode'],
      $query['page']
    );

    return Url::fromRoute('<current>', [], [
      'query' => $query,
    ]);
  }

  /**
   * Returns local deal IDs eligible for the SEO page recommendation picker.
   *
   * @return array<int,int>
   *   Deal node IDs in a stable, display-friendly order.
   */
  /**
   * Provides a deterministic fallback when the recommendation service cannot pick.
   *
   * The current SEO page filters have already constrained the candidate pool. If
   * the recommendation service returns no item because every candidate is
   * excluded by the current rotation session, still return one visible filtered
   * result so the local pick action never falls back to the full result list.
   *
   * @param array<int,int> $candidateDealNids
   *   Deal IDs eligible for the current local SEO page and keyword filter.
   * @param array<int,int> $excludedDealNids
   *   Deal IDs already used in the current recommendation rotation.
   *
   * @return array<int,int>
   *   A single fallback deal ID, or an empty array when no candidate exists.
   */
  private function fallbackLocalRecommendationDealNids(array $candidateDealNids, array $excludedDealNids): array {
    $candidateDealNids = array_values(array_unique(array_filter(
      array_map('intval', $candidateDealNids),
      static fn(int $nid): bool => $nid > 0
    )));
    $excludedDealNids = array_values(array_unique(array_filter(
      array_map('intval', $excludedDealNids),
      static fn(int $nid): bool => $nid > 0
    )));

    if (empty($candidateDealNids)) {
      return [];
    }

    $availableDealNids = array_values(array_diff($candidateDealNids, $excludedDealNids));
    if (!empty($availableDealNids)) {
      return [(int) $availableDealNids[array_rand($availableDealNids)]];
    }

    return [(int) $candidateDealNids[array_rand($candidateDealNids)]];
  }

  private function loadLocalRecommendationDealNids(string $cityLabel, ?string $categoryLabel = NULL, string $searchText = ''): array {
    $query = $this->seoLandingDatabase->select('node_field_data', 'd');
    $query->distinct();
    $query->addField('d', 'nid');
    $query->innerJoin('node__field_venue', 'fv', 'fv.entity_id = d.nid AND fv.deleted = 0');
    $query->innerJoin('node_field_data', 'v', 'v.nid = fv.field_venue_target_id AND v.status = 1');
    $query->innerJoin('node__field_address', 'fa', 'fa.entity_id = v.nid AND fa.deleted = 0');
    $query->condition('d.type', 'deal');
    $query->condition('d.status', 1);
    $query->condition('v.type', 'venue');
    $query->condition('fa.field_address_locality', $cityLabel);

    if ($categoryLabel !== NULL) {
      $query->innerJoin('node__field_deal_category', 'fdc', 'fdc.entity_id = d.nid AND fdc.deleted = 0');
      $query->innerJoin('taxonomy_term_field_data', 'deal_category', 'deal_category.tid = fdc.field_deal_category_target_id');
      $query->condition('deal_category.vid', self::DEAL_CATEGORY_VOCABULARY);
      $query->condition('deal_category.name', $categoryLabel);
    }

    $this->applyLocalRecommendationSearchFilter($query, $searchText);

    $query->orderBy('d.changed', 'DESC');
    $query->orderBy('d.title', 'ASC');
    $query->range(0, 75);

    return array_values(array_filter(
      array_map('intval', $query->execute()->fetchCol()),
      static fn(int $nid): bool => $nid > 0
    ));
  }

  /**
   * Applies a simple local keyword filter matching the visible SEO page search.
   */
  private function applyLocalRecommendationSearchFilter($query, string $searchText): void {
    $tokens = $this->localRecommendationSearchTokens($searchText);
    if (empty($tokens)) {
      return;
    }

    $query->leftJoin('node__field_price_offer_text', 'offer_text', 'offer_text.entity_id = d.nid AND offer_text.deleted = 0');
    $query->leftJoin('node__body', 'deal_body', 'deal_body.entity_id = d.nid AND deal_body.deleted = 0');
    $query->leftJoin('node__field_cuisine', 'venue_cuisine', 'venue_cuisine.entity_id = v.nid AND venue_cuisine.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'venue_cuisine_term', 'venue_cuisine_term.tid = venue_cuisine.field_cuisine_target_id');
    $query->leftJoin('node__field_tags', 'venue_tags', 'venue_tags.entity_id = v.nid AND venue_tags.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'venue_tag_term', 'venue_tag_term.tid = venue_tags.field_tags_target_id');

    foreach ($tokens as $token) {
      $like = '%' . $this->seoLandingDatabase->escapeLike($token) . '%';
      $or = $query->orConditionGroup()
        ->condition('d.title', $like, 'LIKE')
        ->condition('v.title', $like, 'LIKE')
        ->condition('offer_text.field_price_offer_text_value', $like, 'LIKE')
        ->condition('deal_body.body_value', $like, 'LIKE')
        ->condition('venue_cuisine_term.name', $like, 'LIKE')
        ->condition('venue_tag_term.name', $like, 'LIKE');
      $query->condition($or);
    }
  }

  /**
   * Tokenizes local SEO recommendation search text.
   *
   * @return array<int,string>
   *   Search tokens.
   */
  private function localRecommendationSearchTokens(string $searchText): array {
    $searchText = mb_strtolower(trim($searchText), 'UTF-8');
    $searchText = preg_replace('/[^[:alnum:]\s]+/u', ' ', $searchText) ?? '';
    $tokens = preg_split('/\s+/', $searchText) ?: [];

    return array_values(array_unique(array_filter(
      array_map('trim', $tokens),
      static fn(string $token): bool => mb_strlen($token, 'UTF-8') >= 2
    )));
  }

  /**
   * Returns TRUE when a query flag value is enabled.
   */
  private function queryFlagIsEnabled(string $value): bool {
    return in_array(mb_strtolower(trim($value), 'UTF-8'), ['1', 'true', 'on', 'yes'], TRUE);
  }

  /**
   * Disables page cache for personalized local recommendation mode.
   */
  private function killPageCacheForLocalRecommendation(): void {
    $this->seoLandingPageCacheKillSwitch->trigger();
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

    $direct_recommendation_deal_nid = (int) \Drupal::request()->attributes->get('spotdeals_search_smart_location.direct_recommendation_deal_nid', 0);
    if ($direct_recommendation_deal_nid > 0) {
      return $this->buildDirectLocalRecommendationResults($display_id, $direct_recommendation_deal_nid);
    }

    $result = $this->buildViewDisplay(self::DEALS_VIEW_ID, $display_id, $arguments, TRUE);

    return $result['build'];
  }

  /**
   * Builds the single direct recommendation result for local SEO pick mode.
   */
  private function buildDirectLocalRecommendationResults(string $displayId, int $dealNid): array {
    if (
      $dealNid <= 0
      || !function_exists('\_spotdeals_search_smart_location_build_direct_recommendation_render')
    ) {
      return ['#markup' => ''];
    }

    $direct_recommendation = \_spotdeals_search_smart_location_build_direct_recommendation_render($dealNid);
    if (empty($direct_recommendation)) {
      return ['#markup' => ''];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'view',
          'view-deals-search-solr',
          'view-id-deals_search_solr',
          'view-display-id-' . $displayId,
          'spotdeals-seo-results-view',
        ],
        'data-recommendation-active' => '1',
      ],
      'results' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['spotdeals-seo-results-view__results'],
        ],
        'header' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $this->t('Displaying @start - @end of @total', [
            '@start' => 1,
            '@end' => 1,
            '@total' => 1,
          ]),
          '#attributes' => [
            'class' => ['view-header'],
          ],
        ],
        'content' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['view-content', 'spotdeals-finder__cards'],
            'data-result-count' => '1',
          ],
          'direct_recommendation' => $direct_recommendation,
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
      '#attached' => [
        'library' => [
          'spotdeals_minimal/deal-card',
          'spotdeals_vote/vote',
        ],
      ],
    ];
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
