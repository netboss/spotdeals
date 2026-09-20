<?php

declare(strict_types=1);

namespace Drupal\spotdeals_social\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\spotdeals_social\Cache\SocialActivityCache;
use Drupal\user\UserInterface;

/**
 * Centralizes SpotDeals private-account visibility rules.
 */
final class SocialPrivacyManager {

  public const FIELD_NAME = 'field_social_private';

  public function __construct(
    private readonly Connection $database,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Returns TRUE when the account has enabled private social mode.
   */
  public function isPrivate(UserInterface $account): bool {
    return $account->hasField(self::FIELD_NAME)
      && !$account->get(self::FIELD_NAME)->isEmpty()
      && (bool) $account->get(self::FIELD_NAME)->value;
  }

  /**
   * Returns TRUE when a viewer may see a private account's social profile.
   */
  public function canViewProfile(UserInterface $account, AccountInterface $viewer): bool {
    $account_uid = (int) $account->id();
    $viewer_uid = (int) $viewer->id();

    if ($viewer_uid > 0 && $viewer_uid === $account_uid) {
      return TRUE;
    }

    // Administrative access remains independent from social privacy.
    if ($viewer->hasPermission('administer users')) {
      return TRUE;
    }

    // Direction matters for profile visibility. A user who is blocked by the
    // profile owner cannot view that profile. A user who has blocked the
    // profile owner may still view the profile so they can review and undo
    // their own block. Mutual blocks therefore remain hidden in both
    // directions until one side unblocks from their Blocked users page.
    if ($viewer_uid > 0 && $this->userBlocked($account_uid, $viewer_uid)) {
      return FALSE;
    }

    if ($viewer_uid > 0 && $this->userBlocked($viewer_uid, $account_uid)) {
      return TRUE;
    }

    if (!$this->isPrivate($account)) {
      return TRUE;
    }

    if ($viewer_uid <= 0) {
      return FALSE;
    }

    // A private user opens the relationship by choosing to follow the viewer.
    return $this->userFollows($account_uid, $viewer_uid);
  }

  /**
   * Returns TRUE when the actor may initiate a social action toward the target.
   */
  public function canInitiateInteraction(AccountInterface $actor, UserInterface $target): bool {
    $actor_uid = (int) $actor->id();
    $target_uid = (int) $target->id();

    if ($actor_uid <= 0) {
      return FALSE;
    }

    if ($actor_uid === $target_uid || $actor->hasPermission('administer users')) {
      return TRUE;
    }

    if ($this->usersAreBlocked($actor_uid, $target_uid)) {
      return FALSE;
    }

    if (!$this->isPrivate($target)) {
      return TRUE;
    }

    return $this->userFollows($target_uid, $actor_uid);
  }

  /**
   * Changes private mode and invalidates affected profile/activity caches.
   */
  public function setPrivate(UserInterface $account, bool $private): void {
    if (!$account->hasField(self::FIELD_NAME)) {
      throw new \LogicException('The SpotDeals social privacy field is not installed.');
    }

    if ($this->isPrivate($account) === $private) {
      return;
    }

    $account->set(self::FIELD_NAME, $private ? 1 : 0);
    $account->save();

    $uid = (int) $account->id();
    $tags = [
      'user:' . $uid,
      SocialActivityCache::activityTag($uid),
      SocialActivityCache::timelineTag($uid),
    ];

    // Followers' timelines may gain or lose this user's activity immediately.
    if ($this->database->schema()->tableExists('flagging')) {
      $followers = $this->database->select('flagging', 'f')
        ->fields('f', ['uid'])
        ->condition('flag_id', 'follow_user')
        ->condition('entity_id', $uid)
        ->execute()
        ->fetchCol();

      foreach (array_unique(array_map('intval', $followers)) as $follower_uid) {
        if ($follower_uid > 0) {
          $tags[] = SocialActivityCache::timelineTag($follower_uid);
        }
      }
    }

    $this->cacheTagsInvalidator->invalidateTags(array_values(array_unique($tags)));
  }

  /**
   * Returns TRUE when either user has blocked the other.
   */
  public function usersAreBlocked(int $first_uid, int $second_uid): bool {
    if (
      $first_uid <= 0 ||
      $second_uid <= 0 ||
      !$this->database->schema()->tableExists('flagging')
    ) {
      return FALSE;
    }

    $query = $this->database->select('flagging', 'f');
    $query->addExpression('1');
    $query->condition('flag_id', 'block_user');
    $or = $query->orConditionGroup();
    $first_blocks_second = $query->andConditionGroup()
      ->condition('uid', $first_uid)
      ->condition('entity_id', $second_uid);
    $second_blocks_first = $query->andConditionGroup()
      ->condition('uid', $second_uid)
      ->condition('entity_id', $first_uid);
    $or->condition($first_blocks_second);
    $or->condition($second_blocks_first);
    $query->condition($or);
    $query->range(0, 1);

    return (bool) $query->execute()->fetchField();
  }

  /**
   * Returns TRUE when the actor has blocked the target.
   */
  public function userBlocked(int $actor_uid, int $target_uid): bool {
    if (
      $actor_uid <= 0 ||
      $target_uid <= 0 ||
      !$this->database->schema()->tableExists('flagging')
    ) {
      return FALSE;
    }

    $query = $this->database->select('flagging', 'f');
    $query->addExpression('1');
    $query->condition('flag_id', 'block_user');
    $query->condition('uid', $actor_uid);
    $query->condition('entity_id', $target_uid);
    $query->range(0, 1);

    return (bool) $query->execute()->fetchField();
  }

  /**
   * Returns TRUE when one user currently follows another.
   */
  public function userFollows(int $actor_uid, int $target_uid): bool {
    if (
      $actor_uid <= 0 ||
      $target_uid <= 0 ||
      !$this->database->schema()->tableExists('flagging')
    ) {
      return FALSE;
    }

    $query = $this->database->select('flagging', 'f');
    $query->addExpression('1');
    $query->condition('flag_id', 'follow_user');
    $query->condition('uid', $actor_uid);
    $query->condition('entity_id', $target_uid);
    $query->range(0, 1);

    return (bool) $query->execute()->fetchField();
  }

}
