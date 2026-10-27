<?php

namespace Drupal\spotdeals_home_bubbles\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Shows recent conversations and the existing inline conversation composer.
 *
 * @Block(
 *   id = "spotdeals_home_recent_conversations",
 *   admin_label = @Translation("SpotDeals recent conversations"),
 *   category = @Translation("SpotDeals")
 * )
 */
final class RecentConversationsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $account = \Drupal::currentUser();
    $can_create = $account->isAuthenticated() && $account->hasPermission('create conversation content');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'conversation')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 5)
      ->execute();

    $items = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $address = $node->get('field_address')->first();
      $location = $address ? trim((string) ($address->get('locality')->getValue() ?? '')) : '';
      $body = $node->hasField('field_conversation_body')
        ? trim(strip_tags((string) $node->get('field_conversation_body')->value)) : '';
      $items[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-recent-conversations__item']],
        'title' => [
          '#type' => 'link',
          '#title' => $node->label(),
          '#url' => $node->toUrl(),
          '#attributes' => ['class' => ['sd-recent-conversations__link']],
        ],
        'location' => [
          '#type' => 'html_tag',
          '#tag' => 'small',
          '#value' => $location,
        ],
        'excerpt' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => mb_strimwidth($body, 0, 135, '…'),
        ],
      ];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sd-recent-conversations']],
      '#attached' => ['library' => ['spotdeals_home_bubbles/home_composer']],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.path'],
        'tags' => ['node_list:conversation', 'node_list'],
        'max-age' => 0,
      ],
      'heading' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-recent-conversations__heading']],
        'desktop_title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Recent conversations'),
          '#attributes' => ['class' => ['block-title', 'sd-recent-conversations__desktop-title']],
        ],
        'toggle' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Recent conversations'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['sd-recent-conversations__heading-toggle'],
            'data-sd-recent-toggle' => '',
            'aria-controls' => 'sd-recent-conversations-list',
            'aria-expanded' => 'false',
          ],
        ],
      ],
    ];

    if ($can_create) {
      $build['composer'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-home-conversation-create'], 'data-sd-home-composer' => ''],
        'toggle' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t("What's on your mind?"),
          '#attributes' => [
            'type' => 'button',
            'class' => ['sd-home-conversation-create__toggle'],
            'data-sd-create-toggle' => '',
            'aria-controls' => 'sd-profile-create-form',
            'aria-expanded' => 'false',
          ],
        ],
        'panel' => [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'sd-profile-create-form',
            'class' => ['sd-home-conversation-create__panel'],
            'hidden' => 'hidden',
          ],
          'form' => \Drupal::formBuilder()->getForm(\Drupal\spotdeals_conversations\Form\ProfileConversationForm::class),
        ],
        'activity' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['sd-home-conversation-create__published'], 'data-sd-activity-list' => '', 'aria-live' => 'polite'],
        ],
      ];
    }

    $build['list'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'sd-recent-conversations-list',
        'class' => ['sd-recent-conversations__list'],
      ],
      'items' => $items ?: ['empty' => ['#markup' => $this->t('No conversations yet.')]],
    ];
    return $build;
  }
}
