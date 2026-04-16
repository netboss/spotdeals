<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\spotdeals_search_smart_location\RecommendationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns direct recommendation markup for AJAX requests.
 */
final class RecommendationAjaxController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly RecommendationService $recommendationService,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('spotdeals_search_smart_location.recommendation_service'),
      $container->get('renderer'),
    );
  }

  /**
   * Builds an AJAX recommendation response.
   */
  public function recommendation(Request $request): JsonResponse {
    $values = $request->request->all();

    if (!\_spotdeals_search_smart_location_is_recommendation_mode($values)) {
      return $this->buildErrorResponse('Recommendation mode is not active.', 400);
    }

    $originMode = (string) ($values['search_origin_mode'] ?? '');
    $originLat = $values['origin_lat'] ?? NULL;
    $originLon = $values['origin_lon'] ?? NULL;

    if ($originMode !== 'browser' || !is_numeric($originLat) || !is_numeric($originLon)) {
      return $this->buildErrorResponse('Browser location is required for recommendation mode.', 400);
    }

    $rawQuery = trim((string) ($values['search_raw'] ?? $values['search_deals'] ?? $values['search_api_fulltext'] ?? ''));
    $cleanQuery = \_spotdeals_search_smart_location_normalize_keywords(
      trim((string) ($values['search_clean'] ?? ''))
    );
    $parsed = \_spotdeals_search_smart_location_parse($rawQuery);

    $recommendationCuisines = \_spotdeals_search_smart_location_parse_recommendation_cuisines(
      (string) ($values['recommendation_cuisines'] ?? '')
    );
    $recommendationFallbackSource = $cleanQuery !== ''
      ? $cleanQuery
      : trim((string) ($parsed['keywords'] ?? ''));
    $effectiveRecommendationCuisines = !empty($recommendationCuisines)
      ? $recommendationCuisines
      : \_spotdeals_search_smart_location_parse_recommendation_cuisines($recommendationFallbackSource);

    $recommendationAction = strtolower(trim((string) ($values['recommendation_action'] ?? '')));
    if ($recommendationAction === 'reset') {
      \_spotdeals_search_smart_location_reset_recommendation_session();
    }
    elseif ($recommendationAction === 'retry') {
      \_spotdeals_search_smart_location_apply_recommendation_action_to_session($recommendationAction);
    }

    $excludedVenueNids = \_spotdeals_search_smart_location_get_recommendation_exclusions();
    $recommendedDealNids = $this->recommendationService->recommendDealNids(
      (float) $originLat,
      (float) $originLon,
      $effectiveRecommendationCuisines,
      25.0,
      [],
      $excludedVenueNids,
    );

    \_spotdeals_search_smart_location_store_current_recommendation($recommendedDealNids);

    $directRecommendationDealNid = !empty($recommendedDealNids[0]) ? (int) $recommendedDealNids[0] : 0;

    $request->attributes->set('spotdeals_search_smart_location.recommendation_mode', TRUE);
    $request->attributes->set('spotdeals_search_smart_location.recommendation_action', $recommendationAction);
    $request->attributes->set('spotdeals_search_smart_location.raw_query', $rawQuery);
    $request->attributes->set('spotdeals_search_smart_location.direct_recommendation_deal_nid', $directRecommendationDealNid);
    $request->attributes->set('spotdeals_search_smart_location.recommended_deal_nids', $recommendedDealNids);
    $request->attributes->set('spotdeals_search_smart_location.recommendation_nearby_message_added', FALSE);

    $viewHtml = $this->renderer->renderRoot($this->buildAjaxViewRenderArray($directRecommendationDealNid));

    \Drupal::logger('spotdeals_search_smart_location')->notice(
      'SMART LOCATION recommendation AJAX response built: action="@action" deal_nid="@deal" excluded="@excluded" recommended="@recommended"',
      [
        '@action' => $recommendationAction,
        '@deal' => (string) $directRecommendationDealNid,
        '@excluded' => json_encode($excludedVenueNids, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        '@recommended' => json_encode($recommendedDealNids, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ]
    );

    $response = new JsonResponse([
      'success' => TRUE,
      'recommendation_active' => $directRecommendationDealNid > 0,
      'deal_nid' => $directRecommendationDealNid,
      'view_html' => (string) $viewHtml,
    ]);
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');

    return $response;
  }

  /**
   * Builds view-like markup for the recommendation results area.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function buildAjaxViewRenderArray(int $dealNid): array {
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'view',
          'view-deals-search-solr',
          'view-id-deals_search_solr',
          'view-display-id-page_1',
        ],
        'data-recommendation-active' => $dealNid > 0 ? '1' : '0',
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    if ($dealNid <= 0) {
      $build['empty'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['view-empty'],
        ],
        'content' => [
          '#markup' => $this->t('No nearby pick could be found right now. Please try again.'),
        ],
      ];

      return $build;
    }

    $content = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['view-content'],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    $recommendationNearbyMessage = \_spotdeals_search_smart_location_build_recommendation_nearby_message($dealNid);
    if (!empty($recommendationNearbyMessage)) {
      $content['recommendation_nearby_message'] = $recommendationNearbyMessage;
    }

    $content['recommendation'] = \_spotdeals_search_smart_location_build_direct_recommendation_render($dealNid);
    $build['content'] = $content;

    return $build;
  }

  /**
   * Builds a JSON error response with no-cache headers.
   */
  private function buildErrorResponse(string $message, int $statusCode): JsonResponse {
    $response = new JsonResponse([
      'success' => FALSE,
      'message' => $message,
    ], $statusCode);
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');

    return $response;
  }

}
