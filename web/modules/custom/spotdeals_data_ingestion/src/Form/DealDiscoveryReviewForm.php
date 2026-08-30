<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPublishPreviewService;
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
    private readonly DealDiscoveryPublishPreviewService $publishPreview,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_data_ingestion.deal_discovery_storage'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('spotdeals_data_ingestion.deal_discovery_publish_preview'),
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

    $form['automatic_publishing'] = $this->buildAutomaticPublishingSummary();

    $form['publishing_overrides'] = [
      '#type' => 'details',
      '#title' => $this->t('Publishing overrides'),
      '#description' => $this->t('Use these only when the automatic publishing value shown above is unresolved or does not accurately represent the source. Overrides are stored on this candidate and are used by publishing preview and audit.'),
      '#open' => FALSE,
    ];

    $form['publishing_overrides']['override_day_of_week_tid'] = [
      '#type' => 'select',
      '#title' => $this->t('Day of week override'),
      '#options' => $this->taxonomyOptions('day_of_week'),
      '#empty_option' => $this->t('- Use automatic derivation shown above -'),
      '#default_value' => (int) ($this->candidate['override_day_of_week_tid'] ?? 0) ?: '',
      '#description' => $this->t('Leave this on automatic only when the Day of Week value shown above correctly represents the source. Otherwise select the correct existing taxonomy term.'),
    ];

    $form['publishing_overrides']['override_deal_category_tid'] = [
      '#type' => 'select',
      '#title' => $this->t('Deal category override'),
      '#options' => $this->taxonomyOptions('deal_category'),
      '#empty_option' => $this->t('- Use automatic derivation shown above -'),
      '#default_value' => (int) ($this->candidate['override_deal_category_tid'] ?? 0) ?: '',
      '#description' => $this->t('Leave this on automatic only when the Deal Category value shown above correctly represents the source. Otherwise select the correct existing taxonomy term.'),
    ];

    $form['decision'] = [
      '#type' => 'radios',
      '#title' => $this->t('Administrative decision'),
      '#required' => TRUE,
      '#default_value' => match ((string) $this->candidate['status']) {
        'approved' => 'approved',
        'rejected' => 'rejected',
        'pending' => 'pending',
        default => 'approved',
      },
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
   * Builds a read-only summary of the exact current automatic publishing plan.
   */
  private function buildAutomaticPublishingSummary(): array {
    $element = [
      '#type' => 'details',
      '#title' => $this->t('Automatic publishing values'),
      '#description' => $this->t('These are the exact values the current publishing preview derives from the saved candidate. If an override is left on automatic, the value shown here is what publishing will use. This preview does not write data.'),
      '#open' => TRUE,
    ];

    try {
      // Pending and rejected candidates are normally ineligible for publishing
      // preview. Temporarily mark this in-memory copy approved so an admin can
      // inspect the exact no-write field derivation before making a decision.
      $previewCandidate = $this->candidate;
      $previewCandidate['status'] = 'approved';
      $preview = $this->publishPreview->preview($previewCandidate);

      $deal = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
      $proposed = is_array($deal['proposed_fields'] ?? NULL)
        ? $deal['proposed_fields']
        : [];
      $blocking = is_array($deal['blocking_fields'] ?? NULL)
        ? $deal['blocking_fields']
        : [];
      $informational = is_array($deal['informational'] ?? NULL)
        ? $deal['informational']
        : [];

      $element['readiness'] = [
        '#type' => 'item',
        '#title' => $this->t('Publishing readiness'),
        '#markup' => !empty($preview['ready'])
          ? '<strong>' . $this->t('Ready based on the currently saved candidate values.') . '</strong>'
          : '<strong>' . $this->t('Not ready. Review the unresolved or blocking values below before approving.') . '</strong>',
      ];

      $qualityCorrections = is_array($informational['content_quality_corrections'] ?? NULL)
        ? $informational['content_quality_corrections']
        : [];
      if ($qualityCorrections !== []) {
        $correctionItems = [];
        foreach ($qualityCorrections as $field => $correction) {
          if (!is_array($correction)) {
            continue;
          }
          $correctionItems[] = htmlspecialchars((string) $field)
            . ': <del>' . htmlspecialchars((string) ($correction['from'] ?? '')) . '</del>'
            . ' &rarr; <strong>' . htmlspecialchars((string) ($correction['to'] ?? '')) . '</strong>';
        }
        if ($correctionItems !== []) {
          $element['content_normalization'] = [
            '#type' => 'item',
            '#title' => $this->t('Automatic content normalization'),
            '#markup' => '<p>' . $this->t('The following deterministic cleanup will be applied before publishing:') . '</p><ul><li>'
              . implode('</li><li>', $correctionItems)
              . '</li></ul>',
          ];
        }
      }

      $qualityWarnings = is_array($informational['content_quality_warnings'] ?? NULL)
        ? $informational['content_quality_warnings']
        : [];
      if ($qualityWarnings !== []) {
        $element['content_quality_warnings'] = [
          '#type' => 'item',
          '#title' => $this->t('Content quality review flags'),
          '#markup' => '<ul><li>' . implode('</li><li>', array_map(
            static fn (mixed $warning): string => htmlspecialchars((string) $warning),
            $qualityWarnings,
          )) . '</li></ul>',
        ];
      }

      $element['day_of_week'] = [
        '#type' => 'item',
        '#title' => $this->t('Day of Week automatic value'),
        '#markup' => $this->formatTaxonomyValues(
          $proposed['field_day_of_week'] ?? NULL,
          (string) ($blocking['field_day_of_week'] ?? ''),
        ),
      ];

      $element['deal_category'] = [
        '#type' => 'item',
        '#title' => $this->t('Deal Category automatic value'),
        '#markup' => $this->formatTaxonomyValues(
          $proposed['field_deal_category'] ?? NULL,
          (string) ($blocking['field_deal_category'] ?? ''),
        ),
      ];

      $startTime = trim((string) ($proposed['field_start_time'] ?? ''));
      $element['start_time'] = [
        '#type' => 'item',
        '#title' => $this->t('Start time automatic value'),
        '#markup' => $startTime !== ''
          ? '<strong>' . htmlspecialchars($startTime) . '</strong>'
          : '<strong>' . $this->t('No automatic start time derived.') . '</strong>',
      ];

      if (array_key_exists('field_recurring', $proposed)) {
        $recurring = $proposed['field_recurring'];
        $recurringLabel = $recurring === NULL
          ? (string) $this->t('Not derived / unset')
          : ((bool) $recurring ? (string) $this->t('Yes') : (string) $this->t('No'));

        $element['recurring'] = [
          '#type' => 'item',
          '#title' => $this->t('Recurring automatic value'),
          '#markup' => '<strong>' . htmlspecialchars($recurringLabel) . '</strong>',
        ];
      }

      $venue = is_array($preview['venue'] ?? NULL) ? $preview['venue'] : [];
      $venueAction = (string) ($venue['action'] ?? 'unresolved');
      $venueDescription = match ($venueAction) {
        'reuse_existing' => $this->t(
          'Reuse existing venue: @title (node @nid).',
          [
            '@title' => (string) ($venue['title'] ?? ''),
            '@nid' => (int) ($venue['nid'] ?? 0),
          ],
        ),
        'would_create' => $this->t(
          'Create a new venue from the validated Geoapify venue: @title.',
          ['@title' => (string) ($venue['title'] ?? '')],
        ),
        default => $this->t('Venue resolution is currently unresolved.'),
      };

      $element['venue_action'] = [
        '#type' => 'item',
        '#title' => $this->t('Venue publishing action'),
        '#markup' => '<strong>' . htmlspecialchars((string) $venueDescription) . '</strong>',
      ];

      $blockingMessages = [];
      foreach ($blocking as $message) {
        $message = trim((string) $message);
        if ($message !== '') {
          $blockingMessages[] = htmlspecialchars($message);
        }
      }
      foreach ((array) ($venue['errors'] ?? []) as $message) {
        $message = trim((string) $message);
        if ($message !== '') {
          $blockingMessages[] = htmlspecialchars($message);
        }
      }

      if (!empty($deal['duplicate_found'])) {
        $blockingMessages[] = htmlspecialchars((string) $this->t(
          'A duplicate deal was detected (node @nid).',
          ['@nid' => (int) ($deal['duplicate_nid'] ?? 0)],
        ));
      }

      if ($blockingMessages !== []) {
        $element['blockers'] = [
          '#type' => 'item',
          '#title' => $this->t('Current publishing blockers'),
          '#markup' => '<ul><li>' . implode('</li><li>', $blockingMessages) . '</li></ul>',
        ];
      }
    }
    catch (\Throwable $exception) {
      $element['preview_error'] = [
        '#type' => 'item',
        '#title' => $this->t('Publishing preview unavailable'),
        '#markup' => htmlspecialchars($this->t(
          'The automatic publishing values could not be calculated: @message',
          ['@message' => $exception->getMessage()],
        )),
      ];
    }

    return $element;
  }

  /**
   * Formats derived taxonomy values for the read-only publishing summary.
   */
  private function formatTaxonomyValues(mixed $value, string $blockingMessage): string {
    $values = [];

    if (is_array($value)) {
      if (isset($value['name'])) {
        $values[] = (string) $value['name'];
      }
      else {
        foreach ($value as $item) {
          if (is_array($item) && isset($item['name'])) {
            $values[] = (string) $item['name'];
          }
        }
      }
    }

    $values = array_values(array_unique(array_filter(array_map('trim', $values))));
    if ($values !== []) {
      return '<strong>' . htmlspecialchars(implode(', ', $values)) . '</strong>';
    }

    if ($blockingMessage !== '') {
      return '<strong>' . $this->t('UNRESOLVED') . '</strong><br>'
        . htmlspecialchars($blockingMessage);
    }

    return '<strong>' . $this->t('No automatic value derived; this does not currently block publishing.') . '</strong>';
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
