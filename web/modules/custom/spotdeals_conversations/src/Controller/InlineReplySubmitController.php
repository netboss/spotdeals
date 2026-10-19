<?php

declare(strict_types=1);

namespace Drupal\spotdeals_conversations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Dedicated JSON reply submission: no Form API AJAX rebuild or redirect. */
final class InlineReplySubmitController extends ControllerBase {

  public function submit(NodeInterface $node, Request $request): JsonResponse {
    if ($node->bundle() !== 'conversation' || !$node->isPublished() || !$node->access('view')) {
      return new JsonResponse(['error' => 'Conversation unavailable.'], 404);
    }
    $token = (string) $request->request->get('reply_token', '');
    if (!\Drupal::service('csrf_token')->validate($token, 'spotdeals_conversations.reply.' . $node->id())) {
      return new JsonResponse(['error' => 'Your session expired. Refresh the page and try again.'], 403);
    }
    if (!$this->currentUser()->isAuthenticated() || !$this->currentUser()->hasPermission('post comments') ||
      (int) $node->get('field_conversation_replies')->status !== 2) {
      return new JsonResponse(['error' => 'You cannot reply to this conversation.'], 403);
    }
    $reply = trim((string) $request->request->get('reply', ''));
    if ($reply === '' || mb_strlen($reply) > 10000) {
      return new JsonResponse(['error' => 'Enter a reply (up to 10,000 characters).'], 422);
    }
    $parent_id = filter_var($request->request->get('parent_id', '0'), FILTER_VALIDATE_INT);
    if ($parent_id === FALSE || $parent_id < 0) {
      return new JsonResponse(['error' => 'Invalid parent reply.'], 422);
    }
    if ($parent_id > 0) {
      $parent = $this->entityTypeManager()->getStorage('comment')->load($parent_id);
      if (!$parent || !$parent->isPublished() || !$parent->access('view')
        || $parent->getCommentedEntityId() != $node->id()
        || $parent->bundle() !== 'conversation_replies') {
        return new JsonResponse(['error' => 'Parent reply is unavailable.'], 422);
      }
    }
    $comment = $this->entityTypeManager()->getStorage('comment')->create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'field_conversation_replies',
      'comment_type' => 'conversation_replies',
      'uid' => $this->currentUser()->id(),
      'subject' => '',
      'pid' => $parent_id,
      'comment_body' => ['value' => $reply, 'format' => 'plain_text'],
    ]);
    if (!$comment->access('create')) {
      return new JsonResponse(['error' => 'You cannot post this reply.'], 403);
    }
    try {
      $comment->save();
    }
    catch (\Throwable $e) {
      \Drupal::logger('spotdeals_conversations')->error('Inline reply failed: @error', ['@error' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Could not save the reply. Please try again.'], 500);
    }
    return new JsonResponse(['saved' => TRUE, 'comment_id' => (int) $comment->id()]);
  }
}
