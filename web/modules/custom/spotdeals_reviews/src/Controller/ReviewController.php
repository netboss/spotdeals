<?php

declare(strict_types=1);

namespace Drupal\spotdeals_reviews\Controller;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\spotdeals_reviews\Service\ReviewService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles review widget submissions.
 */
final class ReviewController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly ReviewService $reviewService,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Saves one review answer.
   */
  public function submit(Request $request): JsonResponse {
    if (!$this->currentUser->isAuthenticated()) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'You must be logged in to submit a review.',
      ], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Invalid review payload.',
      ], 400);
    }

    $field = isset($data['field']) && is_string($data['field']) ? $data['field'] : '';
    $value = filter_var($data['value'] ?? NULL, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    $venueId = isset($data['venue_id']) ? (int) $data['venue_id'] : 0;
    $dealId = isset($data['deal_id']) ? (int) $data['deal_id'] : 0;

    if ($value === NULL) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'A true/false review value is required.',
      ], 400);
    }

    try {
      $result = $this->reviewService->submitReview((int) $this->currentUser->id(), $venueId, $dealId, $field, $value);
    }
    catch (\Throwable $exception) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $exception->getMessage(),
      ], 400);
    }

    return new JsonResponse([
      'status' => 'ok',
      'message' => 'Review saved.',
      'stats' => $result['stats'],
      'user_review' => $result['user_review'],
    ]);
  }

}
