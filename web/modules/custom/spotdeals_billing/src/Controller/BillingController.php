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
    $monthly_url = Url::fromRoute('spotdeals_billing.checkout', ['plan' => 'monthly']);
    $yearly_url = Url::fromRoute('spotdeals_billing.checkout', ['plan' => 'yearly']);
    $billing_url = Url::fromRoute('spotdeals_billing.portal');

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['spotdeals-billing-upgrade-page'],
      ],
      'intro' => [
        '#markup' => '<p>Upgrade to Pro to manage unlimited listings and multiple locations.</p>',
      ],
      'plans' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['spotdeals-billing-plans'],
        ],
        'monthly' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['spotdeals-billing-plan']],
          'title' => ['#markup' => '<h2>Pro Monthly</h2>'],
          'price' => ['#markup' => '<p>$19.99 / month</p>'],
          'cta' => [
            '#type' => 'link',
            '#title' => $this->t('Choose monthly'),
            '#url' => $monthly_url,
            '#attributes' => ['class' => ['button', 'button--primary']],
          ],
        ],
        'yearly' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['spotdeals-billing-plan']],
          'title' => ['#markup' => '<h2>Pro Yearly</h2>'],
          'price' => ['#markup' => '<p>$179 / year</p>'],
          'save' => ['#markup' => '<p><strong>Save about 25%</strong></p>'],
          'cta' => [
            '#type' => 'link',
            '#title' => $this->t('Choose yearly'),
            '#url' => $yearly_url,
            '#attributes' => ['class' => ['button', 'button--primary']],
          ],
        ],
      ],
      'manage' => [
        '#type' => 'container',
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Manage existing billing'),
          '#url' => $billing_url,
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

    try {
      $portal_url = $this->stripeBilling->createBillingPortalUrl($account);
      return new TrustedRedirectResponse($portal_url);
    }
    catch (\Throwable $e) {
      $this->getLogger('spotdeals_billing')->error('Stripe portal error: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Billing portal is not available yet for this account.'));
      return new RedirectResponse(Url::fromRoute('spotdeals_billing.upgrade')->toString());
    }
  }

  /**
   * Success page.
   */
  public function successPage() {
    return [
      '#markup' => '<p>Your payment was received. We are confirming your subscription now. If your plan does not update within a minute, refresh the page.</p>',
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
      '#markup' => '<p>Your checkout was canceled. You can return to the upgrade page whenever you are ready.</p>',
      '#cache' => [
        'contexts' => ['user'],
      ],
    ];
  }

}
