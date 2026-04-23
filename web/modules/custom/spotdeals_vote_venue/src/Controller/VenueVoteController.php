<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote_venue\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\spotdeals_vote_venue\VenueVoteManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles venue vote submissions.
 */
final class VenueVoteController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly VenueVoteManager $voteManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('spotdeals_vote_venue.manager'),
    );
  }

  /**
   * Submits a venue vote.
   */
  public function submit(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      $payload = $request->request->all();
    }

    try {
      $response = $this->voteManager->submitVote(
        (int) $this->currentUser()->id(),
        (int) ($payload['venue_nid'] ?? 0),
        trim((string) ($payload['field'] ?? '')),
        (int) ($payload['value'] ?? -1),
        isset($payload['source']) ? (string) $payload['source'] : NULL,
      );

      return $this->buildResponse($response, 200);
    }
    catch (\InvalidArgumentException $exception) {
      return $this->buildResponse([
        'ok' => FALSE,
        'message' => $exception->getMessage(),
      ], 400);
    }
    catch (\Throwable $exception) {
      \Drupal::logger('spotdeals_vote_venue')->error(
        'Vote submission failed for venue "@venue": @message',
        [
          '@venue' => (string) ($payload['venue_nid'] ?? 0),
          '@message' => $exception->getMessage(),
        ]
      );

      return $this->buildResponse([
        'ok' => FALSE,
        'message' => 'Unable to save vote right now.',
      ], 500);
    }
  }

  /**
   * Builds a no-cache JSON response.
   *
   * @param array<string,mixed> $payload
   *   Response data.
   */
  private function buildResponse(array $payload, int $statusCode): JsonResponse {
    $response = new JsonResponse($payload, $statusCode);
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    return $response;
  }

}
