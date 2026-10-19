<?php

declare(strict_types=1);

namespace Drupal\spotdeals_conversations\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;

/** Inline profile-only conversation composer. */
final class ProfileConversationForm extends FormBase {

  public function getFormId(): string {
    return 'spotdeals_conversations_profile_create';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#prefix'] = '<div id="sd-profile-create-form-wrapper">';
    $form['#suffix'] = '</div>';
    $form['#attributes']['class'][] = 'sd-profile-create-form';
    if ($form_state->hasAnyErrors()) {
      $form['error_notice'] = ['#markup' => '<p role="alert">' . $this->t('Please correct the form and try again.') . '</p>'];
    }
    $form['title'] = ['#type' => 'textfield', '#title' => $this->t('Conversation title'), '#required' => TRUE, '#maxlength' => 255];
    // Match the Conversation content type's configured Address field instead of
    // collecting an unvalidated free-text location.
    $address_definition = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', 'conversation')['field_address'];
    $address_settings = $address_definition->getSettings();
    $form['field_address'] = [
      '#type' => 'address',
      '#title' => $address_definition->getLabel(),
      '#required' => $address_definition->isRequired(),
      '#default_value' => $form_state->getValue('field_address') ?: ['country_code' => 'US'],
      '#available_countries' => $address_settings['available_countries'] ?? [],
      // Only collect the three geographic components used by conversations.
      // Address expects field override enum strings, not field settings arrays.
      '#field_overrides' => [
        'givenName' => 'hidden',
        'additionalName' => 'hidden',
        'familyName' => 'hidden',
        'organization' => 'hidden',
        'addressLine1' => 'hidden',
        'addressLine2' => 'hidden',
        'addressLine3' => 'hidden',
        'dependentLocality' => 'hidden',
        'postalCode' => 'hidden',
        'sortingCode' => 'hidden',
      ],
    ];
    // The inline Address element does not reliably render the field's help
    // text. Show the configured description below its country/city/state inputs.
    $location_description = trim((string) $address_definition->getDescription());
    if ($location_description !== '') {
      $form['field_address_help'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sd-profile-create-form__location-help']],
        'text' => [
          '#markup' => \Drupal\Component\Utility\Xss::filterAdmin($location_description),
        ],
      ];
    }
    $form['body'] = ['#type' => 'textarea', '#title' => $this->t('Tell us more (optional)'), '#required' => FALSE, '#rows' => 5];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['publish'] = [
      '#type' => 'submit', '#value' => $this->t('Publish'),
      '#ajax' => ['callback' => '::ajaxPublish', 'wrapper' => 'sd-profile-create-form-wrapper'],
    ];
    $form['actions']['cancel'] = ['#type' => 'button', '#value' => $this->t('Cancel'), '#attributes' => ['data-sd-create-cancel' => '']];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->isAuthenticated() || !$this->currentUser()->hasPermission('create conversation content')) {
      $form_state->setErrorByName('title', $this->t('You cannot publish conversations.'));
      return;
    }
    $address = (array) $form_state->getValue('field_address', []);
    if (empty($address['country_code']) || empty($address['administrative_area']) || empty($address['locality'])) {
      $form_state->setErrorByName('field_address', $this->t('Please provide a country, state and city.'));
    }
    if (mb_strlen(trim((string) $form_state->getValue('body'))) > 10000) {
      $form_state->setErrorByName('body', $this->t('Please use 10,000 characters or fewer.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $node = $storage->create([
      'type' => 'conversation', 'uid' => $this->currentUser()->id(), 'status' => 1,
      'title' => trim((string) $form_state->getValue('title')),
      'field_conversation_replies' => ['status' => 2],
      'field_address' => (array) $form_state->getValue('field_address', []),
      'field_conversation_body' => ['value' => trim((string) $form_state->getValue('body')), 'format' => 'plain_text'],
    ]);
    if (!$node->access('create')) {
      $form_state->setErrorByName('title', $this->t('You cannot create this conversation.'));
      return;
    }
    $violations = $node->validate();
    if ($violations->count()) {
      foreach ($violations as $violation) {
        $form_state->setErrorByName(str_starts_with((string) $violation->getPropertyPath(), 'field_address') ? 'field_address' : 'body', $this->t('Conversation could not be published: @message', ['@message' => $violation->getMessage()]));
      }
      return;
    }
    try {
      $node->save();
      $form_state->set('created_node_id', (int) $node->id());
      $form_state->set('created_title', $node->label());
      $form_state->setValue('title', '');
      $form_state->setValue('body', '');
      $form_state->setValue('field_address', []);
      // The AJAX rebuild must not reuse the submitted browser input.
      // Clearing values alone does not clear FormState's raw user input.
      // Preserve Drupal AJAX metadata (ajax_form, form_build_id, trigger, etc.).
      // Clearing all user input makes Drupal return HTML instead of AJAX JSON.
      $input = $form_state->getUserInput();
      unset($input['title'], $input['body'], $input['field_address']);
      $form_state->setUserInput($input);
      $form_state->setRebuild(TRUE);
    }
    catch (\Throwable $e) {
      \Drupal::logger('spotdeals_conversations')->error('Profile conversation creation failed: @error', ['@error' => $e->getMessage()]);
      $form_state->setErrorByName('body', $this->t('Could not publish. Please try again.'));
    }
  }

  public function ajaxPublish(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#sd-profile-create-form-wrapper', $form));
    $nid = (int) $form_state->get('created_node_id');
    if ($nid && !$form_state->hasAnyErrors()) {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
      $title = Html::escape((string) $node->label());
      $url = \Drupal\Core\Url::fromRoute('spotdeals_conversations.inline_preview', ['node' => $nid])->toString();
      $url = Html::escape($url);
      $id = 'sd-new-conversation-' . $nid;
      // The preview endpoint accepts only sd-activity-inline-N panel targets.
      $panel_id = 'sd-activity-inline-' . $nid;
      $markup = '<div class="sd-profile__activity sd-profile__activity--expandable sd-profile__activity--new" id="' . $id . '">' .
        '<span class="sd-profile__activity-icon sd-profile__activity-icon--conversation">💬</span>' .
        '<div><small>' . $this->t('Conversation published!') . '</small>' .
        '<button type="button" class="sd-profile__activity-trigger" data-sd-inline-conversation data-inline-url="' . $url . '" aria-expanded="false" aria-controls="' . $panel_id . '">' . $title . '</button></div>' .
        '<time>' . $this->t('Just now') . '</time><div id="' . $panel_id . '" class="sd-profile__activity-expand" hidden role="region" aria-label="' . $title . '"></div></div>';
      $response->addCommand(new PrependCommand('[data-sd-activity-list]', $markup));
      // Reset the rebuilt form itself, not just the old DOM fields. This also
      // works if Drupal's behavior reattachment runs after the AJAX commands.
      $response->addCommand(new InvokeCommand('#sd-profile-create-form-wrapper input[type="text"], #sd-profile-create-form-wrapper textarea', 'val', ['']));
      $response->addCommand(new InvokeCommand('#sd-profile-create-form-wrapper select[name*="administrative_area"]', 'val', ['']));
      $response->addCommand(new InvokeCommand('#sd-profile-create-form-wrapper select[name*="country_code"]', 'val', ['US']));
      // Explicitly close the composer after successful AJAX publication.
      // The new activity entry must not depend on behavior reattachment timing.
      $response->addCommand(new InvokeCommand('#sd-profile-create-form', 'attr', ['hidden', 'hidden']));
      $response->addCommand(new InvokeCommand('[data-sd-create-toggle]', 'attr', ['aria-expanded', 'false']));

    }
    return $response;
  }
}
