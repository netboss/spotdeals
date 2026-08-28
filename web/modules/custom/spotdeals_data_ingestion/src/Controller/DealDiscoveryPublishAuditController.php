<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPublishAuditService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Displays the deal publishing audit dashboard in Drupal administration.
 */
final class DealDiscoveryPublishAuditController extends ControllerBase {

  public function __construct(
    private readonly DealDiscoveryPublishAuditService $auditService,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_data_ingestion.deal_discovery_publish_audit'),
      $container->get('request_stack'),
    );
  }

  /**
   * Displays the read-only publishing dashboard.
   */
  public function audit(): array {
    $audit = $this->auditService->audit();
    $summary = $audit['summary'];

    $allowedResults = [
      'all',
      'ready',
      'blocked',
      'needs_review',
      'published',
      'auto_published',
      'duplicate',
      'venue_unresolved',
      'error',
    ];

    $requestedResult = (string) $this->requestStack
      ->getCurrentRequest()
      ?->query
      ->get('result', 'all');

    $resultFilter = in_array($requestedResult, $allowedResults, TRUE)
      ? $requestedResult
      : 'all';

    $summaryDefinitions = [
      'all' => [
        'label' => 'All candidates',
        'count' => (int) ($summary['total'] ?? 0),
      ],
      'ready' => [
        'label' => 'Ready',
        'count' => (int) ($summary['ready'] ?? 0),
      ],
      'blocked' => [
        'label' => 'Blocked',
        'count' => (int) ($summary['blocked'] ?? 0),
      ],
      'needs_review' => [
        'label' => 'Needs review',
        'count' => (int) ($summary['needs_review'] ?? 0),
      ],
      'published' => [
        'label' => 'Published manually',
        'count' => (int) ($summary['published'] ?? 0),
      ],
      'auto_published' => [
        'label' => 'Auto-published',
        'count' => (int) ($summary['auto_published'] ?? 0),
      ],
      'duplicate' => [
        'label' => 'Duplicate',
        'count' => (int) ($summary['duplicate'] ?? 0),
      ],
      'venue_unresolved' => [
        'label' => 'Venue unresolved',
        'count' => (int) ($summary['venue_unresolved'] ?? 0),
      ],
      'error' => [
        'label' => 'Errors',
        'count' => (int) ($summary['error'] ?? 0),
      ],
    ];

    $summaryLinks = [];
    foreach ($summaryDefinitions as $key => $definition) {
      $summaryLinks[$key] = [
        'title' => $this->t(
          '@label (@count)',
          [
            '@label' => $definition['label'],
            '@count' => $definition['count'],
          ],
        ),
        'url' => Url::fromRoute(
          'spotdeals_data_ingestion.deal_discovery_publish_audit',
          [],
          $key === 'all'
            ? []
            : ['query' => ['result' => $key]],
        ),
        'attributes' => [
          'class' => $key === $resultFilter ? ['is-active'] : [],
        ],
      ];
    }

    $rows = [];
    foreach ($audit['rows'] as $row) {
      $result = (string) ($row['result'] ?? '');
      if ($resultFilter !== 'all' && $result !== $resultFilter) {
        continue;
      }

      $candidate = is_array($row['candidate'] ?? NULL)
        ? $row['candidate']
        : [];
      $candidateId = (int) ($candidate['id'] ?? 0);
      if ($candidateId <= 0) {
        continue;
      }

      $rows[] = [
        'data' => [
          'id' => $candidateId,
          'venue' => (string) ($candidate['venue_name'] ?? ''),
          'offer' => (string) ($candidate['offer_title'] ?? ''),
          'status' => $this->humanize((string) ($candidate['status'] ?? '')),
          'audit_result' => $this->humanize($result),
          'reason' => (string) ($row['message'] ?? ''),
          'operations' => [
            'data' => $this->buildOperations(
              $candidate,
              $result,
            ),
          ],
        ],
      ];
    }

    $visibleCount = count($rows);

    return [
      'notice' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['messages', 'messages--status'],
        ],
        'content' => [
          '#markup' => '<p><strong>'
            . $this->t('Publishing dashboard.')
            . '</strong> '
            . $this->t(
              'This audit is read-only. It evaluates candidates using the same publishing-readiness logic used by Preview publish. Use the actions below to review, resolve, preview, or publish candidates.',
            )
            . '</p>',
        ],
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['container-inline']],
        'discovery' => [
          '#type' => 'link',
          '#title' => $this->t('Run deal discovery'),
          '#url' => Url::fromRoute(
            'spotdeals_data_ingestion.deal_discovery_run',
          ),
          '#attributes' => [
            'class' => ['button', 'button--primary'],
          ],
          '#access' => $this->currentUser()
            ->hasPermission('run spotdeals deal discovery'),
        ],
        'publish_ready' => [
          '#type' => 'link',
          '#title' => $this->t('Publish ready reviewed candidates'),
          '#url' => Url::fromRoute(
            'spotdeals_data_ingestion.deal_discovery_publish_ready',
          ),
          '#attributes' => [
            'class' => ['button'],
          ],
          '#access' => $this->currentUser()
            ->hasPermission('publish spotdeals deal discovery'),
          '#prefix' => ' ',
        ],
        'queue' => [
          '#type' => 'link',
          '#title' => $this->t('Back to deal discovery'),
          '#url' => Url::fromRoute(
            'spotdeals_data_ingestion.deal_discovery_candidates',
          ),
          '#attributes' => [
            'class' => ['button'],
          ],
        ],
      ],
      'summary' => [
        '#type' => 'details',
        '#title' => $this->t(
          'Publishing status: @ready ready, @blocked blocked, @published published',
          [
            '@ready' => (int) ($summary['ready'] ?? 0),
            '@blocked' => (int) ($summary['blocked'] ?? 0),
            '@published' => (int) ($summary['published'] ?? 0) + (int) ($summary['auto_published'] ?? 0),
          ],
        ),
        '#open' => TRUE,
        'links' => [
          '#theme' => 'links',
          '#links' => $summaryLinks,
          '#attributes' => [
            'class' => ['links', 'inline'],
          ],
        ],
      ],
      'current_filter' => [
        '#markup' => '<p><strong>'
          . $this->t('Showing:')
          . '</strong> '
          . $this->t(
            '@label — @count candidate(s)',
            [
              '@label' => $summaryDefinitions[$resultFilter]['label'],
              '@count' => $visibleCount,
            ],
          )
          . '</p>',
      ],
      'results' => [
        '#type' => 'table',
        '#caption' => $this->t('Candidate publishing audit'),
        '#header' => [
          $this->t('ID'),
          $this->t('Venue'),
          $this->t('Offer'),
          $this->t('Candidate status'),
          $this->t('Audit result'),
          $this->t('Reason / next step'),
          $this->t('Actions'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t(
          'No candidates match this publishing-audit filter.',
        ),
      ],
    ];
  }

  /**
   * Builds context-sensitive actions for one audited candidate.
   */
  private function buildOperations(
    array $candidate,
    string $result,
  ): array {
    $candidateId = (int) ($candidate['id'] ?? 0);
    $status = (string) ($candidate['status'] ?? '');

    $operations = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];

    if (in_array($result, ['published', 'auto_published'], TRUE)) {
      $operations['published_result'] = [
        '#type' => 'link',
        '#title' => $this->t('Published result'),
        '#url' => Url::fromRoute(
          'spotdeals_data_ingestion.deal_discovery_publish_preview',
          ['candidate_id' => $candidateId],
        ),
      ];

      $dealNid = (int) ($candidate['published_deal_nid'] ?? 0);
      if ($dealNid > 0) {
        $operations['deal'] = [
          '#type' => 'link',
          '#title' => $this->t('Open deal'),
          '#url' => Url::fromRoute(
            'entity.node.canonical',
            ['node' => $dealNid],
          ),
          '#prefix' => ' | ',
        ];
      }

      $venueNid = (int) ($candidate['published_venue_nid'] ?? 0);
      if ($venueNid > 0) {
        $operations['venue'] = [
          '#type' => 'link',
          '#title' => $this->t('Open venue'),
          '#url' => Url::fromRoute(
            'entity.node.canonical',
            ['node' => $venueNid],
          ),
          '#prefix' => ' | ',
        ];
      }

      return $operations;
    }

    if ($result === 'needs_review') {
      $operations['review'] = [
        '#type' => 'link',
        '#title' => $this->t('Review candidate'),
        '#url' => Url::fromRoute(
          'spotdeals_data_ingestion.deal_discovery_candidate_review',
          ['candidate_id' => $candidateId],
        ),
      ];

      return $operations;
    }

    if (in_array(
      $result,
      ['blocked', 'duplicate', 'venue_unresolved', 'error'],
      TRUE,
    )) {
      $operations['resolve'] = [
        '#type' => 'link',
        '#title' => $result === 'blocked'
          ? $this->t('Resolve blocker')
          : $this->t('Inspect preview'),
        '#url' => Url::fromRoute(
          'spotdeals_data_ingestion.deal_discovery_publish_preview',
          ['candidate_id' => $candidateId],
        ),
      ];
      $operations['review'] = [
        '#type' => 'link',
        '#title' => $this->t('Review candidate'),
        '#url' => Url::fromRoute(
          'spotdeals_data_ingestion.deal_discovery_candidate_review',
          ['candidate_id' => $candidateId],
        ),
        '#prefix' => ' | ',
      ];

      return $operations;
    }

    if (
      $result === 'ready'
      && in_array($status, ['approved', 'auto_approved'], TRUE)
    ) {
      $operations['preview'] = [
        '#type' => 'link',
        '#title' => $this->t('Preview publish'),
        '#url' => Url::fromRoute(
          'spotdeals_data_ingestion.deal_discovery_publish_preview',
          ['candidate_id' => $candidateId],
        ),
      ];

      if ($this->currentUser()->hasPermission(
        'publish spotdeals deal discovery',
      )) {
        $operations['publish'] = [
          '#type' => 'link',
          '#title' => $this->t('Publish'),
          '#url' => Url::fromRoute(
            'spotdeals_data_ingestion.deal_discovery_publish',
            ['candidate_id' => $candidateId],
          ),
          '#prefix' => ' | ',
        ];
      }

      return $operations;
    }

    $operations['review'] = [
      '#type' => 'link',
      '#title' => $this->t('Review candidate'),
      '#url' => Url::fromRoute(
        'spotdeals_data_ingestion.deal_discovery_candidate_review',
        ['candidate_id' => $candidateId],
      ),
    ];

    return $operations;
  }

  private function humanize(string $value): string {
    return ucfirst(str_replace('_', ' ', $value));
  }

}
