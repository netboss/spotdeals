<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_smart_location\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\spotdeals_search_smart_location\Service\DealActivityLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles deal activity logging requests.
 */
final class DealActivityController extends ControllerBase {

  /**
   * Constructs a deal activity controller.
   */
  public function __construct(
    private readonly DealActivityLogger $dealActivityLogger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('spotdeals_search_smart_location.deal_activity_logger'),
    );
  }

  /**
   * Logs deal activity from browser interactions.
   */
  public function log(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      $payload = $request->request->all();
    }

    $dealNid = (int) ($payload['deal_nid'] ?? 0);
    $venueNid = (int) ($payload['venue_nid'] ?? 0);
    $action = (string) ($payload['action'] ?? 'view');
    $source = (string) ($payload['source'] ?? '');

    $this->dealActivityLogger->log($dealNid, $action, $source, $venueNid);

    return new JsonResponse(['ok' => TRUE]);
  }

}
