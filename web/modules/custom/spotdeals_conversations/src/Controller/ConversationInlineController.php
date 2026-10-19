<?php

declare(strict_types=1);

namespace Drupal\spotdeals_conversations\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Renders the existing Conversation inside a profile activity item. */
final class ConversationInlineController extends ControllerBase {

  private const PAGE_SIZE = 10;

  private function assertViewable(NodeInterface $node): void {
    if ($node->bundle() !== 'conversation' || !$node->isPublished() || !$node->access('view')) {
      throw new NotFoundHttpException();
    }
  }

  /** Builds all visible replies, irrespective of who the viewer follows. */
  private function replies(NodeInterface $node, int $offset, int $parent = 0, string $target = ''): array {
    $storage = $this->entityTypeManager()->getStorage('comment');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->condition('comment_type', 'conversation_replies')
      ->condition('status', 1);
    // Drupal stores root comments with a NULL parent ID. Some existing
    // comments may use 0, so support both without changing stored data.
    if ($parent === 0) {
      $roots = $query->orConditionGroup()
        ->notExists('pid')
        ->condition('pid', 0);
      $query->condition($roots);
    }
    else {
      $query->condition('pid', $parent);
    }
    $ids = $query
      ->sort('created', 'DESC')
      ->sort('cid', 'DESC')
      ->range($offset, self::PAGE_SIZE + 1)
      ->execute();
    $comments = array_values($storage->loadMultiple($ids));
    $hasMore = count($comments) > self::PAGE_SIZE;
    $comments = array_slice($comments, 0, self::PAGE_SIZE);
    $items = [];
    foreach ($comments as $comment) {
      if (!$comment->access('view')) {
        continue;
      }
      $cid = (int) $comment->id();
      $child_count = (int) $storage->getQuery()->accessCheck(TRUE)
        ->condition('entity_type', 'node')->condition('entity_id', $node->id())
        ->condition('comment_type', 'conversation_replies')->condition('status', 1)
        ->condition('pid', $cid)->count()->execute();
      $item = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-inline-conversation__reply'], 'data-comment-id' => (string) $cid],
        'author' => ['#type' => 'html_tag', '#tag' => 'strong', '#plain_text' => $comment->getOwner()->getDisplayName()],
        'body' => ['#type' => 'html_tag', '#tag' => 'p', '#plain_text' => trim(strip_tags((string) ($comment->get('comment_body')->value ?? '')))],
        'reply_action' => [
          '#type' => 'html_tag', '#tag' => 'button', '#value' => $this->t('Reply'),
          '#attributes' => ['type' => 'button', 'class' => ['sd-inline-thread-reply'],
            'data-sd-reply-to' => (string) $cid,
            'data-reply-author' => $comment->getOwner()->getDisplayName()],
        ],
      ];
      if ($child_count > 0) {
        $item['children'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['sd-inline-thread-children'], 'data-thread-parent' => (string) $cid],
        ];
        $item['more_children'] = $this->threadButton($node, $target, $cid, 0, $child_count);
      }
      $items[] = $item;
    }
    return [$items, $hasMore];
  }

  /** A button that loads only the next children of one parent comment. */
  private function threadButton(NodeInterface $node, string $target, int $parent, int $offset, int $remaining): array {
    return [
      '#type' => 'html_tag', '#tag' => 'button',
      '#value' => $this->formatPlural($remaining, 'View 1 reply', 'View @count replies'),
      '#attributes' => [
        'type' => 'button', 'class' => ['sd-inline-thread-more'],
        'data-sd-thread-more' => '', 'data-thread-parent' => (string) $parent,
        'data-inline-url' => \Drupal\Core\Url::fromRoute('spotdeals_conversations.inline_more',
          ['node' => $node->id()], ['query' => ['parent' => $parent, 'offset' => $offset]])->toString(),
        'data-target' => $target,
      ],
    ];
  }

  /** A reusable panel, also used after an AJAX reply submission. */
  public function panel(NodeInterface $node, string $target, int $visible = self::PAGE_SIZE): array {
    $this->assertViewable($node);
    $visible = max(self::PAGE_SIZE, min(500, $visible));
    $all = [];
    $more = FALSE;
    for ($offset = 0; $offset < $visible; $offset += self::PAGE_SIZE) {
      [$batch, $more] = $this->replies($node, $offset, 0, $target);
      $all = array_merge($all, $batch);
      if (!$more) {
        break;
      }
    }
    $reply_count = (int) $this->entityTypeManager()->getStorage('comment')->getQuery()
      ->accessCheck(TRUE)
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->condition('comment_type', 'conversation_replies')
      ->condition('status', 1)
      ->count()
      ->execute();
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sd-inline-conversation']],
      'body' => ['#type' => 'html_tag', '#tag' => 'p', '#plain_text' => trim(strip_tags((string) ($node->get('field_conversation_body')->value ?? '')))],
      'votes' => $this->voteControls($node, $reply_count),
      'reply_view' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-inline-reply-view'], 'data-sd-reply-view' => ''],
        'toggle' => [
          '#type' => 'html_tag', '#tag' => 'button', '#value' => $this->t('Newest') . ' ⌄',
          '#attributes' => ['type' => 'button', 'class' => ['sd-inline-reply-view__toggle'],
            'data-sd-view-toggle' => '', 'aria-expanded' => 'false'],
        ],
        'choices' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['sd-inline-reply-view__choices'], 'data-sd-view-choices' => '', 'hidden' => 'hidden'],
          'newest' => [
            '#type' => 'html_tag', '#tag' => 'label',
            'input' => [
              '#type' => 'html_tag', '#tag' => 'input',
              '#attributes' => [
                'type' => 'radio', 'name' => 'sd-view-' . (int) $node->id(),
                'value' => 'newest', 'checked' => 'checked',
              ],
            ],
            'text' => ['#plain_text' => ' ' . $this->t('Newest')],
          ],
          'all' => [
            '#type' => 'html_tag', '#tag' => 'label',
            'input' => [
              '#type' => 'html_tag', '#tag' => 'input',
              '#attributes' => [
                'type' => 'radio', 'name' => 'sd-view-' . (int) $node->id(),
                'value' => 'all',
              ],
            ],
            'text' => ['#plain_text' => ' ' . $this->t('All comments')],
          ],
          'relevant' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $this->t('Most relevant (coming later)'),
            '#attributes' => ['class' => ['sd-inline-reply-view__unavailable']]],
        ],
        'status' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => '',
          '#attributes' => ['class' => ['sd-inline-reply-view__status'], 'role' => 'status', 'data-sd-view-status' => '']],
      ],
      'replies' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-inline-conversation__replies']],
        'items' => $all ?: ['#markup' => '<p>' . $this->t('No replies yet.') . '</p>'],
      ],
      '#cache' => ['max-age' => 0],
    ];
    if ($more) {
      $build['load_more'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Load more replies'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['sd-inline-conversation__load-more'],
          'data-sd-inline-load-more' => '',
          'data-inline-url' => \Drupal\Core\Url::fromRoute('spotdeals_conversations.inline_more', ['node' => $node->id()], ['query' => ['offset' => $visible]])->toString(),
          'data-target' => $target,
        ],
      ];
    }
    $account = $this->currentUser();
    $comment = $this->entityTypeManager()->getStorage('comment')->create([
      'entity_type' => 'node', 'entity_id' => $node->id(),
      'field_name' => 'field_conversation_replies', 'comment_type' => 'conversation_replies',
    ]);
    if ($account->isAuthenticated() && (int) $node->get('field_conversation_replies')->status === 2
      && $account->hasPermission('post comments') && $comment->access('create')) {
      $build['form'] = $this->formBuilder()->getForm('Drupal\spotdeals_conversations\Form\InlineReplyForm', $node, $target);
    }
    else {
      $build['full_link'] = [
        '#type' => 'link', '#title' => $this->t('Open conversation'), '#url' => $node->toUrl(),
      ];
    }
    return $build;
  }

  /** Voting controls for the original conversation. */
  private function voteControls(NodeInterface $node, int $reply_count): array {
    $state = ConversationVoteController::voteState((int) $node->id(), (int) $this->currentUser()->id());
    $authenticated = $this->currentUser()->isAuthenticated();
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['sd-inline-conversation__votes'],
        'data-sd-conversation-votes' => '',
        'data-vote-url' => \Drupal\Core\Url::fromRoute('spotdeals_conversations.vote', ['node' => $node->id()])->toString(),
      ],
    ];
    foreach ([1 => '👍', -1 => '👎'] as $value => $symbol) {
      $build['vote_' . ($value === 1 ? 'up' : 'down')] = [
        '#type' => 'html_tag', '#tag' => 'button',
        '#value' => $symbol . ' ' . ($value === 1 ? $state['up'] : $state['down']),
        '#attributes' => [
          'type' => 'button', 'class' => ['sd-inline-conversation__vote'],
          'data-vote-value' => (string) $value,
          'aria-pressed' => $state['mine'] === $value ? 'true' : 'false',
          'aria-label' => $value === 1 ? $this->t('Upvote conversation') : $this->t('Downvote conversation'),
          'disabled' => $authenticated ? NULL : 'disabled',
        ],
      ];
    }
    $build['reply_count'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => '💬 ' . $this->formatPlural($reply_count, '1 reply', '@count replies'),
      '#attributes' => [
        'class' => ['sd-inline-conversation__reply-count'],
        'type' => 'button',
        'data-sd-toggle-replies' => '',
        'aria-expanded' => 'false',
        'aria-label' => $this->formatPlural($reply_count, '1 reply', '@count replies'),
      ],
    ];
    // Share the conversation itself, not the containing profile page.
    // Use the node's canonical URL so configured path aliases are respected.
    $build['share'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => '↗ ' . $this->t('Share'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['sd-inline-conversation__share'],
        'data-sd-share-conversation' => '',
        'data-share-url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'data-share-title' => $node->label(),
        'aria-label' => $this->t('Share conversation'),
      ],
    ];
    if (!$authenticated) {
      $build['login'] = ['#type' => 'link', '#title' => $this->t('Log in to vote'), '#url' => \Drupal\Core\Url::fromRoute('user.login')];
    }
    return $build;
  }

  public function preview(NodeInterface $node, Request $request): AjaxResponse {
    $this->assertViewable($node);
    $target = (string) $request->query->get('target', '');
    if (!preg_match('/^sd-activity-inline-\d+$/', $target)) {
      throw new NotFoundHttpException();
    }
    $visible = max(self::PAGE_SIZE, min(500, (int) $request->query->get('visible', self::PAGE_SIZE)));
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('#' . $target, $this->panel($node, $target, $visible)));
    return $response;
  }

  /** Appends the next ten children of a specific parent, or top-level replies. */
  public function more(NodeInterface $node, Request $request): AjaxResponse {
    $this->assertViewable($node);
    $target = (string) $request->query->get('target', '');
    $offset = filter_var($request->query->get('offset'), FILTER_VALIDATE_INT);
    $parent = filter_var($request->query->get('parent', 0), FILTER_VALIDATE_INT);
    if (!preg_match('/^sd-activity-inline-\d+$/', $target)
      || $offset === FALSE || $offset < 0 || $offset % self::PAGE_SIZE !== 0
      || $parent === FALSE || $parent < 0 || ($parent === 0 && $offset < self::PAGE_SIZE)) {
      throw new NotFoundHttpException();
    }
    if ($parent > 0) {
      $comment = $this->entityTypeManager()->getStorage('comment')->load($parent);
      if (!$comment || !$comment->access('view') || !$comment->isPublished()
        || $comment->getEntityTypeId() !== 'comment'
        || $comment->getCommentedEntityId() != $node->id()
        || $comment->bundle() !== 'conversation_replies') {
        throw new NotFoundHttpException();
      }
    }
    [$items, $has_more] = $this->replies($node, $offset, $parent, $target);
    $response = new AjaxResponse();
    if ($parent > 0) {
      $selector = '#' . $target . ' [data-thread-parent="' . $parent . '"]';
      $response->addCommand(new AppendCommand($selector . '.sd-inline-thread-children', $items));
      $button_selector = '#' . $target . ' button[data-sd-thread-more][data-thread-parent="' . $parent . '"]';
      $next = $has_more ? $this->threadButton($node, $target, $parent, $offset + self::PAGE_SIZE, self::PAGE_SIZE) : ['#markup' => ''];
      $response->addCommand(new ReplaceCommand($button_selector, $next));
    }
    else {
      if ($items) {
        $response->addCommand(new AppendCommand('#' . $target . ' .sd-inline-conversation__replies', $items));
      }
      $next = $has_more ? [
        '#type' => 'html_tag', '#tag' => 'button', '#value' => $this->t('Load more replies'),
        '#attributes' => ['type' => 'button', 'class' => ['sd-inline-conversation__load-more'],
          'data-sd-inline-load-more' => '',
          'data-inline-url' => \Drupal\Core\Url::fromRoute('spotdeals_conversations.inline_more',
            ['node' => $node->id()], ['query' => ['offset' => $offset + self::PAGE_SIZE]])->toString(),
          'data-target' => $target],
      ] : ['#markup' => ''];
      $response->addCommand(new ReplaceCommand('#' . $target . ' .sd-inline-conversation__load-more', $next));
    }
    return $response;
  }

}
