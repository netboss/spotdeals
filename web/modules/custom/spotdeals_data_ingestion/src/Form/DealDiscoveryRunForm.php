<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryLocationResolver;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryRunner;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage;
use Drupal\spotdeals_data_ingestion\Service\VenueTypeResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs review-only deal discovery from Drupal administration.
 */
final class DealDiscoveryRunForm extends FormBase {

  public function __construct(
    private readonly DealDiscoveryStorage $storage,
    private readonly StateInterface $state,
    private readonly VenueTypeResolver $venueTypeResolver,
    private readonly DealDiscoveryLocationResolver $locationResolver,
    private readonly DealDiscoveryRunner $runner,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_data_ingestion.deal_discovery_storage'),
      $container->get('state'),
      $container->get('Drupal\spotdeals_data_ingestion\Service\VenueTypeResolver'),
      $container->get('spotdeals_data_ingestion.deal_discovery_location_resolver'),
      $container->get('spotdeals_data_ingestion.deal_discovery_runner'),
    );
  }

  public function getFormId(): string {
    return 'spotdeals_deal_discovery_run_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $pendingCount = $this->storage->count('pending');
    $backlogAlert = $this->state->get(
      'spotdeals_data_ingestion.deal_discovery_backlog_alert',
      NULL,
    );

    $form['notice'] = [
      '#type' => 'container',
      'text' => [
        '#markup' => '<p>' . $this->t('This runs deal discovery and classifies candidates by confidence. High-confidence candidates are automatically approved only when the exact no-write publishing preview is fully ready with no blockers or duplicates. Ready auto-approved candidates are queued for automatic publishing by Drupal cron. Candidates requiring administrator judgment remain queued for review.') . '</p>',
      ],
      'pending' => [
        '#markup' => '<p><strong>' . $this->t('Pending candidates: @count', ['@count' => $pendingCount]) . '</strong></p>',
      ],
      'flag_backlog' => $backlogAlert === NULL
        ? [
          '#type' => 'link',
          '#title' => $this->t('Flag backlog for admin review'),
          '#url' => \Drupal\Core\Url::fromRoute('spotdeals_data_ingestion.deal_discovery_backlog_flag'),
          '#attributes' => ['class' => ['button']],
        ]
        : [
          '#markup' => '<p><strong>' . $this->t('This backlog has already been flagged for administrator review.') . '</strong></p>',
        ],
    ];

    $venueTypeOptions = [];
    foreach ($this->venueTypeResolver->mappedVenueTypes() as $definition) {
      $venueTypeOptions[(string) $definition['tid']] = $definition['name'];
    }
    natcasesort($venueTypeOptions);

    $form['venue_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Venue category'),
      '#options' => $venueTypeOptions,
      '#empty_option' => $this->t('- Select a venue category -'),
      '#description' => $this->t('Categories come from the existing SpotDeals venue-type taxonomy and its configured Geoapify mappings.'),
      '#required' => TRUE,
    ];

    $form['location'] = [
      '#type' => 'select',
      '#title' => $this->t('Location'),
      '#options' => $this->locationResolver->options(),
      '#empty_option' => $this->t('- Select a location -'),
      '#description' => $this->t('Locations come from cities already represented by SpotDeals venue content.'),
      '#required' => TRUE,
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced options'),
      '#open' => FALSE,
    ];

    $form['advanced']['candidate_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Candidate limit'),
      '#default_value' => 50,
      '#min' => 1,
      '#max' => 50,
      '#required' => TRUE,
    ];

    $form['advanced']['site_pages'] = [
      '#type' => 'number',
      '#title' => $this->t('Website pages per candidate'),
      '#default_value' => 5,
      '#min' => 1,
      '#max' => 10,
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run deal discovery'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $venueTypeTid = (int) $form_state->getValue('venue_type');
    $venueType = NULL;
    foreach ($this->venueTypeResolver->mappedVenueTypes() as $definition) {
      if ((int) $definition['tid'] === $venueTypeTid) {
        $venueType = $definition;
        break;
      }
    }

    if ($venueType === NULL) {
      $this->messenger()->addError($this->t('The selected venue category does not have an active Geoapify mapping.'));
      return;
    }

    $location = $this->locationResolver->decode(
      (string) $form_state->getValue('location'),
    );
    if ($location === NULL) {
      $this->messenger()->addError($this->t('The selected location is invalid.'));
      return;
    }

    $candidateLimit = max(1, min(50, (int) $form_state->getValue('candidate_limit')));
    $sitePages = max(1, min(10, (int) $form_state->getValue('site_pages')));

    try {
      $result = $this->runner->run(
        $venueType,
        $location,
        $candidateLimit,
        $sitePages,
      );
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Deal discovery failed: @message', [
        '@message' => $exception->getMessage(),
      ]));
      return;
    }

    $history = is_array($result['history'] ?? NULL) ? $result['history'] : [];
    $categories = is_array($history['categories'] ?? NULL) ? $history['categories'] : [];
    $locations = is_array($history['locations'] ?? NULL) ? $history['locations'] : [];

    $this->messenger()->addStatus($this->t(
      'Discovery completed for @category in @location. Researched @researched venue candidates, found @review qualifying venues, and queued/refreshed @queued deal candidates: @auto ready and queued for automatic cron publishing, @duplicates automatically rejected as existing duplicates, @rejected rejected/non-actionable, and @pending pending manual review. Explored so far: categories — @categories; cities — @cities.',
      [
        '@category' => (string) ($result['category_label'] ?? ''),
        '@location' => (string) ($result['location_label'] ?? ''),
        '@researched' => (int) ($result['researched'] ?? 0),
        '@review' => (int) ($result['review_venues'] ?? 0),
        '@queued' => (int) ($result['queued'] ?? 0),
        '@auto' => (int) ($result['auto_approved'] ?? 0),
        '@duplicates' => (int) ($result['duplicates_rejected'] ?? 0),
        '@rejected' => (int) ($result['rejected'] ?? 0),
        '@pending' => (int) ($result['pending'] ?? 0),
        '@categories' => implode(', ', $categories),
        '@cities' => implode(', ', $locations),
      ],
    ));

    $form_state->setRedirect('spotdeals_data_ingestion.deal_discovery_candidates');
  }

}
