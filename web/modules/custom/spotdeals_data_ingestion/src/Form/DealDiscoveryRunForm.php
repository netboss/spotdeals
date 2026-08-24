<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryConfidenceClassifier;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryLocationResolver;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryService;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage;
use Drupal\spotdeals_data_ingestion\Service\GeoapifyClient;
use Drupal\spotdeals_data_ingestion\Service\VenueCandidateValidator;
use Drupal\spotdeals_data_ingestion\Service\VenueMapper;
use Drupal\spotdeals_data_ingestion\Service\VenueTypeResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs review-only deal discovery from Drupal administration.
 */
final class DealDiscoveryRunForm extends FormBase {

  private const API_KEY_STATE_NAME = 'spotdeals_data_ingestion.geoapify_api_key';

  public function __construct(
    private readonly GeoapifyClient $geoapifyClient,
    private readonly VenueMapper $venueMapper,
    private readonly VenueCandidateValidator $candidateValidator,
    private readonly DealDiscoveryService $dealDiscoveryService,
    private readonly DealDiscoveryStorage $storage,
    private readonly StateInterface $state,
    private readonly VenueTypeResolver $venueTypeResolver,
    private readonly DealDiscoveryLocationResolver $locationResolver,
    private readonly DealDiscoveryConfidenceClassifier $confidenceClassifier,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('Drupal\spotdeals_data_ingestion\Service\GeoapifyClient'),
      $container->get('Drupal\spotdeals_data_ingestion\Service\VenueMapper'),
      $container->get('Drupal\spotdeals_data_ingestion\Service\VenueCandidateValidator'),
      $container->get('Drupal\spotdeals_data_ingestion\Service\DealDiscoveryService'),
      $container->get('spotdeals_data_ingestion.deal_discovery_storage'),
      $container->get('state'),
      $container->get('Drupal\spotdeals_data_ingestion\Service\VenueTypeResolver'),
      $container->get('spotdeals_data_ingestion.deal_discovery_location_resolver'),
      $container->get('spotdeals_data_ingestion.deal_discovery_confidence_classifier'),
    );
  }

  public function getFormId(): string {
    return 'spotdeals_deal_discovery_run_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['notice'] = [
      '#markup' => '<p>' . $this->t('This runs deal discovery and classifies candidates by confidence. High-confidence candidates are automatically approved in shadow mode; uncertain candidates remain pending for review. No venue nodes, deal nodes, or CSV rows are created.') . '</p>',
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
    $apiKey = trim((string) $this->state->get(self::API_KEY_STATE_NAME, ''));
    if ($apiKey === '') {
      $this->messenger()->addError($this->t('No Geoapify API key is configured.'));
      return;
    }

    $venueTypeTid = (int) $form_state->getValue('venue_type');
    $category = '';
    foreach ($this->venueTypeResolver->mappedVenueTypes() as $definition) {
      if ((int) $definition['tid'] !== $venueTypeTid) {
        continue;
      }

      $category = implode(',', $definition['categories']);
      break;
    }

    if ($category === '') {
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

    try {
      $placeId = $this->geoapifyClient->resolveLocalityPlaceId(
        $apiKey,
        $location['city'],
        $location['state'],
        $location['country'],
      );
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t(
        'Could not resolve the selected location with Geoapify: @message',
        ['@message' => $exception->getMessage()],
      ));
      return;
    }

    $candidateLimit = max(1, min(50, (int) $form_state->getValue('candidate_limit')));
    $sitePages = max(1, min(10, (int) $form_state->getValue('site_pages')));

    try {
      $features = $this->geoapifyClient->fetchPlaces(
        apiKey: $apiKey,
        placeId: $placeId,
        category: $category,
        pageSize: 100,
        maxPages: 5,
      );
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Deal discovery failed: @message', [
        '@message' => $exception->getMessage(),
      ]));
      return;
    }

    $venues = array_map(
      fn (array $feature): array => $this->venueMapper->map($feature, $category),
      $features,
    );
    $venues = $this->candidateValidator->validateBatch($venues);

    $researched = 0;
    $reviewVenues = 0;
    $queued = 0;
    $autoApproved = 0;
    $pendingReview = 0;
    $researchedWebsiteHosts = [];

    foreach ($venues as $venue) {
      if (!$venue['valid'] || $venue['existing_duplicate'] || $venue['batch_duplicate']) {
        continue;
      }

      if ($researched >= $candidateLimit) {
        break;
      }

      if (($venue['website'] ?? '') === '' && ($venue['external_id'] ?? '') !== '') {
        try {
          $detailsFeature = $this->geoapifyClient->getPlaceDetails(
            $apiKey,
            (string) $venue['external_id'],
          );
          $enrichedVenue = $this->venueMapper->map($detailsFeature, $category);
          if (($enrichedVenue['website'] ?? '') !== '') {
            $venue['website'] = $enrichedVenue['website'];
          }
          if (($venue['phone'] ?? '') === '' && ($enrichedVenue['phone'] ?? '') !== '') {
            $venue['phone'] = $enrichedVenue['phone'];
          }
        }
        catch (\Throwable) {
          // Discovery remains best-effort. A failed details lookup does not
          // prevent the remaining candidates from being researched.
        }
      }

      $websiteHost = $this->normalizedWebsiteHost((string) ($venue['website'] ?? ''));
      if ($websiteHost !== '' && isset($researchedWebsiteHosts[$websiteHost])) {
        continue;
      }
      if ($websiteHost !== '') {
        $researchedWebsiteHosts[$websiteHost] = TRUE;
      }

      $researched++;
      $result = $this->dealDiscoveryService->discover($venue, $sitePages);
      if (($result['recommendation'] ?? '') !== 'REVIEW') {
        continue;
      }

      $reviewVenues++;
      $address = is_array($venue['address'] ?? NULL) ? $venue['address'] : [];
      $venueAddress = trim(implode(', ', array_filter([
        (string) ($address['address_line1'] ?? ''),
        (string) ($address['locality'] ?? ''),
        (string) ($address['state_code'] ?? ''),
        (string) ($address['postcode'] ?? ''),
      ])));

      foreach ($result['deal_candidates'] as $candidate) {
        $classification = $this->confidenceClassifier->classify(
          $candidate,
          (int) ($result['location_confidence'] ?? 0),
        );

        if ($classification['status'] === 'auto_approved') {
          $autoApproved++;
        }
        else {
          $pendingReview++;
        }

        $this->storage->createOrRefresh([
          'external_source' => (string) ($venue['external_provider'] ?? 'geoapify'),
          'external_id' => (string) ($venue['external_id'] ?? ''),
          'venue_name' => (string) ($venue['title'] ?? ''),
          'venue_address' => $venueAddress,
          'venue_website' => (string) ($result['website'] ?? $venue['website'] ?? ''),
          'category' => $category,
          'place_id' => $placeId,
          'offer_title' => (string) ($candidate['title'] ?? ''),
          'offer_value' => (string) ($candidate['value'] ?? ''),
          'schedule' => (string) ($candidate['schedule'] ?? ''),
          'source_url' => (string) ($candidate['source_url'] ?? ''),
          'reason' => (string) ($candidate['reason'] ?? ''),
          'score' => (int) ($candidate['score'] ?? 0),
          'status' => $classification['status'],
          'confidence' => $classification['confidence'],
          'classification_reason' => implode('; ', $classification['reasons']),
        ]);
        $queued++;
      }
    }

    $this->messenger()->addStatus($this->t(
      'Discovery completed. Researched @researched venue candidates, found @review qualifying venues, and queued/refreshed @queued deal candidates: @auto automatically approved in shadow mode and @pending pending manual review.',
      [
        '@researched' => $researched,
        '@review' => $reviewVenues,
        '@queued' => $queued,
        '@auto' => $autoApproved,
        '@pending' => $pendingReview,
      ],
    ));

    $form_state->setRedirect('spotdeals_data_ingestion.deal_discovery_candidates');
  }

  private function normalizedWebsiteHost(string $website): string {
    $website = trim($website);
    if ($website === '') {
      return '';
    }

    if (!preg_match('#^https?://#i', $website)) {
      $website = 'https://' . ltrim($website, '/');
    }

    $host = mb_strtolower((string) parse_url($website, PHP_URL_HOST));
    return preg_replace('/^www\./i', '', $host) ?? $host;
  }

}
