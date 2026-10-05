<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\State\StateInterface;

/**
 * Runs the shared deal-discovery workflow for manual and scheduled discovery.
 */
final class DealDiscoveryRunner {

  private const API_KEY_STATE_NAME = 'spotdeals_data_ingestion.geoapify_api_key';

  private const DISCOVERY_HISTORY_STATE_NAME = 'spotdeals_data_ingestion.deal_discovery_history';

  public function __construct(
    private readonly GeoapifyClient $geoapifyClient,
    private readonly VenueMapper $venueMapper,
    private readonly VenueCandidateValidator $candidateValidator,
    private readonly DealDiscoveryService $dealDiscoveryService,
    private readonly DealDiscoveryStorage $storage,
    private readonly StateInterface $state,
    private readonly DealDiscoveryConfidenceClassifier $confidenceClassifier,
    private readonly DealDiscoveryContentQualityService $contentQuality,
    private readonly DealDiscoveryPublishPreviewService $publishPreview,
  ) {}

  /**
   * Runs one category/location discovery pass.
   *
   * @param array{tid: int|string, name: string, categories: string[]} $venueType
   *   One mapped SpotDeals venue-type definition.
   * @param array{city: string, state: string, country: string} $location
   *   Discovery location.
   * @param int $candidateLimit
   *   Maximum venue candidates to research.
   * @param int $sitePages
   *   Maximum website pages to research per candidate.
   *
   * @return array<string, mixed>
   *   Discovery result counters and labels.
   */
  public function run(
    array $venueType,
    array $location,
    int $candidateLimit = 50,
    int $sitePages = 5,
  ): array {
    $apiKey = trim((string) $this->state->get(self::API_KEY_STATE_NAME, ''));
    if ($apiKey === '') {
      throw new \RuntimeException('No Geoapify API key is configured.');
    }

    $category = implode(',', array_filter(array_map(
      static fn (mixed $value): string => trim((string) $value),
      (array) ($venueType['categories'] ?? []),
    )));
    $categoryLabel = trim((string) ($venueType['name'] ?? ''));
    if ($category === '') {
      throw new \InvalidArgumentException('The selected venue category does not have an active Geoapify mapping.');
    }

    $city = trim((string) ($location['city'] ?? ''));
    $state = strtoupper(trim((string) ($location['state'] ?? '')));
    $country = strtoupper(trim((string) ($location['country'] ?? 'US')));
    if ($city === '') {
      throw new \InvalidArgumentException('The selected location is invalid.');
    }
    $location = [
      'city' => $city,
      'state' => $state,
      'country' => $country !== '' ? $country : 'US',
    ];

    $placeId = $this->geoapifyClient->resolveLocalityPlaceId(
      $apiKey,
      $location['city'],
      $location['state'],
      $location['country'],
    );

    $candidateLimit = max(1, min(50, $candidateLimit));
    $sitePages = max(1, min(10, $sitePages));

    $features = $this->geoapifyClient->fetchPlaces(
      apiKey: $apiKey,
      placeId: $placeId,
      category: $category,
      pageSize: 100,
      maxPages: 5,
    );

    $venues = array_map(
      fn (array $feature): array => $this->venueMapper->map($feature, $category),
      $features,
    );
    $venues = $this->candidateValidator->validateBatch($venues);

    $researched = 0;
    $reviewVenues = 0;
    $queued = 0;
    $autoApproved = 0;
    $duplicatesRejected = 0;
    $rejectedCandidates = 0;
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
        $candidate = $this->contentQuality->normalizeCandidate($candidate);
        $classification = $this->confidenceClassifier->classify(
          $candidate,
          (int) ($result['location_confidence'] ?? 0),
        );

        $candidateId = $this->storage->createOrRefresh([
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

        $storedCandidate = $this->storage->load($candidateId);
        $autoRejectedDuplicate = FALSE;
        if ((string) ($storedCandidate['status'] ?? '') === 'auto_approved') {
          try {
            $preview = $this->publishPreview->preview($storedCandidate);
            if (empty($preview['ready'])) {
              $deal = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
              $duplicateNid = !empty($deal['duplicate_found'])
                ? (int) ($deal['duplicate_nid'] ?? 0)
                : 0;

              if (
                $duplicateNid > 0
                && $this->storage->markRejectedAsDuplicate($candidateId, $duplicateNid)
              ) {
                $autoRejectedDuplicate = TRUE;
              }
              else {
                $this->storage->markPendingForPublishingReadiness(
                  $candidateId,
                  $this->publishingReadinessReasons($preview),
                );
              }
              $storedCandidate = $this->storage->load($candidateId);
            }
          }
          catch (\Throwable $exception) {
            $this->storage->markPendingForPublishingReadiness(
              $candidateId,
              ['Publishing readiness preview failed: ' . $exception->getMessage()],
            );
            $storedCandidate = $this->storage->load($candidateId);
          }
        }

        if ((string) ($storedCandidate['status'] ?? '') === 'auto_approved') {
          $autoApproved++;
        }
        elseif ($autoRejectedDuplicate) {
          $duplicatesRejected++;
        }
        elseif ((string) ($storedCandidate['status'] ?? '') === 'rejected') {
          $rejectedCandidates++;
        }
        else {
          $pendingReview++;
        }
      }
    }

    $locationLabel = $this->locationLabel($location);
    $historySummary = $this->recordDiscoveryRun($categoryLabel, $locationLabel);

    return [
      'category' => $category,
      'category_label' => $categoryLabel,
      'location_label' => $locationLabel,
      'researched' => $researched,
      'review_venues' => $reviewVenues,
      'queued' => $queued,
      'auto_approved' => $autoApproved,
      'duplicates_rejected' => $duplicatesRejected,
      'rejected' => $rejectedCandidates,
      'pending' => $pendingReview,
      'history' => $historySummary,
    ];
  }

  /**
   * Records a completed discovery run and returns the explored summary.
   *
   * @return array{categories: string[], locations: string[]}
   *   Unique category and location labels seen in completed discovery runs.
   */
  private function recordDiscoveryRun(string $categoryLabel, string $locationLabel): array {
    $history = $this->state->get(self::DISCOVERY_HISTORY_STATE_NAME, []);
    if (!is_array($history)) {
      $history = [];
    }

    $categories = is_array($history['categories'] ?? NULL)
      ? $history['categories']
      : [];
    $locations = is_array($history['locations'] ?? NULL)
      ? $history['locations']
      : [];

    if ($categoryLabel !== '') {
      $categories[] = $categoryLabel;
    }
    if ($locationLabel !== '') {
      $locations[] = $locationLabel;
    }

    $categories = array_values(array_unique(array_filter(array_map(
      static fn (mixed $value): string => trim((string) $value),
      $categories,
    ))));
    $locations = array_values(array_unique(array_filter(array_map(
      static fn (mixed $value): string => trim((string) $value),
      $locations,
    ))));

    natcasesort($categories);
    natcasesort($locations);
    $categories = array_values($categories);
    $locations = array_values($locations);

    $this->state->set(self::DISCOVERY_HISTORY_STATE_NAME, [
      'categories' => $categories,
      'locations' => $locations,
    ]);

    return [
      'categories' => $categories,
      'locations' => $locations,
    ];
  }

  /**
   * Builds the same concise city/state label shown by the discovery form.
   *
   * @param array{city: string, state: string, country: string} $location
   *   Decoded discovery location.
   */
  private function locationLabel(array $location): string {
    $label = trim((string) ($location['city'] ?? ''));
    $state = strtoupper(trim((string) ($location['state'] ?? '')));

    if ($state !== '') {
      $label .= ', ' . $state;
    }

    return $label;
  }

  /**
   * Builds concise publishing-readiness reasons.
   *
   * @param array<string, mixed> $preview
   *   The no-write publishing preview.
   *
   * @return string[]
   *   Human-readable publishing-readiness reasons.
   */
  private function publishingReadinessReasons(array $preview): array {
    $reasons = [];

    foreach ((array) ($preview['errors'] ?? []) as $error) {
      $error = trim((string) $error);
      if ($error !== '') {
        $reasons[] = $error;
      }
    }

    $venue = is_array($preview['venue'] ?? NULL) ? $preview['venue'] : [];
    foreach ((array) ($venue['errors'] ?? []) as $error) {
      $error = trim((string) $error);
      if ($error !== '') {
        $reasons[] = $error;
      }
    }

    $deal = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
    foreach ((array) ($deal['blocking_fields'] ?? []) as $field => $message) {
      $message = trim((string) $message);
      if ($message !== '') {
        $reasons[] = (string) $field . ': ' . $message;
      }
    }

    if (!empty($deal['duplicate_found'])) {
      $duplicateNid = (int) ($deal['duplicate_nid'] ?? 0);
      $reasons[] = $duplicateNid > 0
        ? 'A duplicate deal already exists (node ' . $duplicateNid . ').'
        : 'A duplicate deal already exists.';
    }

    if ($reasons === []) {
      $reasons[] = 'The publishing preview did not report the candidate as ready.';
    }

    return array_values(array_unique($reasons));
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
