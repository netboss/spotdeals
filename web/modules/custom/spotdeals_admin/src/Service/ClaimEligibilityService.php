<?php

namespace Drupal\spotdeals_admin\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

class ClaimEligibilityService {

  protected $entityTypeManager;
  protected $currentUser;
  protected $planTier;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, AccountProxyInterface $currentUser, PlanTierService $planTier) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->planTier = $planTier;
  }

  public function userHasPendingClaimForVenue($uid, $venue_id) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'claim')
      ->condition('field_claimant', $uid)
      ->condition('field_venue', $venue_id)
      ->condition('field_status', 'pending')
      ->accessCheck(FALSE);

    return $query->count()->execute() > 0;
  }

  public function venueIsClaimed($venue) {
    return !$venue->get('field_owner')->isEmpty();
  }

}
