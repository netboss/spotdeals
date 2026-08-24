<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reviews one discovered deal candidate.
 */
final class DealDiscoveryReviewForm extends FormBase {

  private array $candidate = [];

  public function __construct(
    private readonly DealDiscoveryStorage $storage,
    private readonly AccountProxyInterface $account,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_data_ingestion.deal_discovery_storage'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'spotdeals_deal_discovery_review_form';
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?int $candidate_id = NULL,
  ): array {
    $this->candidate = $this->storage->load((int) $candidate_id) ?? [];
    if ($this->candidate === []) {
      throw new NotFoundHttpException();
    }

    $form['venue'] = [
      '#type' => 'details',
      '#title' => $this->t('Venue and discovery evidence'),
      '#open' => TRUE,
    ];

    foreach ([
      'venue_name' => 'Venue',
      'venue_address' => 'Address',
      'venue_website' => 'Venue website',
      'category' => 'Discovery category',
      'external_source' => 'External source',
      'external_id' => 'External ID',
      'score' => 'Discovery score',
      'confidence' => 'Confidence classification',
      'classification_reason' => 'Classification reason',
      'reason' => 'Why it qualified',
      'status' => 'Current status',
    ] as $key => $label) {
      $form['venue'][$key] = [
        '#type' => 'item',
        '#title' => $this->t($label),
        '#markup' => nl2br(htmlspecialchars((string) $this->candidate[$key])),
      ];
    }

    if (trim((string) $this->candidate['source_url']) !== '') {
      $form['venue']['source_link'] = [
        '#type' => 'link',
        '#title' => $this->t('Open source page in a new tab'),
        '#url' => Url::fromUri((string) $this->candidate['source_url']),
        '#attributes' => [
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
        ],
      ];
    }

    $form['candidate_id'] = [
      '#type' => 'hidden',
      '#value' => $this->candidate['id'],
    ];

    $form['offer_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Offer title'),
      '#default_value' => $this->candidate['offer_title'],
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['offer_value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Offer value'),
      '#default_value' => $this->candidate['offer_value'],
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['schedule'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Schedule / validity'),
      '#default_value' => $this->candidate['schedule'],
      '#rows' => 4,
    ];

    $form['source_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Source URL'),
      '#default_value' => $this->candidate['source_url'],
      '#required' => TRUE,
    ];

    $form['publishing_overrides'] = [
      '#type' => 'details',
      '#title' => $this->t('Publishing overrides'),
      '#description' => $this->t('Use these only when automatic field derivation cannot represent the source correctly. Overrides are stored on this candidate and are used by publishing preview and audit.'),
      '#open' => FALSE,
    ];

    $form['publishing_overrides']['override_day_of_week_tid'] = [
      '#type' => 'select',
      '#title' => $this->t('Day of week override'),
      '#options' => $this->taxonomyOptions('day_of_week'),
      '#empty_option' => $this->t('- Use automatic derivation -'),
      '#default_value' => (int) ($this->candidate['override_day_of_week_tid'] ?? 0) ?: '',
      '#description' => $this->t('Select an existing Day of Week taxonomy term when the automatic schedule mapping is blocked or incorrect.'),
    ];

    $form['publishing_overrides']['override_deal_category_tid'] = [
      '#type' => 'select',
      '#title' => $this->t('Deal category override'),
      '#options' => $this->taxonomyOptions('deal_category'),
      '#empty_option' => $this->t('- Use automatic derivation -'),
      '#default_value' => (int) ($this->candidate['override_deal_category_tid'] ?? 0) ?: '',
      '#description' => $this->t('Select an existing Deal Category taxonomy term when automatic category mapping is blocked or incorrect.'),
    ];

    $form['decision'] = [
      '#type' => 'radios',
      '#title' => $this->t('Administrative decision'),
      '#required' => TRUE,
      '#default_value' => in_array($this->candidate['status'], ['approved', 'rejected'], TRUE)
        ? $this->candidate['status']
        : 'approved',
      '#options' => [
        'approved' => $this->t('Approve candidate for the future publishing step'),
        'rejected' => $this->t('Reject candidate'),
        'pending' => $this->t('Keep pending'),
      ],
    ];

    $form['admin_notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Administrative notes'),
      '#default_value' => $this->candidate['admin_notes'],
      '#maxlength' => 4000,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save review'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $candidate = $this->storage->load((int) $form_state->getValue('candidate_id'));
    if ($candidate === NULL) {
      throw new NotFoundHttpException();
    }

    $status = (string) $form_state->getValue('decision');
    if (!in_array($status, ['pending', 'approved', 'rejected'], TRUE)) {
      $status = 'pending';
    }

    $this->storage->review(
      (int) $candidate['id'],
      $status,
      trim((string) $form_state->getValue('admin_notes')),
      (int) $this->account->id(),
      [
        'offer_title' => trim((string) $form_state->getValue('offer_title')),
        'offer_value' => trim((string) $form_state->getValue('offer_value')),
        'schedule' => trim((string) $form_state->getValue('schedule')),
        'source_url' => trim((string) $form_state->getValue('source_url')),
        'override_day_of_week_tid' => (int) $form_state->getValue('override_day_of_week_tid'),
        'override_deal_category_tid' => (int) $form_state->getValue('override_deal_category_tid'),
      ],
    );

    $this->messenger()->addStatus($this->t('The deal-discovery review was saved.'));
    $form_state->setRedirect('spotdeals_data_ingestion.deal_discovery_candidates');
  }


  /**
   * Returns taxonomy options without hardcoded term IDs.
   *
   * @return array<int, string>
   */
  private function taxonomyOptions(string $vocabulary): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabulary)
      ->sort('weight', 'ASC')
      ->sort('name', 'ASC')
      ->execute();

    if ($ids === []) {
      return [];
    }

    $options = [];
    foreach ($storage->loadMultiple($ids) as $term) {
      $options[(int) $term->id()] = (string) $term->label();
    }

    return $options;
  }


}
