<?php

declare(strict_types=1);

namespace Drupal\spotdeals_social\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\spotdeals_social\Service\SocialRelationshipManager;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays public follower and following lists.
 */
final class SocialRelationshipController extends ControllerBase {

  public function __construct(
    private readonly SocialRelationshipManager $relationshipManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_social.relationship_manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Checks access to follower/following lists.
   */
  public function access(UserInterface $user, AccountInterface $account): AccessResult {
    if (!$account->hasPermission('access user profiles')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $allowed = $this->relationshipManager->canViewRelationships($user);
    $result = $allowed ? AccessResult::allowed() : AccessResult::forbidden();
    $result->addCacheableDependency($user);
    $result->addCacheContexts(['user', 'user.permissions']);
    return $result;
  }

  /**
   * Displays users who follow the profile owner.
   */
  public function followers(UserInterface $user): array {
    return $this->buildList(
      $user,
      $this->relationshipManager->followers($user),
      $this->t('Followers'),
      $this->t('People who follow @name on SpotDeals.', ['@name' => $user->getDisplayName()]),
    );
  }

  /**
   * Displays users followed by the profile owner.
   */
  public function following(UserInterface $user): array {
    return $this->buildList(
      $user,
      $this->relationshipManager->following($user),
      $this->t('Following'),
      $this->t('People @name follows on SpotDeals.', ['@name' => $user->getDisplayName()]),
    );
  }

  /**
   * Checks access to the owner's blocked-user management page.
   */
  public function blockedAccess(UserInterface $user, AccountInterface $account): AccessResult {
    if (!$account->hasPermission('access user profiles')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $allowed = (int) $account->id() === (int) $user->id()
      || $account->hasPermission('administer users');

    return ($allowed ? AccessResult::allowed() : AccessResult::forbidden())
      ->addCacheableDependency($user)
      ->addCacheContexts(['user', 'user.permissions']);
  }

  /**
   * Displays users blocked by the profile owner with an Unblock control.
   */
  public function blocked(UserInterface $user): array {
    $users = $this->relationshipManager->blockedUsers($user);
    $rows = [];

    foreach ($users as $blocked_user) {
      $display_name = $blocked_user->getDisplayName();
      $initial = function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($display_name, 0, 1))
        : strtoupper(substr($display_name, 0, 1));

      $rows[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-social-relationships__person']],
        'avatar' => [
          '#markup' => '<span class="sd-social-relationships__avatar" aria-hidden="true">' . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') . '</span>',
        ],
        'identity' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['sd-social-relationships__identity']],
          'name' => [
            '#markup' => '<strong class="sd-social-relationships__name">' . htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8') . '</strong>',
          ],
          'member' => [
            '#markup' => '<span class="sd-social-relationships__member">' . $this->t('Member since @date', [
              '@date' => $this->dateFormatter->format((int) $blocked_user->getCreatedTime(), 'custom', 'M j, Y'),
            ]) . '</span>',
          ],
        ],
        'unblock' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['sd-social-relationships__view']],
          'link' => [
            '#lazy_builder' => [
              'flag.link_builder:build',
              [
                $blocked_user->getEntityTypeId(),
                $blocked_user->id(),
                'block_user',
                'full',
              ],
            ],
            '#create_placeholder' => TRUE,
          ],
        ],
      ];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sd-social-relationships']],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('← Back to @name', ['@name' => $user->getDisplayName()]),
        '#url' => $user->toUrl(),
        '#attributes' => ['class' => ['sd-social-relationships__back']],
      ],
      'card' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-social-relationships__card']],
        'header' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['sd-social-relationships__header']],
          'profile' => [
            '#markup' => '<div class="sd-social-relationships__profile">' . $this->t('@name’s account', [
              '@name' => $user->getDisplayName(),
            ]) . '</div>',
          ],
          'title_row' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['sd-social-relationships__title-row']],
            'heading' => [
              '#markup' => '<h1 class="sd-social-relationships__title">' . $this->t('Blocked users') . '</h1>',
            ],
            'count' => [
              '#markup' => '<span class="sd-social-relationships__count">' . count($users) . '</span>',
            ],
          ],
          'description' => [
            '#markup' => '<p class="sd-social-relationships__description">' . $this->t('People you have blocked. Unblocking someone removes only your block.') . '</p>',
          ],
        ],
        'body' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['sd-social-relationships__body']],
        ],
      ],
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'tags' => $this->relationshipManager->getCacheTags($user),
      ],
    ];

    if ($rows) {
      $build['card']['body']['list'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-social-relationships__list']],
        'people' => $rows,
      ];
    }
    else {
      $build['card']['body']['empty'] = [
        '#markup' => '<div class="sd-social-relationships__empty"><strong>' . $this->t('No blocked users.') . '</strong><span>' . $this->t('People you block will appear here.') . '</span></div>',
      ];
    }

    foreach ($users as $blocked_user) {
      $build['#cache']['tags'][] = 'user:' . $blocked_user->id();
    }
    $build['#cache']['tags'] = array_values(array_unique($build['#cache']['tags']));

    return $build;
  }

  /**
   * Builds a relationship list render array.
   *
   * @param \Drupal\user\UserInterface $account
   *   Profile owner.
   * @param \Drupal\user\UserInterface[] $users
   *   Users to display.
   * @param mixed $heading
   *   List heading.
   * @param mixed $description
   *   List description.
   */
  private function buildList(
    UserInterface $account,
    array $users,
    mixed $heading,
    mixed $description,
  ): array {
    $rows = [];
    foreach ($users as $user) {
      $display_name = $user->getDisplayName();
      $initial = function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($display_name, 0, 1))
        : strtoupper(substr($display_name, 0, 1));

      $rows[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-social-relationships__person']],
        'avatar' => [
          '#markup' => '<span class="sd-social-relationships__avatar" aria-hidden="true">' . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') . '</span>',
        ],
        'identity' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['sd-social-relationships__identity']],
          'name' => [
            '#type' => 'link',
            '#title' => $display_name,
            '#url' => $user->toUrl(),
            '#attributes' => ['class' => ['sd-social-relationships__name']],
          ],
          'member' => [
            '#markup' => '<span class="sd-social-relationships__member">' . $this->t('Member since @date', [
              '@date' => $this->dateFormatter->format((int) $user->getCreatedTime(), 'custom', 'M j, Y'),
            ]) . '</span>',
          ],
        ],
        'view' => [
          '#type' => 'link',
          '#title' => $this->t('View profile'),
          '#url' => $user->toUrl(),
          '#attributes' => [
            'class' => ['sd-social-relationships__view'],
            'aria-label' => $this->t('View @name profile', ['@name' => $display_name]),
          ],
        ],
      ];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sd-social-relationships']],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('← Back to @name', ['@name' => $account->getDisplayName()]),
        '#url' => $account->toUrl(),
        '#attributes' => ['class' => ['sd-social-relationships__back']],
      ],
      'card' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-social-relationships__card']],
        'header' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['sd-social-relationships__header']],
          'profile' => [
            '#markup' => '<div class="sd-social-relationships__profile">' . $this->t('@name’s network', [
              '@name' => $account->getDisplayName(),
            ]) . '</div>',
          ],
          'title_row' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['sd-social-relationships__title-row']],
            'heading' => [
              '#markup' => '<h1 class="sd-social-relationships__title">' . $heading . '</h1>',
            ],
            'count' => [
              '#markup' => '<span class="sd-social-relationships__count">' . count($users) . '</span>',
            ],
          ],
          'description' => [
            '#markup' => '<p class="sd-social-relationships__description">' . $description . '</p>',
          ],
        ],
        'body' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['sd-social-relationships__body']],
        ],
      ],
    ];

    if ($rows) {
      $build['card']['body']['list'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-social-relationships__list']],
        'people' => $rows,
      ];
    }
    else {
      $build['card']['body']['empty'] = [
        '#markup' => '<div class="sd-social-relationships__empty"><strong>' . $this->t('No users to show yet.') . '</strong><span>' . $this->t('Social connections will appear here as they are added.') . '</span></div>',
      ];
    }

    $cacheability = new CacheableMetadata();
    $cacheability->setCacheTags($this->relationshipManager->getCacheTags($account));
    $cacheability->addCacheContexts(['user', 'user.permissions']);
    foreach ($users as $user) {
      $cacheability->addCacheableDependency($user);
    }
    $cacheability->applyTo($build);

    return $build;
  }

}
