<?php

namespace Drupal\spotdeals_revenue\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for early SpotDeals promoted slot configuration.
 */
class SpotdealsRevenueSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'spotdeals_revenue_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['spotdeals_revenue.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('spotdeals_revenue.settings');

    $form['promoted_slot_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable promoted search slot'),
      '#description' => $this->t('Only search results pages use this slot. Venue and deal detail pages do not use promoted slots.'),
      '#default_value' => (bool) $config->get('promoted_slot_enabled'),
    ];

    $form['promoted_slot_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Promoted slot label'),
      '#default_value' => $config->get('promoted_slot_label') ?: $this->t('Sponsored'),
      '#maxlength' => 64,
    ];

    $form['promoted_slot_markup'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Promoted slot markup'),
      '#description' => $this->t('Temporary third-party ad markup or placeholder markup. Future featured listings should replace this content source without changing the search layout.'),
      '#default_value' => $config->get('promoted_slot_markup') ?: '',
      '#rows' => 8,
    ];

    $form['suggestions'] = [
      '#type' => 'details',
      '#title' => $this->t('Suggestion workflow'),
      '#open' => TRUE,
    ];

    $form['suggestions']['free_deals_per_venue'] = [
      '#type' => 'number',
      '#title' => $this->t('Free suggested deals per venue'),
      '#description' => $this->t('Deal suggestions above this limit are saved for admin review, but owner notification is used instead of auto-adding another free deal.'),
      '#default_value' => $config->get('free_deals_per_venue') ?? 1,
      '#min' => 0,
      '#step' => 1,
    ];

    $form['suggestions']['owner_notifications_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Notify claimed venue owners about gated deal suggestions'),
      '#description' => $this->t('When a deal is suggested for an existing claimed venue that has reached the free suggested-deal limit, SpotDeals attempts to email the venue owner.'),
      '#default_value' => $config->get('owner_notifications_enabled') !== FALSE,
    ];

    $form['suggestions']['captcha_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Suggestion form CAPTCHA'),
      '#markup' => $this->buildCaptchaStatusMarkup(),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('spotdeals_revenue.settings')
      ->set('promoted_slot_enabled', (bool) $form_state->getValue('promoted_slot_enabled'))
      ->set('promoted_slot_label', trim((string) $form_state->getValue('promoted_slot_label')) ?: 'Sponsored')
      ->set('promoted_slot_markup', trim((string) $form_state->getValue('promoted_slot_markup')))
      ->set('free_deals_per_venue', max(0, (int) $form_state->getValue('free_deals_per_venue')))
      ->set('owner_notifications_enabled', (bool) $form_state->getValue('owner_notifications_enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }


  /**
   * Builds an admin-only status note for the existing CAPTCHA integration.
   */
  private function buildCaptchaStatusMarkup(): string {
    if (!\Drupal::moduleHandler()->moduleExists('captcha')) {
      return (string) $this->t('CAPTCHA module is not enabled. Enable/configure the existing CAPTCHA/reCAPTCHA module before production.');
    }

    $captcha_point = \Drupal::config('captcha.captcha_point.spotdeals_suggestion_form');
    if ($captcha_point && !$captcha_point->isNew()) {
      return (string) $this->t('Protected through the existing CAPTCHA configuration for form ID: spotdeals_suggestion_form.');
    }

    return (string) $this->t('CAPTCHA module is enabled, but no CAPTCHA point was found for form ID: spotdeals_suggestion_form. Add this form ID in the existing CAPTCHA/reCAPTCHA configuration.');
  }

}
