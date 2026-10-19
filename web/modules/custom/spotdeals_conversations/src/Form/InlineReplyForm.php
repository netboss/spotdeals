<?php

declare(strict_types=1);

namespace Drupal\spotdeals_conversations\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/** Reply form submitted by the activity-inline behavior, not Drupal AJAX. */
final class InlineReplyForm extends FormBase {

  public function getFormId(): string {
    return 'spotdeals_conversations_inline_reply';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, string $target = ''): array {
    if (!$node || $node->bundle() !== 'conversation' || !$node->isPublished() || !$node->access('view')) {
      return [];
    }
    $form['#action'] = Url::fromRoute('spotdeals_conversations.inline_reply', ['node' => $node->id()])->toString();
    $form['#attributes']['class'][] = 'sd-inline-conversation__form';
    $form['#attributes']['data-sd-inline-reply-form'] = '';
    $form['#attributes']['data-target'] = $target;
    $form['#attributes']['data-preview-url'] = Url::fromRoute('spotdeals_conversations.inline_preview', ['node' => $node->id()])->toString();
    $form['reply_token'] = [
      '#type' => 'hidden',
      '#value' => \Drupal::service('csrf_token')->get('spotdeals_conversations.reply.' . $node->id()),
    ];
    $form['parent_id'] = ['#type' => 'hidden', '#value' => '0'];
    $form['reply'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Write a reply'),
      '#required' => TRUE,
      '#rows' => 3,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Post reply'),
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // The browser submits to the dedicated endpoint; this is a render-only form.
  }
}
