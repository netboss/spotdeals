<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\spotdeals_data_ingestion\Form\DealDiscoveryPublishingOverrideForm;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPublishPreviewService;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Displays a no-write publishing preview for one deal candidate.
 */
final class DealDiscoveryPublishPreviewController extends ControllerBase {

  public function __construct(
    private readonly DealDiscoveryStorage $storage,
    private readonly DealDiscoveryPublishPreviewService $previewService,
    private readonly FormBuilderInterface $dealDiscoveryFormBuilder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_data_ingestion.deal_discovery_storage'),
      $container->get('spotdeals_data_ingestion.deal_discovery_publish_preview'),
      $container->get('form_builder'),
    );
  }

  public function preview(int $candidate_id): array {
    $candidate = $this->storage->load($candidate_id);
    if ($candidate === NULL) {
      throw new NotFoundHttpException();
    }

    $publishedDealNid = (int) ($candidate['published_deal_nid'] ?? 0);
    $publishedVenueNid = (int) ($candidate['published_venue_nid'] ?? 0);

    if ($publishedDealNid > 0 || (string) ($candidate['status'] ?? '') === 'published') {
      $items = [
        $this->t('Candidate status: Published'),
      ];

      if ($publishedVenueNid > 0) {
        $items[] = Link::fromTextAndUrl(
          $this->t('Open published venue (NID @nid)', ['@nid' => $publishedVenueNid]),
          Url::fromRoute('entity.node.canonical', ['node' => $publishedVenueNid]),
        )->toString();
      }

      if ($publishedDealNid > 0) {
        $items[] = Link::fromTextAndUrl(
          $this->t('Open published deal (NID @nid)', ['@nid' => $publishedDealNid]),
          Url::fromRoute('entity.node.canonical', ['node' => $publishedDealNid]),
        )->toString();
      }

      return [
        'notice' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['messages', 'messages--status']],
          'content' => [
            '#markup' => '<p><strong>' . $this->t('Published.') . '</strong> '
              . $this->t('This candidate has already been written to Drupal. Publishing it again will not create another deal.')
              . '</p>',
          ],
        ],
        'published' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
        'back' => Link::fromTextAndUrl(
          $this->t('Back to deal discovery'),
          Url::fromRoute('spotdeals_data_ingestion.deal_discovery_candidates'),
        )->toRenderable(),
      ];
    }

    $preview = $this->previewService->preview($candidate);

    if (isset($preview['errors'])) {
      return [
        '#type' => 'container',
        'message' => [
          '#markup' => '<p>' . htmlspecialchars(implode(' ', $preview['errors'])) . '</p>',
        ],
      ];
    }

    $venue = $preview['venue'];
    $deal = $preview['deal'];

    $venueRows = [
      [$this->t('Action'), str_replace('_', ' ', (string) $venue['action'])],
      [$this->t('Venue'), (string) $venue['title']],
      [$this->t('Existing venue NID'), $venue['nid'] ? (string) $venue['nid'] : '—'],
      [$this->t('Match method'), (string) ($venue['match_method'] ?: '—')],
    ];

    foreach ($venue['errors'] as $error) {
      $venueRows[] = [$this->t('Venue warning'), (string) $error];
    }

    $dealRows = [];
    foreach ($deal['proposed_fields'] as $field => $value) {
      $dealRows[] = [$field, $this->displayValue($value), $this->t('Proposed')];
    }
    foreach ($deal['blocking_fields'] as $field => $message) {
      $suggestion = is_array($deal['blocking_suggestions'][$field] ?? NULL)
        ? $deal['blocking_suggestions'][$field]
        : NULL;

      $value = (string) $message;
      if ($suggestion !== NULL) {
        $value .= ' Suggested resolution: '
          . (string) ($suggestion['name'] ?? '')
          . ' ('
          . strtoupper((string) ($suggestion['confidence'] ?? ''))
          . ' confidence; used by '
          . (int) ($suggestion['usage_count'] ?? 0)
          . ' current deal nodes).';
      }

      $dealRows[] = [$field, $value, $this->t('BLOCKING')];
    }
    foreach ($deal['informational'] as $field => $value) {
      $dealRows[] = [$field, $this->displayValue($value), $this->t('Context')];
    }

    if ($deal['duplicate_found']) {
      $dealRows[] = [
        'duplicate',
        $this->t('Existing matching deal NID @nid', ['@nid' => $deal['duplicate_nid']]),
        $this->t('BLOCKING'),
      ];
    }

    $resolution = [];
    if (!$preview['ready']) {
      $resolution['intro'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'content' => [
          '#markup' => '<p><strong>' . $this->t('Action required.') . '</strong> '
            . $this->t(
              'This candidate is blocked. Resolve supported taxonomy blockers below, or edit the candidate review for other missing/incorrect source data.',
            )
            . '</p>',
        ],
      ];

      if (
        isset($deal['blocking_fields']['field_day_of_week'])
        || isset($deal['blocking_fields']['field_deal_category'])
      ) {
        $resolution['override_form'] = $this->dealDiscoveryFormBuilder->getForm(
          DealDiscoveryPublishingOverrideForm::class,
          $candidate_id,
          $deal['blocking_fields'],
          is_array($deal['blocking_suggestions'] ?? NULL)
            ? $deal['blocking_suggestions']
            : [],
        );
      }

      $resolution['edit_candidate'] = [
        '#type' => 'link',
        '#title' => $this->t('Edit candidate / resolve other blockers'),
        '#url' => Url::fromRoute(
          'spotdeals_data_ingestion.deal_discovery_candidate_review',
          ['candidate_id' => $candidate_id],
        ),
        '#attributes' => [
          'class' => ['button'],
        ],
      ];
    }

    $publishAction = [];
    if (
      !empty($preview['ready'])
      && $this->currentUser()->hasPermission('publish spotdeals deal discovery')
    ) {
      $publishAction = [
        '#type' => 'link',
        '#title' => $this->t('Publish to SpotDeals'),
        '#url' => Url::fromRoute(
          'spotdeals_data_ingestion.deal_discovery_publish',
          ['candidate_id' => $candidate_id],
        ),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ];
    }

    return [
      'notice' => [
        '#type' => 'container',
        'text' => [
          '#markup' => '<p><strong>' . $this->t('Shadow publishing preview.') . '</strong> '
            . $this->t('Simply viewing this page makes no writes. Explicit blocker-resolution actions may save candidate overrides or create an administrator-requested taxonomy term, and Publish to SpotDeals performs the controlled Drupal content write after confirmation.')
            . '</p>',
        ],
      ],
      'summary' => [
        '#type' => 'item',
        '#title' => $this->t('Publishing readiness'),
        '#markup' => $preview['ready']
          ? $this->t('Ready for a future publishing implementation.')
          : $this->t('Not ready: one or more required values or duplicate checks block publishing.'),
      ],
      'venue' => [
        '#type' => 'table',
        '#caption' => $this->t('Venue plan'),
        '#header' => [$this->t('Item'), $this->t('Value')],
        '#rows' => $venueRows,
      ],
      'deal' => [
        '#type' => 'table',
        '#caption' => $this->t('Deal node plan'),
        '#header' => [$this->t('Field / item'), $this->t('Value'), $this->t('State')],
        '#rows' => $dealRows,
      ],
      'publish_action' => $publishAction,
      'resolution' => $resolution,
      'back' => Link::fromTextAndUrl(
        $this->t('Back to deal discovery'),
        Url::fromRoute('spotdeals_data_ingestion.deal_discovery_candidates'),
      )->toRenderable(),
    ];
  }

  private function displayValue(mixed $value): string {
    if ($value === NULL || $value === '') {
      return '—';
    }

    if (is_scalar($value)) {
      return (string) $value;
    }

    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—';
  }

}
