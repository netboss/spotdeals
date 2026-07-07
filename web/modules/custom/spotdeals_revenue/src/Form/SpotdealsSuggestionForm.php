<?php

namespace Drupal\spotdeals_revenue\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public form for suggesting missing venues and deals.
 */
class SpotdealsSuggestionForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs the suggestion form.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'spotdeals_suggestion_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = \Drupal::request();
    $requested_type = (string) $request->query->get('type', 'both');
    $allowed_types = ['venue', 'deal', 'both'];
    $default_type = in_array($requested_type, $allowed_types, TRUE) ? $requested_type : 'deal';
    $default_venue_name = trim((string) $request->query->get('venue', ''));
    $default_deal_name = trim((string) $request->query->get('deal', ''));

    $form['#attached']['library'][] = 'spotdeals_revenue/suggest';
    $form['#attributes']['class'][] = 'spotdeals-suggestion-form';

    $form['intro'] = [
      '#markup' => '<div class="spotdeals-suggestion-form__intro"><p>' . $this->t('Know a venue or deal SpotDeals is missing? Send it over and we will review it before publishing.') . '</p></div>',
    ];

    $form['type'] = [
      '#type' => 'radios',
      '#title' => $this->t('What are you suggesting?'),
      '#options' => [
        'venue' => $this->t('A venue'),
        'deal' => $this->t('A deal'),
        'both' => $this->t('Both a venue and a deal'),
      ],
      '#default_value' => $default_type,
      '#required' => TRUE,
    ];

    $form['venue_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Venue name'),
      '#description' => $this->t('For deal suggestions, enter the existing venue name so we can match the deal to the correct SpotDeals venue.'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#default_value' => $default_venue_name,
      '#attributes' => [
        'autocomplete' => 'organization',
      ],
    ];

    $form['deal_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deal name'),
      '#description' => $this->t('Example: Taco Tuesday, Happy Hour, Lunch Special.'),
      '#maxlength' => 255,
      '#default_value' => $default_deal_name,
      '#states' => [
        'visible' => [
          [':input[name="type"]' => ['value' => 'deal']],
          'or',
          [':input[name="type"]' => ['value' => 'both']],
        ],
        'required' => [
          [':input[name="type"]' => ['value' => 'deal']],
          'or',
          [':input[name="type"]' => ['value' => 'both']],
        ],
      ],
      '#attributes' => [
        'autocomplete' => 'off',
        'data-spotdeals-suggestion-scope' => 'deal',
      ],
    ];

    $venue_only_states = [
      'visible' => [
        [':input[name="type"]' => ['value' => 'venue']],
        'or',
        [':input[name="type"]' => ['value' => 'both']],
      ],
    ];
    $deal_only_states = [
      'visible' => [
        [':input[name="type"]' => ['value' => 'deal']],
        'or',
        [':input[name="type"]' => ['value' => 'both']],
      ],
    ];

    $form['street_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Street address'),
      '#description' => $this->t('Example: 1100 Orlando Ave.'),
      '#maxlength' => 255,
      '#states' => $venue_only_states,
      '#attributes' => [
        'autocomplete' => 'street-address',
        'data-spotdeals-suggestion-scope' => 'venue',
      ],
    ];

    $form['city'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City'),
      '#description' => $this->t('For deal suggestions, this helps us match the deal to the correct existing venue.'),
      '#maxlength' => 128,
      '#states' => [
        'visible' => [
          [':input[name="type"]' => ['value' => 'venue']],
          'or',
          [':input[name="type"]' => ['value' => 'deal']],
          'or',
          [':input[name="type"]' => ['value' => 'both']],
        ],
        'required' => [
          [':input[name="type"]' => ['value' => 'venue']],
          'or',
          [':input[name="type"]' => ['value' => 'deal']],
          'or',
          [':input[name="type"]' => ['value' => 'both']],
        ],
      ],
      '#attributes' => [
        'autocomplete' => 'address-level2',
      ],
    ];

    $form['state'] = [
      '#type' => 'textfield',
      '#title' => $this->t('State or province'),
      '#description' => $this->t('Example: Florida, or FL.'),
      '#maxlength' => 64,
      '#states' => [
        'visible' => [
          [':input[name="type"]' => ['value' => 'venue']],
          'or',
          [':input[name="type"]' => ['value' => 'deal']],
          'or',
          [':input[name="type"]' => ['value' => 'both']],
        ],
        'required' => [
          [':input[name="type"]' => ['value' => 'venue']],
          'or',
          [':input[name="type"]' => ['value' => 'deal']],
          'or',
          [':input[name="type"]' => ['value' => 'both']],
        ],
      ],
      '#attributes' => [
        'autocomplete' => 'address-level1',
        'data-spotdeals-suggestion-scope' => 'location',
      ],
    ];

    $form['zip'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ZIP or postal code'),
      '#description' => $this->t('For deal suggestions, ZIP can be used instead of city.'),
      '#maxlength' => 32,
      '#states' => [
        'visible' => [
          [':input[name="type"]' => ['value' => 'venue']],
          'or',
          [':input[name="type"]' => ['value' => 'deal']],
          'or',
          [':input[name="type"]' => ['value' => 'both']],
        ],
        'required' => [
          [':input[name="type"]' => ['value' => 'venue']],
          'or',
          [':input[name="type"]' => ['value' => 'deal']],
          'or',
          [':input[name="type"]' => ['value' => 'both']],
        ],
      ],
      '#attributes' => [
        'autocomplete' => 'postal-code',
        'inputmode' => 'numeric',
      ],
    ];

    $form['country'] = [
      '#type' => 'select',
      '#title' => $this->t('Country'),
      '#options' => [
        'US' => $this->t('United States'),
      ],
      '#default_value' => 'US',
      '#states' => $venue_only_states,
      '#attributes' => [
        'data-spotdeals-suggestion-scope' => 'venue',
      ],
    ];

    $form['website'] = [
      '#type' => 'url',
      '#title' => $this->t('Website or source URL'),
      '#description' => $this->t('A venue website, menu page, Instagram post, or another source that helps verify the suggestion.'),
      '#maxlength' => 512,
    ];

    $form['deal_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Deal details'),
      '#description' => $this->t('Example: Half-price tacos every Tuesday from 4 PM to 7 PM.'),
      '#rows' => 4,
      '#states' => $deal_only_states,
      '#attributes' => [
        'data-spotdeals-suggestion-scope' => 'deal',
      ],
    ];

    $form['days'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Days'),
      '#description' => $this->t('Example: Monday to Friday, Tuesday, weekends, daily.'),
      '#maxlength' => 255,
      '#states' => $deal_only_states,
      '#attributes' => [
        'data-spotdeals-suggestion-scope' => 'deal',
      ],
    ];

    $form['times'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Times'),
      '#description' => $this->t('Example: 4 PM to 7 PM, all day, late night.'),
      '#maxlength' => 255,
      '#states' => $deal_only_states,
      '#attributes' => [
        'data-spotdeals-suggestion-scope' => 'deal',
      ],
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#description' => $this->t('Anything else that would help us verify this suggestion.'),
      '#rows' => 3,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Your email'),
      '#description' => $this->t('Optional. Only used if we need to ask a follow-up question.'),
      '#maxlength' => 255,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit suggestion'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $type = (string) $form_state->getValue('type');
    $venue_name = $this->cleanSingleLine((string) $form_state->getValue('venue_name'));
    $deal_name = $this->cleanSingleLine((string) $form_state->getValue('deal_name'));
    $deal_description = trim((string) $form_state->getValue('deal_description'));
    $city = $this->cleanSingleLine((string) $form_state->getValue('city'));
    $zip = $this->cleanSingleLine((string) $form_state->getValue('zip'));
    $street_address = $this->cleanSingleLine((string) $form_state->getValue('street_address'));
    $state = $this->cleanSingleLine((string) $form_state->getValue('state'));
    $country = $this->cleanSingleLine((string) $form_state->getValue('country'));
    $website = trim((string) $form_state->getValue('website'));
    $email = trim((string) $form_state->getValue('email'));
    $days = $this->cleanSingleLine((string) $form_state->getValue('days'));
    $times = $this->cleanSingleLine((string) $form_state->getValue('times'));
    $deal_location = $this->buildLocationValue($city, $zip);

    if (!in_array($type, ['venue', 'deal', 'both'], TRUE)) {
      $form_state->setErrorByName('type', $this->t('Please choose what you are suggesting.'));
      return;
    }

    if (!$this->isValidName($venue_name)) {
      $form_state->setErrorByName('venue_name', $this->t('Please enter a real venue name using letters or numbers.'));
    }

    if (in_array($type, ['deal', 'both'], TRUE) && !$this->isValidName($deal_name)) {
      $form_state->setErrorByName('deal_name', $this->t('Please enter a real deal name using letters or numbers.'));
    }

    if (in_array($type, ['deal', 'both'], TRUE) && !$this->isValidLongText($deal_description, 12)) {
      $form_state->setErrorByName('deal_description', $this->t('Please include useful deal details.'));
    }

    if (in_array($type, ['venue', 'both'], TRUE) && !$this->isValidStreetAddress($street_address)) {
      $form_state->setErrorByName('street_address', $this->t('Please enter a valid street address, for example 1100 Orlando Ave.'));
    }

    if (!$this->isValidCity($city)) {
      $form_state->setErrorByName('city', $this->t('Please enter a valid city name.'));
    }

    if (!$this->isValidState($state)) {
      $form_state->setErrorByName('state', $this->t('Please enter a valid state or province, for example Florida or FL.'));
    }

    if (!$this->isValidPostalCode($zip, $country)) {
      $form_state->setErrorByName('zip', $this->t('Please enter a valid ZIP or postal code.'));
    }

    if ($country !== 'US') {
      $form_state->setErrorByName('country', $this->t('Only United States suggestions are supported right now.'));
    }

    if ($website !== '' && !$this->isValidExternalUrl($website)) {
      $form_state->setErrorByName('website', $this->t('Please enter a valid website or source URL, including https://.'));
    }

    if ($email !== '' && !\Drupal::service('email.validator')->isValid($email)) {
      $form_state->setErrorByName('email', $this->t('Please enter a valid email address.'));
    }

    if (in_array($type, ['deal', 'both'], TRUE) && $days !== '' && !$this->isReasonableScheduleText($days)) {
      $form_state->setErrorByName('days', $this->t('Please enter valid days, for example Monday to Friday, Tuesday, weekends, or daily.'));
    }

    if (in_array($type, ['deal', 'both'], TRUE) && $times !== '' && !$this->isReasonableScheduleText($times)) {
      $form_state->setErrorByName('times', $this->t('Please enter valid times, for example 4 PM to 7 PM, all day, or late night.'));
    }

    if ($type === 'deal' && $deal_location !== '') {
      $matched_venue = $this->findExistingVenueByTitleAndLocation($venue_name, $deal_location);
      if (!$matched_venue) {
        $form_state->setErrorByName('type', $this->t('We could not find an existing SpotDeals venue matching this name and city/ZIP. Please choose "Both a venue and a deal" so we can review the venue and deal together.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $now = \Drupal::time()->getRequestTime();
    $type = (string) $form_state->getValue('type');
    $venue_name = trim((string) $form_state->getValue('venue_name'));
    $deal_name = trim((string) $form_state->getValue('deal_name'));
    $deal_description = trim((string) $form_state->getValue('deal_description'));
    $city = trim((string) $form_state->getValue('city'));
    $zip = trim((string) $form_state->getValue('zip'));
    $street_address = trim((string) $form_state->getValue('street_address'));
    $state = trim((string) $form_state->getValue('state'));
    $country = trim((string) $form_state->getValue('country'));
    $deal_location = $this->buildLocationValue($city, $zip);
    $address = $this->buildAddressValue($street_address, $city, $state, $zip, $country);

    $moderation = [
      'matched_venue_nid' => 0,
      'free_limit_blocked' => 0,
      'owner_notified' => 0,
      'owner_notified_time' => 0,
    ];

    $submitter_email = trim((string) $form_state->getValue('email'));

    if (in_array($type, ['deal', 'both'], TRUE)) {
      $moderation = $this->buildDealSuggestionModerationData($venue_name, $deal_location, $deal_description, $submitter_email, $now);
    }

    $this->database->insert('spotdeals_suggestion')
      ->fields([
        'type' => $type,
        'venue_name' => $venue_name,
        'address' => $address,
        'deal_location' => $deal_location,
        'street_address' => $street_address,
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'country' => $country !== '' ? $country : 'US',
        'deal_name' => $deal_name,
        'website' => trim((string) $form_state->getValue('website')),
        'deal_description' => $deal_description,
        'days' => trim((string) $form_state->getValue('days')),
        'times' => trim((string) $form_state->getValue('times')),
        'notes' => trim((string) $form_state->getValue('notes')),
        'email' => $submitter_email,
        'status' => $moderation['status'],
        'matched_venue_nid' => $moderation['matched_venue_nid'],
        'free_limit_blocked' => $moderation['free_limit_blocked'],
        'owner_notified' => $moderation['owner_notified'],
        'owner_notified_time' => $moderation['owner_notified_time'],
        'uid' => (int) $this->currentUser()->id(),
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();

    if ($moderation['free_limit_blocked']) {
      $this->messenger()->addStatus($this->t('Thank you. Your suggestion was submitted for review. This venue may already have its complimentary deal listed, so additional deals may require owner review.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Thank you. Your suggestion was submitted for review.'));
    }
    $form_state->setRedirect('<front>');
  }


  /**
   * Removes line breaks and extra spaces from a textfield value.
   */
  private function cleanSingleLine(string $value): string {
    $value = trim(strip_tags($value));
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
  }

  /**
   * Validates a short user-entered name such as a venue or deal title.
   */
  private function isValidName(string $value): bool {
    $value = $this->cleanSingleLine($value);
    if (mb_strlen($value) < 2 || mb_strlen($value) > 255) {
      return FALSE;
    }

    return (bool) preg_match('/[\p{L}\p{N}]/u', $value);
  }

  /**
   * Validates long text fields that should contain useful human text.
   */
  private function isValidLongText(string $value, int $minimum_length = 8): bool {
    $value = trim(strip_tags($value));
    if (mb_strlen($value) < $minimum_length) {
      return FALSE;
    }

    return (bool) preg_match('/[\p{L}\p{N}]/u', $value);
  }

  /**
   * Validates street address text without pretending to geocode it.
   */
  private function isValidStreetAddress(string $value): bool {
    $value = $this->cleanSingleLine($value);
    if (mb_strlen($value) < 5 || mb_strlen($value) > 255) {
      return FALSE;
    }

    // A street address should normally contain both a number and letters.
    return (bool) preg_match('/\d/u', $value) && (bool) preg_match('/\p{L}/u', $value);
  }

  /**
   * Validates city/locality text.
   */
  private function isValidCity(string $value): bool {
    $value = $this->cleanSingleLine($value);
    if (mb_strlen($value) < 2 || mb_strlen($value) > 128) {
      return FALSE;
    }

    return (bool) preg_match('/^[\p{L}][\p{L}\p{M}\s.\'-]*$/u', $value);
  }

  /**
   * Validates state/province text.
   */
  private function isValidState(string $value): bool {
    $value = $this->cleanSingleLine($value);
    if (mb_strlen($value) < 2 || mb_strlen($value) > 64) {
      return FALSE;
    }

    return (bool) preg_match('/^[\p{L}][\p{L}\p{M}\s\'-]*$/u', $value);
  }

  /**
   * Validates postal codes for the currently supported country set.
   */
  private function isValidPostalCode(string $value, string $country): bool {
    $value = strtoupper($this->cleanSingleLine($value));
    if ($country === 'US' || $country === '') {
      return (bool) preg_match('/^\d{5}(?:-\d{4})?$/', $value);
    }

    return (bool) preg_match('/^[A-Z0-9][A-Z0-9\s-]{2,15}$/', $value);
  }

  /**
   * Validates source URLs for user-submitted suggestions.
   */
  private function isValidExternalUrl(string $value): bool {
    if (!filter_var($value, FILTER_VALIDATE_URL)) {
      return FALSE;
    }

    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], TRUE);
  }

  /**
   * Basic sanity check for days/times fields.
   */
  private function isReasonableScheduleText(string $value): bool {
    $value = $this->cleanSingleLine($value);
    if (mb_strlen($value) < 2 || mb_strlen($value) > 255) {
      return FALSE;
    }

    return (bool) preg_match('/[\p{L}\p{N}]/u', $value);
  }

  /**
   * Builds a reusable location string from city and ZIP values.
   */
  private function buildLocationValue(string $city, string $zip): string {
    $parts = array_filter([trim($city), trim($zip)], static fn(string $value): bool => $value !== '');
    return implode(' ', $parts);
  }

  /**
   * Builds the legacy address string stored with the suggestion.
   */
  private function buildAddressValue(string $street_address, string $city, string $state, string $zip, string $country): string {
    $state_zip = trim(trim($state) . ' ' . trim($zip));
    $parts = array_filter([
      trim($street_address),
      trim($city),
      $state_zip,
      trim($country) !== '' ? trim($country) : 'US',
    ], static fn(string $value): bool => $value !== '');

    return implode(', ', $parts);
  }

  /**
   * Builds moderation metadata for a submitted deal suggestion.
   */
  private function buildDealSuggestionModerationData(string $venue_name, string $deal_location, string $deal_description, string $submitter_email, int $now): array {
    $data = [
      'matched_venue_nid' => 0,
      'free_limit_blocked' => 0,
      'owner_notified' => 0,
      'owner_notified_time' => 0,
      'status' => 'new',
    ];

    $venue = $this->findExistingVenueByTitleAndLocation($venue_name, $deal_location);
    if (!$venue) {
      return $data;
    }

    $data['matched_venue_nid'] = (int) $venue->id();
    $free_limit = max(0, (int) (\Drupal::config('spotdeals_revenue.settings')->get('free_deals_per_venue') ?? 1));
    $active_deal_count = $this->countPublishedDealsForVenue((int) $venue->id());

    if ($active_deal_count >= $free_limit) {
      $data['free_limit_blocked'] = 1;
      if (\Drupal::config('spotdeals_revenue.settings')->get('owner_notifications_enabled') !== FALSE) {
        $notification_result = $this->notifyDealSuggestionRecipient($venue, $deal_description, $submitter_email);
        if ($notification_result === 'sent') {
          $data['owner_notified'] = 1;
          $data['owner_notified_time'] = $now;
        }
        elseif ($notification_result === 'no_recipient') {
          $data['status'] = 'needs_verification';
        }
      }
    }

    return $data;
  }

  /**
   * Finds an existing published venue by normalized title and location.
   */
  private function findExistingVenueByTitleAndLocation(string $venue_name, string $location): ?\Drupal\node\NodeInterface {
    $normalized_suggestion = $this->normalizeVenueTitle($venue_name);
    $normalized_location = $this->normalizeLocation($location);
    if ($normalized_suggestion === '') {
      return NULL;
    }

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'venue')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!$nids) {
      return NULL;
    }

    $fallback_match = NULL;
    foreach ($storage->loadMultiple($nids) as $venue) {
      if ($this->normalizeVenueTitle($venue->label()) !== $normalized_suggestion) {
        continue;
      }

      if ($fallback_match === NULL) {
        $fallback_match = $venue;
      }

      if ($normalized_location !== '' && $this->venueMatchesLocation($venue, $normalized_location)) {
        return $venue;
      }
    }

    // If no location was supplied, keep the old title-only behavior. If a
    // location was supplied but none of the title matches also matched the
    // location, do not guess between similarly named venues in different cities.
    return $normalized_location === '' ? $fallback_match : NULL;
  }

  /**
   * Checks whether a venue node matches a normalized city or ZIP value.
   */
  private function venueMatchesLocation(\Drupal\node\NodeInterface $venue, string $normalized_location): bool {
    $parts = [$venue->label()];

    if ($venue->hasField('field_address') && !$venue->get('field_address')->isEmpty()) {
      foreach ($venue->get('field_address') as $address) {
        foreach (['locality', 'postal_code', 'administrative_area', 'address_line1'] as $property) {
          $value = trim((string) ($address->{$property} ?? ''));
          if ($value !== '') {
            $parts[] = $value;
          }
        }
      }
    }

    $haystack = $this->normalizeLocation(implode(' ', $parts));
    return $haystack !== '' && str_contains($haystack, $normalized_location);
  }

  /**
   * Normalizes a city or ZIP value for location matching.
   */
  private function normalizeLocation(string $location): string {
    $location = html_entity_decode($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $location = mb_strtolower(trim($location), 'UTF-8');
    $location = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $location) ?? $location;
    return trim(preg_replace('/\s+/u', ' ', $location) ?? $location);
  }

  /**
   * Counts published deals attached to a venue.
   */
  private function countPublishedDealsForVenue(int $venue_nid): int {
    if (!$this->database->schema()->tableExists('node__field_venue')) {
      return 0;
    }

    $query = $this->database->select('node_field_data', 'n');
    $query->join('node__field_venue', 'fv', 'fv.entity_id = n.nid');
    $query->condition('n.type', 'deal')
      ->condition('n.status', 1)
      ->condition('n.default_langcode', 1)
      ->condition('fv.field_venue_target_id', $venue_nid);

    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Attempts to notify the best available recipient for a gated deal suggestion.
   *
   * Recipient order:
   * - Registered venue owner from field_primary_owner_user.
   * - Claim contact email from field_claim_contact_email.
   * - Suggestion submitter email.
   *
   * @return string
   *   One of: sent, failed, or no_recipient.
   */
  private function notifyDealSuggestionRecipient(\Drupal\node\NodeInterface $venue, string $deal_description, string $submitter_email): string {
    $emails = $this->getDealSuggestionRecipientEmails($venue, $submitter_email);
    if (!$emails) {
      \Drupal::logger('spotdeals_revenue')->notice('Archived extra deal suggestion for venue @nid because no owner, claim contact, or submitter email was available.', [
        '@nid' => $venue->id(),
      ]);
      return 'no_recipient';
    }

    $mail_manager = \Drupal::service('plugin.manager.mail');
    $langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
    $sent = FALSE;

    foreach ($emails as $email) {
      $result = $mail_manager->mail('spotdeals_revenue', 'deal_suggestion_owner_notice', $email, $langcode, [
        'venue_title' => $venue->label(),
        'deal_description' => $deal_description,
      ]);
      $sent = $sent || !empty($result['result']);
    }

    if (!$sent) {
      \Drupal::logger('spotdeals_revenue')->warning('Failed to send extra deal suggestion notification for venue @nid.', [
        '@nid' => $venue->id(),
      ]);
      return 'failed';
    }

    return 'sent';
  }

  /**
   * Gets notification recipient emails for a gated deal suggestion.
   */
  private function getDealSuggestionRecipientEmails(\Drupal\node\NodeInterface $venue, string $submitter_email): array {
    $emails = [];

    if ($venue->hasField('field_primary_owner_user') && !$venue->get('field_primary_owner_user')->isEmpty()) {
      foreach ($venue->get('field_primary_owner_user')->referencedEntities() as $entity) {
        if ($entity instanceof \Drupal\user\UserInterface && $entity->getEmail()) {
          $emails[] = $entity->getEmail();
        }
      }
    }

    if (!$emails && $venue->hasField('field_claim_contact_email') && !$venue->get('field_claim_contact_email')->isEmpty()) {
      $claim_contact_email = trim((string) $venue->get('field_claim_contact_email')->value);
      if ($claim_contact_email !== '' && \Drupal::service('email.validator')->isValid($claim_contact_email)) {
        $emails[] = $claim_contact_email;
      }
    }

    if (!$emails && $submitter_email !== '' && \Drupal::service('email.validator')->isValid($submitter_email)) {
      $emails[] = $submitter_email;
    }

    return array_values(array_unique($emails));
  }

  /**
   * Normalizes a venue title for duplicate checks.
   */
  private function normalizeVenueTitle(string $title): string {
    $title = html_entity_decode($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title = trim($title);
    $title = preg_replace('/\s+-\s+[^-]+$/u', '', $title) ?? $title;
    $title = mb_strtolower($title, 'UTF-8');
    $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

    $tokens = $title === '' ? [] : preg_split('/\s+/u', $title);
    $weak_suffixes = [
      'bar',
      'bistro',
      'cafe',
      'café',
      'cantina',
      'cuisine',
      'diner',
      'grill',
      'kitchen',
      'pub',
      'restaurant',
      'restaurants',
      'tavern',
    ];

    // Suggestions often omit generic trailing words from official venue names,
    // for example "Istanbul Turkish Mediterranean" vs. "Istanbul Turkish
    // Mediterranean Cuisine - Daytona Beach Shores". Keep at least three
    // original tokens before removing a weak suffix so names like "Cafe Don
    // Juan" are not collapsed too aggressively.
    while (count($tokens) > 2 && in_array(end($tokens), $weak_suffixes, TRUE)) {
      array_pop($tokens);
    }

    return trim(implode(' ', $tokens));
  }


}
