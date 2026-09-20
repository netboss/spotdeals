<?php

declare(strict_types=1);

namespace Drupal\spotdeals_social\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\spotdeals_social\Service\SocialPrivacyManager;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the owner-only private-account toggle shown on user profiles.
 */
final class PrivacyToggleForm extends FormBase {

  public function __construct(
    protected SocialPrivacyManager $privacyManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_social.privacy_manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'spotdeals_social_privacy_toggle_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $user = NULL): array {
    if (!$user instanceof UserInterface || (int) $user->id() !== (int) $this->currentUser()->id()) {
      return [];
    }

    $private = $this->privacyManager->isPrivate($user);

    $form['#attributes']['class'][] = 'sd-profile__privacy-form';
    // CAPTCHA may add its administrative placement element to every Form API
    // form for privileged users. Strip it after form alters have run: this is an
    // owner-only preference control, not an untrusted public submission form.
    $form['#after_build'][] = '::removeCaptchaElement';
    $form['#prefix'] = '<div id="sd-profile-privacy-toggle">';
    $form['#suffix'] = '</div>';

    $form['private'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Private account'),
      '#default_value' => $private,
      '#attributes' => [
        'class' => ['sd-profile__privacy-checkbox'],
        'aria-describedby' => 'sd-profile-privacy-help',
      ],
      '#ajax' => [
        'callback' => '::ajaxToggle',
        'event' => 'change',
        'wrapper' => 'sd-profile-privacy-toggle',
        'progress' => ['type' => 'throbber'],
      ],
    ];

    $form['help'] = [
      '#markup' => '<span class="sd-profile__privacy-info" tabindex="0" aria-describedby="sd-profile-privacy-help" aria-label="' .
        $this->t('Private account information') . '">i</span>' .
        '<span id="sd-profile-privacy-help" class="sd-profile__privacy-help" role="tooltip">' .
        $this->t('You decide who can see and interact with your social profile.') .
        '</span>',
    ];

    return $form;
  }


  /**
   * Removes CAPTCHA's placement/challenge element from this owner-only form.
   */
  public function removeCaptchaElement(array $form, FormStateInterface $form_state): array {
    unset($form['captcha']);
    return $form;
  }

  /**
   * AJAX callback for the privacy checkbox.
   */
  public function ajaxToggle(array &$form, FormStateInterface $form_state): AjaxResponse {
    $account = $this->loadCurrentUser();
    if ($account instanceof UserInterface) {
      $this->privacyManager->setPrivate(
        $account,
        (bool) $form_state->getValue('private'),
      );
    }

    // The checkbox has already changed state in the browser. Do not replace
    // the form markup after saving, so the styled switch and tooltip remain
    // untouched by the AJAX response.
    return new AjaxResponse();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->loadCurrentUser();
    if (!$account instanceof UserInterface) {
      return;
    }

    $this->privacyManager->setPrivate(
      $account,
      (bool) $form_state->getValue('private'),
    );
  }

  /**
   * Loads the authenticated user independently of the embedded form build.
   */
  private function loadCurrentUser(): ?UserInterface {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      return NULL;
    }

    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    return $account instanceof UserInterface ? $account : NULL;
  }

}
