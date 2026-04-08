<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_insights\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\spotdeals_search_insights\Service\SearchInsightsLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a popular searches block.
 *
 * @Block(
 *   id = "spotdeals_search_insights_most_searched",
 *   admin_label = @Translation("SpotDeals popular searches"),
 *   category = @Translation("SpotDeals")
 * )
 */
final class MostSearchedBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a popular searches block.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly SearchInsightsLogger $searchInsightsLogger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('spotdeals_search_insights.logger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $popular = $this->searchInsightsLogger->getPopularSearches(8, 7);

    if ($popular === []) {
      return [
        '#cache' => [
          'max-age' => 1800,
        ],
      ];
    }

    $items = [];
    foreach ($popular as $row) {
      $keyword = (string) ($row['keyword'] ?? '');
      if ($keyword === '') {
        continue;
      }

      $label = mb_convert_case($keyword, MB_CASE_TITLE, 'UTF-8');
      $url = Url::fromUri('internal:/deals', [
        'query' => [
          'search_deals' => $keyword,
          'search_raw' => $keyword,
        ],
      ]);

      $link = Link::fromTextAndUrl($label, $url)->toRenderable();
      $link['#attributes'] = [
        'class' => ['spotdeals-popular-search-link'],
        'data-keyword' => $keyword,
      ];

      $items[] = $link;
    }

    if ($items === []) {
      return [
        '#cache' => [
          'max-age' => 1800,
        ],
      ];
    }

    return [
      'title' => [
        '#markup' => '<h2 class="block-title">' . $this->t('Popular Searches') . '</h2>',
      ],
      'items' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => [
          'class' => ['spotdeals-popular-searches'],
        ],
      ],
      '#attached' => [
        'library' => [
          'spotdeals_search_insights/popular_searches_block',
        ],
      ],
      '#cache' => [
        'max-age' => 1800,
      ],
    ];
  }

}
