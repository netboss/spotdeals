<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote;

use Drupal\Core\PrivateKey;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves durable pseudonymous identities for anonymous voters.
 */
final class AnonymousVoteIdentity {

  public const COOKIE_NAME = 'spotdeals_voter';

  private const COOKIE_LIFETIME = 31536000;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requestStack,
    private readonly PrivateKey $privateKey,
  ) {}

  /**
   * Returns the current voter identity without creating an anonymous cookie.
   *
   * @return array{uid: int, voter_key: string, anonymous_hash: string}|null
   *   The current identity, or NULL when an anonymous browser has no token yet.
   */
  public function current(): ?array {
    $uid = (int) $this->currentUser->id();
    if ($this->currentUser->isAuthenticated() && $uid > 0) {
      return [
        'uid' => $uid,
        'voter_key' => 'u:' . $uid,
        'anonymous_hash' => '',
      ];
    }

    $request = $this->requestStack->getCurrentRequest();
    $token = trim((string) $request?->cookies->get(self::COOKIE_NAME, ''));
    if (!$this->isValidToken($token)) {
      return NULL;
    }

    $hash = $this->hashToken($token);
    return [
      'uid' => 0,
      'voter_key' => 'a:' . $hash,
      'anonymous_hash' => $hash,
    ];
  }

  /**
   * Returns an identity for a vote submission, creating a token when needed.
   *
   * @return array{identity: array{uid: int, voter_key: string, anonymous_hash: string}, cookie: \Symfony\Component\HttpFoundation\Cookie|null}
   *   Identity and an optional cookie that the controller must add to response.
   */
  public function forSubmission(): array {
    $identity = $this->current();
    if ($identity !== NULL) {
      return ['identity' => $identity, 'cookie' => NULL];
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $hash = $this->hashToken($token);
    $request = $this->requestStack->getCurrentRequest();

    return [
      'identity' => [
        'uid' => 0,
        'voter_key' => 'a:' . $hash,
        'anonymous_hash' => $hash,
      ],
      'cookie' => Cookie::create(
        self::COOKIE_NAME,
        $token,
        time() + self::COOKIE_LIFETIME,
        '/',
        NULL,
        $request?->isSecure() ?? TRUE,
        TRUE,
        FALSE,
        Cookie::SAMESITE_LAX,
      ),
    ];
  }

  /**
   * Returns a rotating pseudonymous network identifier for abuse controls.
   */
  public function networkHash(): string {
    $request = $this->requestStack->getCurrentRequest();
    $ip = trim((string) $request?->getClientIp());
    if ($ip === '') {
      $ip = 'unknown';
    }

    return hash_hmac('sha256', gmdate('Y-m-d') . '|' . $ip, $this->secret());
  }

  private function isValidToken(string $token): bool {
    return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
  }

  private function hashToken(string $token): string {
    return hash_hmac('sha256', $token, $this->secret());
  }

  private function secret(): string {
    return $this->privateKey->get() . Settings::getHashSalt();
  }

}
