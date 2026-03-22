<?php

namespace Drupal\spotdeals_admin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Claim review actions.
 */
class SpotdealsAdminClaimController extends ControllerBase {

  /**
   * Approves a claim and assigns the venue to the claimant user.
   */
  public function approve($node) {
    $claim = Node::load($node);

    if (!$claim instanceof NodeInterface || $claim->bundle() !== 'claim') {
      $this->messenger()->addError($this->t('Claim not found.'));
      return $this->redirectToFront();
    }

    $venue = $this->getClaimVenue($claim);
    if (!$venue instanceof NodeInterface) {
      $this->messenger()->addError($this->t('This claim is not linked to a valid venue.'));
      return $this->redirectToClaim($claim);
    }

    $claimant_user = $this->getClaimantUser($claim);
    if (!$claimant_user instanceof UserInterface) {
      $this->messenger()->addError($this->t('This claim does not have a valid claimant user to assign.'));
      return $this->redirectToClaim($claim);
    }

    // Plan gating safeguard on approval.
    if (!spotdeals_admin_user_can_claim_more_venues($claimant_user)) {
      // Allow approval if this user already owns this same venue.
      $already_owner = FALSE;
      if ($venue->hasField('field_primary_owner_user') && !$venue->get('field_primary_owner_user')->isEmpty()) {
        $already_owner = ((int) $venue->get('field_primary_owner_user')->target_id === (int) $claimant_user->id());
      }

      if (!$already_owner) {
        $this->messenger()->addError($this->t('This user is on the Free plan, which includes 1 business listing. Upgrade them to Pro before approving another venue claim.'));
        return $this->redirectToClaim($claim);
      }
    }

    if (!$venue->hasField('field_primary_owner_user')) {
      $this->messenger()->addError($this->t('The linked venue does not have the field_primary_owner_user field.'));
      return $this->redirectToClaim($claim);
    }

    // First, approve the claim itself.
    if (!spotdeals_admin_claim_set_status($claim, 'approved')) {
      $this->messenger()->addError($this->t('Could not set the claim status to Approved.'));
      return $this->redirectToClaim($claim);
    }

    // Canonical venue owner.
    $venue->set('field_primary_owner_user', $claimant_user->id());

    // Apply all venue-side claim metadata.
    $this->applyVenueClaimMetadata($venue, $claim, $claimant_user);

    // Debug logging before save.
    \Drupal::logger('spotdeals_admin')->notice(
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

    // Reload venue from storage and log persisted values.
    $reloaded_venue = Node::load($venue->id());
    if ($reloaded_venue instanceof NodeInterface) {
      \Drupal::logger('spotdeals_admin')->notice(
        'After venue save for venue @vid: owner=@owner claimed_by=@claimed_by claimed_listing=@claimed_listing status=@status email=@email',
        [
          '@vid' => $reloaded_venue->id(),
          '@owner' => $reloaded_venue->hasField('field_primary_owner_user') && !$reloaded_venue->get('field_primary_owner_user')->isEmpty()
            ? $reloaded_venue->get('field_primary_owner_user')->target_id
            : '[empty]',
          '@claimed_by' => $reloaded_venue->hasField('field_claimed_by') && !$reloaded_venue->get('field_claimed_by')->isEmpty()
            ? $reloaded_venue->get('field_claimed_by')->target_id
            : '[empty]',
          '@claimed_listing' => $reloaded_venue->hasField('field_claimed_listing') && !$reloaded_venue->get('field_claimed_listing')->isEmpty()
            ? $reloaded_venue->get('field_claimed_listing')->value
            : '[empty]',
          '@status' => $reloaded_venue->hasField('field_claim_status') && !$reloaded_venue->get('field_claim_status')->isEmpty()
            ? $reloaded_venue->get('field_claim_status')->value
            : '[empty]',
          '@email' => $reloaded_venue->hasField('field_claim_contact_email') && !$reloaded_venue->get('field_claim_contact_email')->isEmpty()
            ? $reloaded_venue->get('field_claim_contact_email')->value
            : '[empty]',
        ]
      );
    }

    $this->applyReviewMetadata($claim);
    $claim->save();

    $this->messenger()->addStatus($this->t('Claim approved and venue assigned to %user.', [
      '%user' => $claimant_user->getDisplayName(),
    ]));

    return $this->redirectToClaim($claim);
  }

  /**
   * Rejects a claim.
   */
  public function reject($node) {
    $claim = Node::load($node);

    if (!$claim instanceof NodeInterface || $claim->bundle() !== 'claim') {
      $this->messenger()->addError($this->t('Claim not found.'));
      return $this->redirectToFront();
    }

    if (!spotdeals_admin_claim_set_status($claim, 'rejected')) {
      $this->messenger()->addError($this->t('Could not set the claim status to Rejected.'));
      return $this->redirectToClaim($claim);
    }

    $this->applyReviewMetadata($claim);
    $claim->save();

    $this->messenger()->addStatus($this->t('Claim rejected.'));
    return $this->redirectToClaim($claim);
  }

  /**
   * Applies venue ownership/claim metadata on approval.
   */
  protected function applyVenueClaimMetadata(NodeInterface $venue, NodeInterface $claim, UserInterface $claimant_user) {
    if ($venue->hasField('field_claimed_by')) {
      $venue->set('field_claimed_by', $claimant_user->id());
    }

    if ($venue->hasField('field_claim_status')) {
      $venue->set('field_claim_status', 'approved');
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
  protected function getClaimVenue(NodeInterface $claim) {
    if (!$claim->hasField('field_venue') || $claim->get('field_venue')->isEmpty()) {
      return NULL;
    }

    $venue = $claim->get('field_venue')->entity;
    if (!$venue instanceof NodeInterface || $venue->bundle() !== 'venue') {
      return NULL;
    }

    return $venue;
  }

  /**
   * Gets the claimant user from the claim.
   *
   * Priority:
   * - field_claimant_user
   * - claim author (legacy fallback for older claims)
   */
  protected function getClaimantUser(NodeInterface $claim) {
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
  protected function applyReviewMetadata(NodeInterface $claim) {
    $current_user = $this->currentUser();
    $now = new DrupalDateTime('now', 'UTC');
    $formatted = $now->format('Y-m-d\TH:i:s');

    if ($claim->hasField('field_reviewed_by')) {
      $claim->set('field_reviewed_by', ['target_id' => $current_user->id()]);
    }

    if ($claim->hasField('field_reviewed_at')) {
      $claim->set('field_reviewed_at', $formatted);
    }
  }

  /**
   * Redirects to the claim node page.
   */
  protected function redirectToClaim(NodeInterface $claim) {
    return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => $claim->id()])->toString());
  }

  /**
   * Redirects to front page.
   */
  protected function redirectToFront() {
    return new RedirectResponse(Url::fromRoute('<front>')->toString());
  }

}
