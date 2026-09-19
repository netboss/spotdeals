<?php

declare(strict_types=1);

namespace Drupal\spotdeals_social\Cache;

/**
 * Defines cache tags used by SpotDeals social activity.
 */
final class SocialActivityCache {

  /**
   * Cache tag for activity performed by a user.
   */
  public static function activityTag(int $uid): string {
    return 'spotdeals_social_activity:' . $uid;
  }

  /**
   * Cache tag for the composition of a user's following timeline.
   */
  public static function timelineTag(int $uid): string {
    return 'spotdeals_social_timeline:' . $uid;
  }

}
