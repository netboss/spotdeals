<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote;

use Drupal\Core\Flood\FloodInterface;

/**
 * Applies conservative server-side abuse limits to anonymous voting.
 */
final class AnonymousVoteRateLimiter {

  private const BROWSER_LIMIT = 30;
  private const BROWSER_WINDOW = 3600;
  private const NETWORK_LIMIT = 60;
  private const NETWORK_WINDOW = 3600;
  private const TARGET_NETWORK_LIMIT = 6;
  private const TARGET_NETWORK_WINDOW = 86400;

  public function __construct(
    private readonly FloodInterface $flood,
    private readonly AnonymousVoteIdentity $identity,
  ) {}

  /**
   * Checks anonymous vote limits before a vote is written.
   */
  public function assertAllowed(string $scope, int $targetId, string $anonymousHash): void {
    if ($anonymousHash === '') {
      return;
    }

    $networkHash = $this->identity->networkHash();
    $browserEvent = 'spotdeals_vote.browser';
    $networkEvent = 'spotdeals_vote.network';
    $targetEvent = 'spotdeals_vote.target.' . preg_replace('/[^a-z0-9_]+/i', '_', $scope) . '.' . $targetId;

    if (!$this->flood->isAllowed($browserEvent, self::BROWSER_LIMIT, self::BROWSER_WINDOW, $anonymousHash)) {
      throw new \InvalidArgumentException('Too many votes were submitted from this browser. Please try again later.');
    }
    if (!$this->flood->isAllowed($networkEvent, self::NETWORK_LIMIT, self::NETWORK_WINDOW, $networkHash)) {
      throw new \InvalidArgumentException('Too many votes were submitted from this network. Please try again later.');
    }
    if (!$this->flood->isAllowed($targetEvent, self::TARGET_NETWORK_LIMIT, self::TARGET_NETWORK_WINDOW, $networkHash)) {
      throw new \InvalidArgumentException('This deal or venue has received too many votes from the same network. Please try again later.');
    }
  }

  /**
   * Registers a successfully stored anonymous vote submission.
   */
  public function register(string $scope, int $targetId, string $anonymousHash): void {
    if ($anonymousHash === '') {
      return;
    }

    $networkHash = $this->identity->networkHash();
    $targetEvent = 'spotdeals_vote.target.' . preg_replace('/[^a-z0-9_]+/i', '_', $scope) . '.' . $targetId;

    $this->flood->register('spotdeals_vote.browser', self::BROWSER_WINDOW, $anonymousHash);
    $this->flood->register('spotdeals_vote.network', self::NETWORK_WINDOW, $networkHash);
    $this->flood->register($targetEvent, self::TARGET_NETWORK_WINDOW, $networkHash);
  }

}
