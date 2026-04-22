<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote;

use Drupal\spotdeals_vote_deal\DealVoteManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Legacy compatibility wrapper around the deal vote manager.
 */
final class VoteManager {

  /**
   * Constructs the legacy vote manager.
   */
  public function __construct(
    private readonly DealVoteManager $dealVoteManager,
  ) {}

  /**
   * Creates the wrapper from the container.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('spotdeals_vote_deal.manager'),
    );
  }

  /**
   * Returns current aggregate and optionally current user vote state.
   *
   * @return array<string,mixed>
   *   Vote state array.
   */
  public function getDealVoteState(int $dealNid, int $uid = 0): array {
    return $this->dealVoteManager->getDealVoteState($dealNid, $uid);
  }

  /**
   * Validates and stores a vote update.
   *
   * @return array<string,mixed>
   *   Normalized response payload.
   */
  public function submitVote(int $uid, int $dealNid, int $venueNid, string $fieldName, int $value, ?string $source = NULL): array {
    return $this->dealVoteManager->submitVote($uid, $dealNid, $venueNid, $fieldName, $value, $source);
  }

}
