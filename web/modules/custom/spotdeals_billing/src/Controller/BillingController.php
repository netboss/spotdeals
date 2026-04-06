<?php

namespace Drupal\spotdeals_billing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\spotdeals_billing\Service\StripeBillingService;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Billing pages and Stripe redirect flows.
 */
class BillingController extends ControllerBase {

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUserProxy;

  /**
   * The Stripe billing service.
   *
   * @var \Drupal\spotdeals_billing\Service\StripeBillingService
   */
  protected $stripeBilling;

  /**
   * Constructs the controller.
   */
  public function __construct(AccountProxyInterface $current_user, StripeBillingService $stripe_billing) {
    $this->currentUserProxy = $current_user;
    $this->stripeBilling = $stripe_billing;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('spotdeals_billing.stripe_billing')
    );
  }

  /**
   * Upgrade page.
   */
  public function upgradePage() {
    $account = $this->entityTypeManager()->getStorage('user')->load($this->currentUserProxy->id());

    // Already Pro: redirect to billing portal.
    if ($account instanceof UserInterface && spotdeals_billing_user_has_pro_access($account)) {
      return new RedirectResponse(Url::fromRoute('spotdeals_billing.portal')->toString());
    }

    $monthly_url = Url::fromRoute('spotdeals_billing.checkout', ['plan' => 'monthly'])->toString();
    $yearly_url = Url::fromRoute('spotdeals_billing.checkout', ['plan' => 'yearly'])->toString();
    $billing_url = Url::fromRoute('spotdeals_billing.portal')->toString();

    $plan_tier = '';
    $plan_status = '';
    $plan_renew_at = '';

    if ($account instanceof UserInterface) {
      $plan_tier = trim((string) ($account->get('field_plan_tier')->value ?? ''));
      $plan_status = trim((string) ($account->get('field_plan_status')->value ?? ''));
      $plan_renew_at = trim((string) ($account->get('field_plan_renew_at')->value ?? ''));
    }

    $show_current_plan = ($plan_tier !== '' || $plan_status !== '');

    return [
      '#theme' => 'spotdeals_billing_upgrade_page',
      '#intro_text' => $this->t('Upgrade to Pro to manage unlimited listings and multiple locations.'),
      '#show_current_plan' => $show_current_plan,
      '#current_plan_label' => _spotdeals_billing_plan_label($plan_tier),
      '#current_status_label' => _spotdeals_billing_status_label($plan_status, $plan_renew_at),
      '#monthly_title' => $this->t('Pro Monthly'),
      '#monthly_price' => $this->t('$19.99 / month'),
      '#monthly_cta_text' => $this->t('Choose monthly'),
      '#monthly_url' => $monthly_url,
      '#yearly_title' => $this->t('Pro Yearly'),
      '#yearly_price' => $this->t('$179 / year'),
      '#yearly_save_text' => $this->t('Save about 25%'),
      '#yearly_cta_text' => $this->t('Choose yearly'),
      '#yearly_url' => $yearly_url,
      '#show_manage_billing' => $account instanceof UserInterface && _spotdeals_billing_should_show_manage_billing($plan_tier, $plan_status),
      '#manage_billing_text' => $this->t('Manage existing billing'),
      '#manage_billing_url' => $billing_url,
      '#attached' => [
        'library' => [
          'spotdeals_billing/spotdeals_billing',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
      ],
    ];
  }

  /**
   * Starts Stripe Checkout for a plan.
   */
  public function startCheckout($plan) {
    $account = $this->entityTypeManager()->getStorage('user')->load($this->currentUserProxy->id());

    if (!$account instanceof UserInterface) {
      $this->messenger()->addError($this->t('Unable to load your account.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Already Pro: no need to checkout again.
    if (spotdeals_billing_user_has_pro_access($account)) {
      $this->messenger()->addStatus($this->t('Your Pro access is already active.'));
      return new RedirectResponse(Url::fromRoute('spotdeals_billing.portal')->toString());
    }

    try {
      $checkout_url = $this->stripeBilling->createCheckoutUrl($account, $plan);
      return new TrustedRedirectResponse($checkout_url);
    }
    catch (\Throwable $e) {
      $this->getLogger('spotdeals_billing')->error('Stripe checkout error: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Unable to start checkout right now. Please try again.'));
      return new RedirectResponse(Url::fromRoute('spotdeals_billing.upgrade')->toString());
    }
  }

  /**
   * Opens the Stripe customer portal.
   */
  public function billingPortal() {
    $account = $this->entityTypeManager()->getStorage('user')->load($this->currentUserProxy->id());

    if (!$account instanceof UserInterface) {
      $this->messenger()->addError($this->t('Unable to load your account.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Hard gate: Free users blocked.
    if (!spotdeals_billing_user_has_pro_access($account)) {
      $this->messenger()->addError($this->t('You need a Pro plan to manage billing.'));
      return new RedirectResponse(Url::fromRoute('spotdeals_billing.upgrade')->toString());
    }

    try {
      $portal_url = $this->stripeBilling->createBillingPortalUrl($account);
      return new TrustedRedirectResponse($portal_url);
    }
    catch (\Throwable $e) {
      $this->getLogger('spotdeals_billing')->error('Stripe portal error: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Billing portal is not available right now.'));
      return new RedirectResponse(Url::fromRoute('spotdeals_billing.upgrade')->toString());
    }
  }

  /**
   * Success page.
   */
  public function successPage() {
    return [
      '#theme' => 'spotdeals_billing_success_page',
      '#message' => $this->t('Your payment was received. We are confirming your subscription now. If your plan does not update within a minute, refresh the page.'),
      '#attached' => [
        'library' => [
          'spotdeals_billing/spotdeals_billing',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
      ],
    ];
  }

  /**
   * Cancel page.
   */
  public function cancelPage() {
    return [
      '#theme' => 'spotdeals_billing_cancel_page',
      '#message' => $this->t('Your checkout was canceled. You can return to the upgrade page whenever you are ready.'),
      '#upgrade_url' => Url::fromRoute('spotdeals_billing.upgrade')->toString(),
      '#upgrade_link_text' => $this->t('Return to upgrade page'),
      '#attached' => [
        'library' => [
          'spotdeals_billing/spotdeals_billing',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
      ],
    ];
  }

}
