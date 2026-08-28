<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Administrative deal-discovery review queue.
 */
final class DealDiscoveryAdminController extends ControllerBase {

  public function __construct(
    private readonly DealDiscoveryStorage $storage,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_data_ingestion.deal_discovery_storage'),
      $container->get('date.formatter'),
    );
  }

  public function listing(Request $request): array {
    $status = (string) $request->query->get('status', 'pending');
    $allowed = ['pending', 'auto_approved', 'approved', 'published', 'rejected', 'all'];
    if (!in_array($status, $allowed, TRUE)) {
      $status = 'pending';
    }

    $filterLinks = [];
    foreach ($allowed as $filter) {
      $filterLinks[$filter] = [
        'title' => $filter === 'auto_approved' ? $this->t('Auto-approved') : ucfirst($filter),
        'url' => Url::fromRoute(
          'spotdeals_data_ingestion.deal_discovery_candidates',
          [],
          ['query' => ['status' => $filter]],
        ),
      ];
    }

    $rows = [];
    foreach ($this->storage->list($status) as $candidate) {
      $sourceUrl = trim((string) $candidate['source_url']);
      $source = $sourceUrl !== ''
        ? Link::fromTextAndUrl($this->t('Source'), Url::fromUri($sourceUrl, ['attributes' => ['target' => '_blank', 'rel' => 'noopener noreferrer']]))->toRenderable()
        : ['#markup' => '—'];

      $rows[] = [
        $candidate['id'],
        [
          'data' => [
            '#markup' => '<strong>' . htmlspecialchars((string) $candidate['venue_name']) . '</strong><br><small>' . htmlspecialchars((string) $candidate['venue_address']) . '</small>',
          ],
        ],
        [
          'data' => [
            '#markup' => '<strong>' . htmlspecialchars((string) $candidate['offer_title']) . '</strong><br><small>' . htmlspecialchars((string) $candidate['offer_value']) . '</small>',
          ],
        ],
        htmlspecialchars((string) $candidate['schedule']),
        (string) $candidate['score'],
        htmlspecialchars((string) $candidate['confidence']),
        str_replace('_', ' ', (string) $candidate['status']),
        $this->dateFormatter->format((int) $candidate['last_seen'], 'short'),
        ['data' => $source],
        [
          'data' => [
            '#type' => 'container',
            'review' => [
              '#type' => 'link',
              '#title' => $this->t('Review'),
              '#url' => Url::fromRoute(
                'spotdeals_data_ingestion.deal_discovery_candidate_review',
                ['candidate_id' => $candidate['id']],
              ),
            ],
            'preview' => in_array($candidate['status'], ['approved', 'auto_approved', 'published'], TRUE)
              ? [
                '#type' => 'link',
                '#title' => $candidate['status'] === 'published'
                  ? $this->t('Published result')
                  : $this->t('Preview publish'),
                '#url' => Url::fromRoute(
                  'spotdeals_data_ingestion.deal_discovery_publish_preview',
                  ['candidate_id' => $candidate['id']],
                ),
                '#prefix' => ' | ',
              ]
              : [],
          ],
        ],
      ];
    }

    return [
      'intro' => [
        '#type' => 'container',
        'text' => [
          '#markup' => '<p>' . $this->t('Deal candidates discovered from official venue websites. High-confidence candidates may be auto-approved and, when automatic publishing is enabled, safely published through the same readiness contract used by Preview publish. The queue is primarily for exceptions and manual review.') . '</p>',
        ],
        'run' => [
          '#type' => 'link',
          '#title' => $this->t('Run deal discovery'),
          '#url' => Url::fromRoute('spotdeals_data_ingestion.deal_discovery_run'),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
        'audit' => [
          '#type' => 'link',
          '#title' => $this->t('Publishing dashboard'),
          '#url' => Url::fromRoute('spotdeals_data_ingestion.deal_discovery_publish_audit'),
          '#attributes' => ['class' => ['button']],
          '#prefix' => ' ',
        ],
      ],
      'filters' => [
        '#type' => 'container',
        'links' => [
          '#theme' => 'links',
          '#links' => $filterLinks,
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('ID'),
          $this->t('Venue'),
          $this->t('Offer'),
          $this->t('Schedule / validity'),
          $this->t('Score'),
          $this->t('Confidence'),
          $this->t('Status'),
          $this->t('Last seen'),
          $this->t('Evidence'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No deal-discovery candidates found for this status.'),
      ],
    ];
  }

}
