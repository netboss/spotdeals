<?php

namespace Drupal\spotdeals_admin\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

class PlanTierService {

  protected $entityTypeManager;
  protected $currentUser;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, AccountProxyInterface $currentUser) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  public function getOwnedVenueCount($uid) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'venue')
      ->condition('field_owner', $uid)
      ->accessCheck(FALSE);

    return $query->count()->execute();
  }

  public function getPendingClaimCount($uid) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'claim')
      ->condition('field_claimant', $uid)
      ->condition('field_status', 'pending')
      ->accessCheck(FALSE);

    return $query->count()->execute();
  }

  public function getPendingClaimLimit() {
    return 3;
  }

  public function canSubmitMorePendingClaims($uid) {
    return $this->getPendingClaimCount($uid) < $this->getPendingClaimLimit();
  }

  public function canClaimMoreVenues($uid) {
    return $this->getOwnedVenueCount($uid) < 1;
  }

}
