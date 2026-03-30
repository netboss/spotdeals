<?php

namespace Drupal\spotdeals_billing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe billing service.
 */
class StripeBillingService {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs the service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('spotdeals_billing');
  }

  /**
   * Returns a configured price ID for a plan.
   */
  public function getPriceId($plan) {
    switch ($plan) {
      case 'monthly':
        $price_id = Settings::get('spotdeals_billing.monthly_price_id');
        break;

      case 'yearly':
        $price_id = Settings::get('spotdeals_billing.yearly_price_id');
        break;

      default:
        throw new \InvalidArgumentException('Invalid billing plan.');
    }

    if (empty($price_id)) {
      throw new \RuntimeException(sprintf('Missing Stripe price ID for plan "%s".', $plan));
    }

    return $price_id;
  }

  /**
   * Creates a checkout URL for the given user and plan.
   */
  public function createCheckoutUrl(UserInterface $account, $plan) {
    $price_id = $this->getPriceId($plan);
    $customer_id = $this->getOrCreateCustomerId($account);

    $success_url = Url::fromRoute('spotdeals_billing.success', [], ['absolute' => TRUE])->toString();
    $cancel_url = Url::fromRoute('spotdeals_billing.cancel', [], ['absolute' => TRUE])->toString();

    $session = CheckoutSession::create([
      'mode' => 'subscription',
      'customer' => $customer_id,
      'line_items' => [
        [
          'price' => $price_id,
          'quantity' => 1,
        ],
      ],
      'success_url' => $success_url . '?session_id={CHECKOUT_SESSION_ID}',
      'cancel_url' => $cancel_url,
      'client_reference_id' => (string) $account->id(),
      'metadata' => [
        'drupal_uid' => (string) $account->id(),
        'selected_plan' => $plan,
      ],
      'subscription_data' => [
        'metadata' => [
          'drupal_uid' => (string) $account->id(),
          'selected_plan' => $plan,
        ],
      ],
    ], [
      'api_key' => Settings::get('spotdeals_billing.stripe_secret_key'),
    ]);

    if (empty($session->url)) {
      throw new \RuntimeException('Stripe Checkout URL was not returned.');
    }

    return $session->url;
  }

  /**
   * Creates a billing portal URL for the given user.
   */
  public function createBillingPortalUrl(UserInterface $account) {
    $customer_id = $this->getStoredCustomerId($account);

    if (empty($customer_id)) {
      throw new \RuntimeException('No Stripe customer exists for this user yet.');
    }

    $return_url = Url::fromRoute('spotdeals_billing.upgrade', [], ['absolute' => TRUE])->toString();

    $session = BillingPortalSession::create([
      'customer' => $customer_id,
      'return_url' => $return_url,
    ], [
      'api_key' => Settings::get('spotdeals_billing.stripe_secret_key'),
    ]);

    if (empty($session->url)) {
      throw new \RuntimeException('Stripe billing portal URL was not returned.');
    }

    return $session->url;
  }

  /**
   * Constructs and verifies a webhook event.
   */
  public function constructWebhookEvent($payload, $signature) {
    $webhook_secret = Settings::get('spotdeals_billing.webhook_secret');

    if (empty($webhook_secret)) {
      throw new \RuntimeException('Missing Stripe webhook secret in settings.php.');
    }

    return Webhook::constructEvent($payload, $signature, $webhook_secret);
  }

  /**
   * Handles a Stripe webhook event.
   */
  public function handleWebhookEvent(Event $event) {
    switch ($event->type) {
      case 'checkout.session.completed':
        $this->handleCheckoutSessionCompleted($event->data->object, (string) $event->id);
        break;

      case 'customer.subscription.created':
      case 'customer.subscription.updated':
      case 'customer.subscription.deleted':
        $this->syncUserFromSubscriptionObject($event->data->object, (string) $event->id, (string) $event->type);
        break;

      default:
        $this->logger->notice('Stripe webhook event ignored: @type (@id)', [
          '@type' => (string) $event->type,
          '@id' => (string) $event->id,
        ]);
        break;
    }
  }

  /**
   * Returns TRUE if the event has already been processed.
   */
  public function isEventProcessed($event_id) {
    if ($event_id === '') {
      return FALSE;
    }

    return (bool) \Drupal::service('keyvalue.expirable')
      ->get('spotdeals_billing.processed_events')
      ->get($event_id);
  }

  /**
   * Marks an event as processed for a limited time.
   */
  public function markEventProcessed($event_id) {
    if ($event_id === '') {
      return;
    }

    \Drupal::service('keyvalue.expirable')
      ->get('spotdeals_billing.processed_events')
      ->setWithExpire($event_id, time(), 60 * 60 * 24 * 14);
  }

  /**
   * Handles checkout.session.completed.
   */
  protected function handleCheckoutSessionCompleted($session, $event_id = '') {
    if (empty($session->client_reference_id)) {
      $this->logger->warning('Stripe checkout.session.completed had no client_reference_id. Event: @id', [
        '@id' => $event_id ?: 'unknown',
      ]);
      return;
    }

    $user = $this->loadUserById((int) $session->client_reference_id);
    if (!$user instanceof UserInterface) {
      $this->logger->warning('Stripe checkout.session.completed could not match Drupal user. Event: @id, UID: @uid', [
        '@id' => $event_id ?: 'unknown',
        '@uid' => (string) $session->client_reference_id,
      ]);
      return;
    }

    if (!empty($session->customer)) {
      $this->setUserFieldValue($user, 'field_stripe_customer_id', (string) $session->customer);
    }

    if (!empty($session->subscription)) {
      $this->setUserFieldValue($user, 'field_stripe_subscription_id', (string) $session->subscription);
    }

    $user->save();

    $this->logger->notice(
      'Stripe checkout.session.completed saved billing IDs for user @uid. Event: @id, Customer: @customer, Subscription: @subscription',
      [
        '@uid' => $user->id(),
        '@id' => $event_id ?: 'unknown',
        '@customer' => !empty($session->customer) ? (string) $session->customer : 'none',
        '@subscription' => !empty($session->subscription) ? (string) $session->subscription : 'none',
      ]
    );
  }

  /**
   * Synchronizes a Drupal user from a Stripe subscription object.
   */
  protected function syncUserFromSubscriptionObject($subscription, $event_id = '', $event_type = '') {
    $customer_id = !empty($subscription->customer) ? (string) $subscription->customer : '';
    $subscription_id = !empty($subscription->id) ? (string) $subscription->id : '';

    // Re-fetch full subscription from Stripe to avoid partial webhook payloads.
    if ($subscription_id !== '') {
      $client = new StripeClient(Settings::get('spotdeals_billing.stripe_secret_key'));
      $subscription = $client->subscriptions->retrieve($subscription_id, []);
    }

    $user = NULL;

    if (!empty($subscription->metadata->drupal_uid)) {
      $user = $this->loadUserById((int) $subscription->metadata->drupal_uid);
    }

    if (!$user instanceof UserInterface && $customer_id !== '') {
      $user = $this->loadUserByStripeCustomerId($customer_id);
    }

    if (!$user instanceof UserInterface && $subscription_id !== '') {
      $user = $this->loadUserByStripeSubscriptionId($subscription_id);
    }

    if (!$user instanceof UserInterface) {
      $this->logger->warning(
        'No Drupal user matched Stripe subscription. Event: @event_id, Type: @event_type, Subscription: @subscription_id, Customer: @customer_id',
        [
          '@event_id' => $event_id ?: 'unknown',
          '@event_type' => $event_type ?: 'unknown',
          '@subscription_id' => $subscription_id ?: 'none',
          '@customer_id' => $customer_id ?: 'none',
        ]
      );
      return;
    }

    $raw_status = (string) ($subscription->status ?? '');
    $resolved_status = $this->resolveDrupalPlanStatus($subscription, $raw_status);
    $is_pro = $this->subscriptionShouldHaveProAccess($subscription, $raw_status, $resolved_status);

    if ($customer_id !== '') {
      $this->setUserFieldValue($user, 'field_stripe_customer_id', $customer_id);
    }

    if ($subscription_id !== '') {
      $this->setUserFieldValue($user, 'field_stripe_subscription_id', $subscription_id);
    }

    $resolved_status_value = $this->resolveUserPlanStatusValue([
      $resolved_status,
      $raw_status,
      $resolved_status === 'canceling' ? 'active' : '',
    ]);
    if ($resolved_status_value !== NULL) {
      $this->setUserFieldValue($user, 'field_plan_status', $resolved_status_value);
    }

    $resolved_plan_value = $this->resolveUserPlanTierValue($is_pro ? ['pro'] : ['free']);
    if ($resolved_plan_value !== NULL) {
      $this->setUserFieldValue($user, 'field_plan_tier', $resolved_plan_value);
    }

    $current_period_end = NULL;

    if (!empty($subscription->items->data[0]->current_period_end)) {
      $current_period_end = (int) $subscription->items->data[0]->current_period_end;
    }
    elseif (!empty($subscription->current_period_end)) {
      $current_period_end = (int) $subscription->current_period_end;
    }

    if (!empty($current_period_end)) {
      $iso_date = gmdate('Y-m-d\TH:i:s', $current_period_end);
      $this->setUserFieldValue($user, 'field_plan_renew_at', $iso_date);
    }
    else {
      $this->clearUserFieldValue($user, 'field_plan_renew_at');
    }

    if (!$is_pro && in_array($resolved_status, ['inactive', 'canceled'], TRUE)) {
      $resolved_free_plan_value = $this->resolveUserPlanTierValue(['free']);
      if ($resolved_free_plan_value !== NULL) {
        $this->setUserFieldValue($user, 'field_plan_tier', $resolved_free_plan_value);
      }
    }

    $this->logger->notice(
      'Stripe renewal timestamp resolved. Event: @event_id, Subscription: @subscription_id, current_period_end: @current_period_end',
      [
        '@event_id' => $event_id ?: 'unknown',
        '@subscription_id' => $subscription_id ?: 'none',
        '@current_period_end' => $current_period_end ?: 'empty',
      ]
    );

    $user->save();

    $this->logger->notice(
      'Stripe subscription sync complete. Event: @event_id, Type: @event_type, UID: @uid, Raw status: @raw_status, Resolved status: @resolved_status, Resolved plan tier: @plan, cancel_at_period_end: @cancel_at_period_end',
      [
        '@event_id' => $event_id ?: 'unknown',
        '@event_type' => $event_type ?: 'unknown',
        '@uid' => $user->id(),
        '@raw_status' => $raw_status ?: 'unknown',
        '@resolved_status' => $resolved_status ?: 'unknown',
        '@plan' => $resolved_plan_value ?: 'unresolved',
        '@cancel_at_period_end' => !empty($subscription->cancel_at_period_end) ? 'true' : 'false',
      ]
    );
  }

  /**
   * Gets or creates a Stripe customer ID for a user.
   */
  protected function getOrCreateCustomerId(UserInterface $account) {
    $stored_customer_id = $this->getStoredCustomerId($account);

    if (!empty($stored_customer_id)) {
      return $stored_customer_id;
    }

    $email = $account->getEmail();
    if (empty($email)) {
      throw new \RuntimeException('User account is missing an email address.');
    }

    $client = new StripeClient(Settings::get('spotdeals_billing.stripe_secret_key'));

    $customer = $client->customers->create([
      'email' => $email,
      'name' => $account->getDisplayName(),
      'metadata' => [
        'drupal_uid' => (string) $account->id(),
      ],
    ]);

    $customer_id = (string) $customer->id;
    $this->setUserFieldValue($account, 'field_stripe_customer_id', $customer_id);
    $account->save();

    return $customer_id;
  }

  /**
   * Resolves the Drupal-visible plan status from Stripe subscription data.
   */
  protected function resolveDrupalPlanStatus($subscription, string $raw_status): string {
    $normalized_status = strtolower(trim($raw_status));
    $cancel_at_period_end = !empty($subscription->cancel_at_period_end);

    if (in_array($normalized_status, ['active', 'trialing'], TRUE) && $cancel_at_period_end) {
      return 'canceling';
    }

    switch ($normalized_status) {
      case 'active':
      case 'trialing':
      case 'past_due':
      case 'unpaid':
      case 'canceled':
      case 'incomplete':
      case 'incomplete_expired':
        return $normalized_status;

      case '':
        return 'inactive';

      default:
        return $normalized_status;
    }
  }

  /**
   * Determines whether the subscription should keep Pro access.
   */
  protected function subscriptionShouldHaveProAccess($subscription, string $raw_status, string $resolved_status): bool {
    $normalized_raw_status = strtolower(trim($raw_status));
    $normalized_resolved_status = strtolower(trim($resolved_status));

    if (in_array($normalized_resolved_status, ['canceling', 'active', 'trialing', 'past_due', 'unpaid'], TRUE)) {
      return TRUE;
    }

    return in_array($normalized_raw_status, ['active', 'trialing'], TRUE);
  }

  /**
   * Resolves the actual stored key for field_plan_tier.
   */
  protected function resolveUserPlanTierValue(array $candidates) {
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');

    if (empty($field_definitions['field_plan_tier'])) {
      return NULL;
    }

    $settings = $field_definitions['field_plan_tier']->getSettings();
    $allowed_values = $settings['allowed_values'] ?? [];

    if (!is_array($allowed_values) || !$allowed_values) {
      return NULL;
    }

    $normalized_candidates = [];
    foreach ($candidates as $candidate) {
      $candidate = trim((string) $candidate);
      if ($candidate !== '') {
        $normalized_candidates[] = mb_strtolower($candidate);
      }
    }

    foreach ($allowed_values as $key => $label) {
      $normalized_key = mb_strtolower(trim((string) $key));
      $normalized_label = mb_strtolower(trim((string) $label));

      foreach ($normalized_candidates as $candidate) {
        if ($candidate === $normalized_key || $candidate === $normalized_label) {
          return (string) $key;
        }
      }
    }

    return NULL;
  }

  /**
   * Resolves the actual stored key for field_plan_status.
   */
  protected function resolveUserPlanStatusValue(array $candidates) {
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');

    if (empty($field_definitions['field_plan_status'])) {
      return NULL;
    }

    $settings = $field_definitions['field_plan_status']->getSettings();
    $allowed_values = $settings['allowed_values'] ?? [];

    if (!is_array($allowed_values) || !$allowed_values) {
      return NULL;
    }

    $normalized_candidates = [];
    foreach ($candidates as $candidate) {
      $candidate = trim((string) $candidate);
      if ($candidate !== '') {
        $normalized_candidates[] = mb_strtolower($candidate);
      }
    }

    foreach ($allowed_values as $key => $label) {
      $normalized_key = mb_strtolower(trim((string) $key));
      $normalized_label = mb_strtolower(trim((string) $label));

      foreach ($normalized_candidates as $candidate) {
        if ($candidate === $normalized_key || $candidate === $normalized_label) {
          return (string) $key;
        }
      }
    }

    return NULL;
  }

  /**
   * Returns the stored Stripe customer ID, if any.
   */
  protected function getStoredCustomerId(UserInterface $account) {
    if ($account->hasField('field_stripe_customer_id') && !$account->get('field_stripe_customer_id')->isEmpty()) {
      return (string) $account->get('field_stripe_customer_id')->value;
    }

    return '';
  }

  /**
   * Loads a Drupal user by ID.
   */
  protected function loadUserById($uid) {
    if ($uid <= 0) {
      return NULL;
    }

    return $this->entityTypeManager->getStorage('user')->load($uid);
  }

  /**
   * Loads a Drupal user by Stripe customer ID.
   */
  protected function loadUserByStripeCustomerId($customer_id) {
    return $this->loadSingleUserByField('field_stripe_customer_id', $customer_id);
  }

  /**
   * Loads a Drupal user by Stripe subscription ID.
   */
  protected function loadUserByStripeSubscriptionId($subscription_id) {
    return $this->loadSingleUserByField('field_stripe_subscription_id', $subscription_id);
  }

  /**
   * Loads a single Drupal user by a field value.
   */
  protected function loadSingleUserByField($field_name, $value) {
    $storage = $this->entityTypeManager->getStorage('user');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($field_name, $value)
      ->range(0, 1);

    $ids = $query->execute();
    if (empty($ids)) {
      return NULL;
    }

    $id = reset($ids);
    return $storage->load($id);
  }

  /**
   * Sets a field only if it exists on the user.
   */
  protected function setUserFieldValue(UserInterface $user, $field_name, $value) {
    if ($user->hasField($field_name)) {
      $user->set($field_name, $value);
    }
  }

  /**
   * Clears a field only if it exists on the user.
   */
  protected function clearUserFieldValue(UserInterface $user, $field_name) {
    if ($user->hasField($field_name)) {
      $user->set($field_name, NULL);
    }
  }

}
