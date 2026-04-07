<?php

namespace Drupal\spotdeals_admin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\spotdeals_admin\Service\ClaimWorkflowService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Claim review actions.
 */
class SpotdealsAdminClaimController extends ControllerBase {

  /**
   * The claim workflow service.
   *
   * @var \Drupal\spotdeals_admin\Service\ClaimWorkflowService
   */
  protected $claimWorkflowService;

  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeStorage;

  /**
   * Constructs the controller.
   */
  public function __construct(ClaimWorkflowService $claimWorkflowService, EntityTypeManagerInterface $entityTypeManager) {
    $this->claimWorkflowService = $claimWorkflowService;
    $this->nodeStorage = $entityTypeManager->getStorage('node');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('spotdeals_admin.claim_workflow'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Approves a claim and assigns the venue to the claimant user.
   */
  public function approve($node) {
    $claim = $this->nodeStorage->load($node);

    if (!$claim instanceof NodeInterface) {
      $this->messenger()->addError($this->t('Claim not found.'));
      return $this->redirectToFront();
    }

    $result = $this->claimWorkflowService->approveClaim($claim);

    if (!$result['success']) {
      $this->messenger()->addError($this->t($result['message']));
    }
    else {
      $this->messenger()->addStatus($this->t($result['message']));
    }

    if (!empty($result['redirect_claim'])) {
      return $this->redirectToClaim($claim);
    }

    return $this->redirectToFront();
  }

  /**
   * Rejects a claim.
   */
  public function reject($node) {
    $claim = $this->nodeStorage->load($node);

    if (!$claim instanceof NodeInterface) {
      $this->messenger()->addError($this->t('Claim not found.'));
      return $this->redirectToFront();
    }

    $result = $this->claimWorkflowService->rejectClaim($claim);

    if (!$result['success']) {
      $this->messenger()->addError($this->t($result['message']));
    }
    else {
      $this->messenger()->addStatus($this->t($result['message']));
    }

    if (!empty($result['redirect_claim'])) {
      return $this->redirectToClaim($claim);
    }

    return $this->redirectToFront();
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
