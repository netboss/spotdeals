<?php

namespace Drupal\spotdeals_billing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\spotdeals_billing\Service\StripeBillingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles Stripe webhooks.
 */
class StripeWebhookController extends ControllerBase {

  /**
   * The Stripe billing service.
   *
   * @var \Drupal\spotdeals_billing\Service\StripeBillingService
   */
  protected $stripeBilling;

  /**
   * Constructs the controller.
   */
  public function __construct(StripeBillingService $stripe_billing) {
    $this->stripeBilling = $stripe_billing;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('spotdeals_billing.stripe_billing')
    );
  }

  /**
   * Webhook endpoint.
   */
  public function handle(Request $request) {
    $payload = $request->getContent();
    $signature = (string) $request->headers->get('Stripe-Signature');

    try {
      $event = $this->stripeBilling->constructWebhookEvent($payload, $signature);

      $event_id = (string) ($event->id ?? '');
      $event_type = (string) ($event->type ?? '');

      $this->getLogger('spotdeals_billing')->notice(
        'Stripe webhook received: @type (@id)',
        [
          '@type' => $event_type ?: 'unknown',
          '@id' => $event_id ?: 'no-id',
        ]
      );

      if ($event_id !== '' && $this->stripeBilling->isEventProcessed($event_id)) {
        $this->getLogger('spotdeals_billing')->notice(
          'Stripe webhook ignored as duplicate: @type (@id)',
          [
            '@type' => $event_type ?: 'unknown',
            '@id' => $event_id,
          ]
        );

        return new JsonResponse(['received' => TRUE, 'duplicate' => TRUE], 200);
      }

      $this->stripeBilling->handleWebhookEvent($event);

      if ($event_id !== '') {
        $this->stripeBilling->markEventProcessed($event_id);
      }

      $this->getLogger('spotdeals_billing')->notice(
        'Stripe webhook processed successfully: @type (@id)',
        [
          '@type' => $event_type ?: 'unknown',
          '@id' => $event_id ?: 'no-id',
        ]
      );

      return new JsonResponse(['received' => TRUE], 200);
    }
    catch (\UnexpectedValueException $e) {
      $this->getLogger('spotdeals_billing')->warning('Invalid Stripe webhook payload.');
      return new JsonResponse(['error' => 'Invalid payload'], 400);
    }
    catch (\Stripe\Exception\SignatureVerificationException $e) {
      $this->getLogger('spotdeals_billing')->warning('Invalid Stripe webhook signature.');
      return new JsonResponse(['error' => 'Invalid signature'], 400);
    }
    catch (\Throwable $e) {
      $this->getLogger('spotdeals_billing')->error('Stripe webhook error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'Webhook processing failed'], 500);
    }
  }

}
