<?php

declare(strict_types=1);

namespace Drupal\spotdeals_social\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\spotdeals_social\Cache\SocialActivityCache;
use Drupal\user\UserInterface;

/**
 * Reads public SpotDeals follow relationships while respecting blocks.
 */
final class SocialRelationshipManager {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly SocialPrivacyManager $privacyManager,
  ) {}

  /**
   * Returns follower/following counts and URLs for a profile.
   *
   * @return array<string, mixed>
   *   Social relationship summary.
   */
  public function buildSummary(UserInterface $account): array {
    if (!$this->privacyManager->canViewProfile($account, $this->currentUser)) {
      return [];
    }

    $uid = (int) $account->id();
    if ($uid <= 0 || !$this->database->schema()->tableExists('flagging')) {
      return $this->emptySummary($account);
    }

    return [
      'followers_count' => count($this->loadUsers($this->followerIds($uid))),
      'following_count' => count($this->loadUsers($this->followingIds($uid))),
      'followers_url' => Url::fromRoute('spotdeals_social.followers', ['user' => $account->id()])->toString(),
      'following_url' => Url::fromRoute('spotdeals_social.following', ['user' => $account->id()])->toString(),
    ];
  }

  /**
   * Returns visible followers of an account.
   *
   * @return \Drupal\user\UserInterface[]
   *   User accounts keyed by user ID.
   */
  public function followers(UserInterface $account): array {
    if (!$this->privacyManager->canViewProfile($account, $this->currentUser)) {
      return [];
    }

    return $this->loadUsers($this->followerIds((int) $account->id()));
  }

  /**
   * Returns visible accounts followed by an account.
   *
   * @return \Drupal\user\UserInterface[]
   *   User accounts keyed by user ID.
   */
  public function following(UserInterface $account): array {
    if (!$this->privacyManager->canViewProfile($account, $this->currentUser)) {
      return [];
    }

    return $this->loadUsers($this->followingIds((int) $account->id()));
  }

  /**
   * Returns cache tags for relationship summaries/lists.
   *
   * @return string[]
   *   Cache tags.
   */
  public function canViewRelationships(UserInterface $account): bool {
    return $this->privacyManager->canViewProfile($account, $this->currentUser);
  }


  /**
   * Returns active users blocked by the current account owner.
   *
   * This is intentionally directional: it reveals only blocks created by the
   * supplied account and never whether another user has blocked them.
   *
   * @return \Drupal\user\UserInterface[]
   *   Blocked user accounts keyed by user ID.
   */
  public function blockedUsers(UserInterface $account): array {
    $uid = (int) $account->id();
    if ($uid <= 0 || !$this->database->schema()->tableExists('flagging')) {
      return [];
    }

    $rows = $this->database->select('flagging', 'f')
      ->fields('f', ['entity_id'])
      ->condition('flag_id', 'block_user')
      ->condition('uid', $uid)
      ->execute()
      ->fetchCol();

    $uids = array_values(array_unique(array_filter(array_map('intval', $rows))));
    if (!$uids) {
      return [];
    }

    $loaded = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
    $users = [];
    foreach ($uids as $blocked_uid) {
      if (
        isset($loaded[$blocked_uid]) &&
        $loaded[$blocked_uid] instanceof UserInterface &&
        $loaded[$blocked_uid]->isActive()
      ) {
        $users[$blocked_uid] = $loaded[$blocked_uid];
      }
    }

    return $users;
  }

  /**
   * Returns cache contexts for viewer-dependent relationship visibility.
   *
   * @return string[]
   *   Cache contexts.
   */
  public function getCacheContexts(): array {
    return ['user'];
  }

  /**
   * Returns cache tags for relationship summaries/lists.
   *
   * @return string[]
   *   Cache tags.
   */
  public function getCacheTags(UserInterface $account): array {
    return [
      SocialActivityCache::timelineTag((int) $account->id()),
      'user:' . $account->id(),
    ];
  }

  /**
   * Returns visible follower user IDs.
   *
   * @return int[]
   *   User IDs.
   */
  private function followerIds(int $uid): array {
    if ($uid <= 0 || !$this->database->schema()->tableExists('flagging')) {
      return [];
    }

    $rows = $this->database->select('flagging', 'f')
      ->fields('f', ['uid'])
      ->condition('flag_id', 'follow_user')
      ->condition('entity_id', $uid)
      ->execute()
      ->fetchCol();

    return $this->filterBlocked($uid, $rows);
  }

  /**
   * Returns visible followed user IDs.
   *
   * @return int[]
   *   User IDs.
   */
  private function followingIds(int $uid): array {
    if ($uid <= 0 || !$this->database->schema()->tableExists('flagging')) {
      return [];
    }

    $rows = $this->database->select('flagging', 'f')
      ->fields('f', ['entity_id'])
      ->condition('flag_id', 'follow_user')
      ->condition('uid', $uid)
      ->execute()
      ->fetchCol();

    return $this->filterBlocked($uid, $rows);
  }

  /**
   * Removes users blocked in either direction relative to the profile owner.
   *
   * @param int $uid
   *   Profile owner user ID.
   * @param array<int|string> $candidateIds
   *   Candidate user IDs.
   *
   * @return int[]
   *   Visible user IDs.
   */
  private function filterBlocked(int $uid, array $candidateIds): array {
    $candidateIds = array_values(array_unique(array_filter(array_map('intval', $candidateIds))));
    if (!$candidateIds) {
      return [];
    }

    $query = $this->database->select('flagging', 'f');
    $query->fields('f', ['uid', 'entity_id']);
    $query->condition('flag_id', 'block_user');
    $or = $query->orConditionGroup()
      ->condition('uid', $uid)
      ->condition('entity_id', $uid);
    $query->condition($or);

    $blocked = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $actor = (int) $row->uid;
      $target = (int) $row->entity_id;
      $blocked[] = $actor === $uid ? $target : $actor;
    }

    return array_values(array_diff($candidateIds, array_unique($blocked)));
  }

  /**
   * Loads active users while preserving the relationship order.
   *
   * @param int[] $uids
   *   User IDs.
   *
   * @return \Drupal\user\UserInterface[]
   *   Active user accounts keyed by user ID.
   */
  private function loadUsers(array $uids): array {
    if (!$uids) {
      return [];
    }

    $loaded = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
    $users = [];
    foreach ($uids as $uid) {
      if (
        isset($loaded[$uid]) &&
        $loaded[$uid] instanceof UserInterface &&
        $loaded[$uid]->isActive() &&
        $this->privacyManager->canViewProfile($loaded[$uid], $this->currentUser)
      ) {
        $users[$uid] = $loaded[$uid];
      }
    }

    return $users;
  }

  /**
   * Returns an empty summary with valid relationship-list URLs.
   */
  private function emptySummary(UserInterface $account): array {
    return [
      'followers_count' => 0,
      'following_count' => 0,
      'followers_url' => Url::fromRoute('spotdeals_social.followers', ['user' => $account->id()])->toString(),
      'following_url' => Url::fromRoute('spotdeals_social.following', ['user' => $account->id()])->toString(),
    ];
  }

}
