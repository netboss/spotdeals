<?php

namespace Drupal\spotdeals_social\EventSubscriber;

use Drupal\flag\Event\FlagEvents;
use Drupal\flag\Event\FlaggingEvent;
use Drupal\flag\Event\UnflaggingEvent;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\spotdeals_social\Cache\SocialActivityCache;
use Drupal\spotdeals_social\Service\SocialPrivacyManager;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Enforces SpotDeals social relationship rules.
 */
final class SocialFlagSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the social flag subscriber.
   */
  public function __construct(
    private readonly FlagServiceInterface $flagService,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly SocialPrivacyManager $privacyManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      FlagEvents::ENTITY_FLAGGED => ['onEntityFlagged'],
      FlagEvents::ENTITY_UNFLAGGED => ['onEntityUnflagged'],
    ];
  }

  /**
   * Enforces follow/block invariants after a social flag is created.
   */
  public function onEntityFlagged(FlaggingEvent $event): void {
    $flagging = $event->getFlagging();
    $flag = $flagging->getFlag();
    $flag_id = $flag->id();

    if (!in_array($flag_id, ['follow_user', 'block_user'], TRUE)) {
      return;
    }

    $actor = $flagging->getOwner();
    $target = $flagging->getFlaggable();

    if (!$actor instanceof UserInterface || !$target instanceof UserInterface) {
      return;
    }

    $this->invalidateRelationshipTimelines($actor, $target);

    // Flag itself is configured not to allow users to flag themselves. Keep a
    // defensive check here so the social invariants remain true if that config
    // is changed accidentally later.
    if ((int) $actor->id() === (int) $target->id()) {
      $this->flagService->unflag($flag, $target, $actor);
      return;
    }

    if ($flag_id === 'block_user') {
      $this->removeFollowRelationships($actor, $target);
      return;
    }

    // A follow relationship cannot exist when either user has blocked the
    // other. A private target also cannot be approached unless that private
    // user deliberately opened the relationship by following the actor first.
    if (
      $this->usersAreBlocked($actor, $target) ||
      !$this->privacyManager->canInitiateInteraction($actor, $target)
    ) {
      $this->flagService->unflag($flag, $target, $actor);
    }
  }

  /**
   * Invalidates social timeline composition after a social flag is removed.
   */
  public function onEntityUnflagged(UnflaggingEvent $event): void {
    foreach ($event->getFlaggings() as $flagging) {
      if (!in_array($flagging->getFlag()->id(), ['follow_user', 'block_user'], TRUE)) {
        continue;
      }

      $actor = $flagging->getOwner();
      $target = $flagging->getFlaggable();
      if (!$actor instanceof UserInterface || !$target instanceof UserInterface) {
        continue;
      }

      $this->invalidateRelationshipTimelines($actor, $target);
    }
  }

  /**
   * Invalidates profile and timeline caches when the relationship graph changes.
   */
  private function invalidateRelationshipTimelines(UserInterface $first, UserInterface $second): void {
    $this->cacheTagsInvalidator->invalidateTags([
      SocialActivityCache::timelineTag((int) $first->id()),
      SocialActivityCache::timelineTag((int) $second->id()),
      'user:' . $first->id(),
      'user:' . $second->id(),
    ]);
  }

  /**
   * Returns TRUE when either user has blocked the other.
   */
  private function usersAreBlocked(UserInterface $first, UserInterface $second): bool {
    $block_flag = $this->flagService->getFlagById('block_user');

    if ($block_flag === NULL) {
      return FALSE;
    }

    return $this->flagService->getFlagging($block_flag, $second, $first) !== NULL
      || $this->flagService->getFlagging($block_flag, $first, $second) !== NULL;
  }

  /**
   * Removes follow relationships in both directions between two users.
   */
  private function removeFollowRelationships(UserInterface $first, UserInterface $second): void {
    $follow_flag = $this->flagService->getFlagById('follow_user');

    if ($follow_flag === NULL) {
      return;
    }

    if ($this->flagService->getFlagging($follow_flag, $second, $first) !== NULL) {
      $this->flagService->unflag($follow_flag, $second, $first);
    }

    if ($this->flagService->getFlagging($follow_flag, $first, $second) !== NULL) {
      $this->flagService->unflag($follow_flag, $first, $second);
    }
  }

}
