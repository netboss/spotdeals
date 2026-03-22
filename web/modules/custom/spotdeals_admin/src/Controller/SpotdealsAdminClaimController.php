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

    if (!$venue->hasField('field_primary_owner_user')) {
      $this->messenger()->addError($this->t('The linked venue does not have the field_primary_owner_user field.'));
      return $this->redirectToClaim($claim);
    }

    if (!spotdeals_admin_claim_set_status($claim, 'approved')) {
      $this->messenger()->addError($this->t('Could not set the claim status to Approved. Check allowed values for field_claim_status.'));
      return $this->redirectToClaim($claim);
    }

    $venue->set('field_primary_owner_user', ['target_id' => $claimant_user->id()]);
    $venue->save();

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
      $this->messenger()->addError($this->t('Could not set the claim status to Rejected. Check allowed values for field_claim_status.'));
      return $this->redirectToClaim($claim);
    }

    $this->applyReviewMetadata($claim);
    $claim->save();

    $this->messenger()->addStatus($this->t('Claim rejected.'));
    return $this->redirectToClaim($claim);
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
   * - claim author
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
