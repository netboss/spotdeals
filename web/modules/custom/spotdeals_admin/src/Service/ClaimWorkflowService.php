<?php

namespace Drupal\spotdeals_admin\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Handles claim approval/rejection workflow.
 */
class ClaimWorkflowService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs the service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->logger = $loggerFactory->get('spotdeals_admin');
  }

  /**
   * Approves a claim and assigns the venue to the claimant user.
   *
   * Returns an array:
   * - success: bool
   * - message: string
   * - claim: \Drupal\node\NodeInterface
   * - redirect_claim: bool
   */
  public function approveClaim(NodeInterface $claim): array {
    if ($claim->bundle() !== 'claim') {
      return [
        'success' => FALSE,
        'message' => 'Claim not found.',
        'claim' => $claim,
        'redirect_claim' => FALSE,
      ];
    }

    $venue = $this->getClaimVenue($claim);
    if (!$venue instanceof NodeInterface) {
      return [
        'success' => FALSE,
        'message' => 'This claim is not linked to a valid venue.',
        'claim' => $claim,
        'redirect_claim' => TRUE,
      ];
    }

    $claimantUser = $this->getClaimantUser($claim);
    if (!$claimantUser instanceof UserInterface) {
      return [
        'success' => FALSE,
        'message' => 'This claim does not have a valid claimant user to assign.',
        'claim' => $claim,
        'redirect_claim' => TRUE,
      ];
    }

    // Preserve existing plan-gating behavior from the current controller/module.
    if (!spotdeals_admin_user_can_claim_more_venues($claimantUser)) {
      $alreadyOwner = FALSE;

      if ($venue->hasField('field_primary_owner_user') && !$venue->get('field_primary_owner_user')->isEmpty()) {
        $alreadyOwner = ((int) $venue->get('field_primary_owner_user')->target_id === (int) $claimantUser->id());
      }

      if (!$alreadyOwner) {
        return [
          'success' => FALSE,
          'message' => 'This user is on the Free plan, which includes 1 business listing. Upgrade them to Pro before approving another venue claim.',
          'claim' => $claim,
          'redirect_claim' => TRUE,
        ];
      }
    }

    if (!$venue->hasField('field_primary_owner_user')) {
      return [
        'success' => FALSE,
        'message' => 'The linked venue does not have the field_primary_owner_user field.',
        'claim' => $claim,
        'redirect_claim' => TRUE,
      ];
    }

    // Preserve existing status helper behavior.
    if (!spotdeals_admin_claim_set_status($claim, 'approved')) {
      return [
        'success' => FALSE,
        'message' => 'Could not set the claim status to Approved.',
        'claim' => $claim,
        'redirect_claim' => TRUE,
      ];
    }

    // Canonical venue owner.
    $venue->set('field_primary_owner_user', $claimantUser->id());

    // Apply venue-side claim metadata.
    $this->applyVenueClaimMetadata($venue, $claim, $claimantUser);

    // Preserve current debug logging behavior.
    $this->logger->notice(
      'Before venue save for venue @vid: owner=@owner claimed_by=@claimed_by claimed_listing=@claimed_listing status=@status email=@email',
      [
        '@vid' => $venue->id(),
        '@owner' => $venue->hasField('field_primary_owner_user') && !$venue->get('field_primary_owner_user')->isEmpty()
          ? $venue->get('field_primary_owner_user')->target_id
          : '[empty]',
        '@claimed_by' => $venue->hasField('field_claimed_by') && !$venue->get('field_claimed_by')->isEmpty()
          ? $venue->get('field_claimed_by')->target_id
          : '[empty]',
        '@claimed_listing' => $venue->hasField('field_claimed_listing') && !$venue->get('field_claimed_listing')->isEmpty()
          ? $venue->get('field_claimed_listing')->value
          : '[empty]',
        '@status' => $venue->hasField('field_claim_status') && !$venue->get('field_claim_status')->isEmpty()
          ? $venue->get('field_claim_status')->value
          : '[empty]',
        '@email' => $venue->hasField('field_claim_contact_email') && !$venue->get('field_claim_contact_email')->isEmpty()
          ? $venue->get('field_claim_contact_email')->value
          : '[empty]',
      ]
    );

    $venue->save();

    if (function_exists('spotdeals_revenue_notify_pending_suggestions_for_venue')) {
      $notifiedSuggestions = spotdeals_revenue_notify_pending_suggestions_for_venue($venue);
      if ($notifiedSuggestions > 0) {
        $this->logger->notice('Notified claimed venue owner about @count pending gated suggestion(s) for venue @vid after claim approval.', [
          '@count' => $notifiedSuggestions,
          '@vid' => $venue->id(),
        ]);
      }
    }

    $reloadedVenue = $this->entityTypeManager->getStorage('node')->load($venue->id());
    if ($reloadedVenue instanceof NodeInterface) {
      $this->logger->notice(
        'After venue save for venue @vid: owner=@owner claimed_by=@claimed_by claimed_listing=@claimed_listing status=@status email=@email',
        [
          '@vid' => $reloadedVenue->id(),
          '@owner' => $reloadedVenue->hasField('field_primary_owner_user') && !$reloadedVenue->get('field_primary_owner_user')->isEmpty()
            ? $reloadedVenue->get('field_primary_owner_user')->target_id
            : '[empty]',
          '@claimed_by' => $reloadedVenue->hasField('field_claimed_by') && !$reloadedVenue->get('field_claimed_by')->isEmpty()
            ? $reloadedVenue->get('field_claimed_by')->target_id
            : '[empty]',
          '@claimed_listing' => $reloadedVenue->hasField('field_claimed_listing') && !$reloadedVenue->get('field_claimed_listing')->isEmpty()
            ? $reloadedVenue->get('field_claimed_listing')->value
            : '[empty]',
          '@status' => $reloadedVenue->hasField('field_claim_status') && !$reloadedVenue->get('field_claim_status')->isEmpty()
            ? $reloadedVenue->get('field_claim_status')->value
            : '[empty]',
          '@email' => $reloadedVenue->hasField('field_claim_contact_email') && !$reloadedVenue->get('field_claim_contact_email')->isEmpty()
            ? $reloadedVenue->get('field_claim_contact_email')->value
            : '[empty]',
        ]
      );
    }

    $this->applyReviewMetadata($claim);
    $claim->save();

    return [
      'success' => TRUE,
      'message' => sprintf('Claim approved and venue assigned to %s.', $claimantUser->getDisplayName()),
      'claim' => $claim,
      'redirect_claim' => TRUE,
    ];
  }

  /**
   * Rejects a claim.
   *
   * Returns an array:
   * - success: bool
   * - message: string
   * - claim: \Drupal\node\NodeInterface
   * - redirect_claim: bool
   */
  public function rejectClaim(NodeInterface $claim): array {
    if ($claim->bundle() !== 'claim') {
      return [
        'success' => FALSE,
        'message' => 'Claim not found.',
        'claim' => $claim,
        'redirect_claim' => FALSE,
      ];
    }

    if (!spotdeals_admin_claim_set_status($claim, 'rejected')) {
      return [
        'success' => FALSE,
        'message' => 'Could not set the claim status to Rejected.',
        'claim' => $claim,
        'redirect_claim' => TRUE,
      ];
    }

    $this->applyReviewMetadata($claim);
    $claim->save();

    return [
      'success' => TRUE,
      'message' => 'Claim rejected.',
      'claim' => $claim,
      'redirect_claim' => TRUE,
    ];
  }

  /**
   * Applies venue ownership/claim metadata on approval.
   */
  protected function applyVenueClaimMetadata(NodeInterface $venue, NodeInterface $claim, UserInterface $claimantUser): void {
    if ($venue->hasField('field_claimed_by')) {
      $venue->set('field_claimed_by', $claimantUser->id());
    }

    if ($venue->hasField('field_claim_status')) {
      $venue->set('field_claim_status', 'claimed');
    }

    if ($venue->hasField('field_claimed_listing')) {
      $venue->set('field_claimed_listing', TRUE);
    }

    if (
      $venue->hasField('field_claim_contact_email') &&
      $claim->hasField('field_contact_email') &&
      !$claim->get('field_contact_email')->isEmpty()
    ) {
      $venue->set('field_claim_contact_email', $claim->get('field_contact_email')->value);
    }
  }

  /**
   * Gets the venue linked from the claim.
   */
  protected function getClaimVenue(NodeInterface $claim): ?NodeInterface {
    if ($claim->hasField('field_venue') && !$claim->get('field_venue')->isEmpty()) {
      $venue = $claim->get('field_venue')->entity;

      if ($venue instanceof NodeInterface && $venue->bundle() === 'venue') {
        return $venue;
      }
    }

    return $this->recoverClaimVenueByTitle($claim);
  }

  /**
   * Attempts to recover older/orphaned claims by matching the claim title.
   *
   * Some early claim links created claims without a reliable field_venue value.
   * After venue rollback/import cycles, those claims can no longer approve even
   * when the matching venue still exists under a new node ID. If exactly one
   * venue has the same title as the claim, attach it back to the claim and
   * continue the approval workflow. If there are zero or multiple matches, do
   * not guess.
   */
  protected function recoverClaimVenueByTitle(NodeInterface $claim): ?NodeInterface {
    $title = trim((string) $claim->label());

    if ($title === '') {
      return NULL;
    }

    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->condition('title', $title)
      ->range(0, 2)
      ->execute();

    if (!is_array($ids) || count($ids) !== 1) {
      return NULL;
    }

    $venue = $this->entityTypeManager->getStorage('node')->load(reset($ids));

    if (!$venue instanceof NodeInterface || $venue->bundle() !== 'venue') {
      return NULL;
    }

    if ($claim->hasField('field_venue')) {
      $claim->set('field_venue', ['target_id' => $venue->id()]);
    }

    $this->logger->notice(
      'Recovered missing venue reference for claim @claim_id by exact title match to venue @venue_id.',
      [
        '@claim_id' => $claim->id(),
        '@venue_id' => $venue->id(),
      ]
    );

    return $venue;
  }

  /**
   * Gets the claimant user from the claim.
   *
   * Priority:
   * - field_claimant_user
   * - claim author (legacy fallback for older claims)
   */
  protected function getClaimantUser(NodeInterface $claim): ?UserInterface {
    if ($claim->hasField('field_claimant_user') && !$claim->get('field_claimant_user')->isEmpty()) {
      $user = $claim->get('field_claimant_user')->entity;
      if ($user instanceof UserInterface) {
        return $user;
      }
    }

    $owner = $claim->getOwner();
    if ($owner instanceof UserInterface) {
      return $owner;
    }

    return NULL;
  }

  /**
   * Applies reviewer metadata to the claim.
   */
  protected function applyReviewMetadata(NodeInterface $claim): void {
    $now = new DrupalDateTime('now', 'UTC');
    $formatted = $now->format('Y-m-d\TH:i:s');

    if ($claim->hasField('field_reviewed_by')) {
      $claim->set('field_reviewed_by', ['target_id' => $this->currentUser->id()]);
    }

    if ($claim->hasField('field_reviewed_at')) {
      $claim->set('field_reviewed_at', $formatted);
    }
  }

}
