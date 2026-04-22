<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote_deal;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Builds the deal vote render array.
 */
final class DealVoteRenderBuilder {

  /**
   * Constructs the render builder.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly DealVoteManager $voteManager,
  ) {}

  /**
   * Builds deal voting markup.
   *
   * @return array<string,mixed>
   *   Render array.
   */
  public function build(NodeInterface $deal, ?NodeInterface $venue = NULL, bool $compact = FALSE): array {
    if ($deal->bundle() !== 'deal') {
      return [];
    }

    $venueNid = $venue instanceof NodeInterface ? (int) $venue->id() : 0;
    if ($venueNid <= 0) {
      return [];
    }

    $voteState = $this->voteManager->getDealVoteState((int) $deal->id(), (int) $this->currentUser->id());

    $classes = ['spotdeals-vote'];
    $classes[] = $compact ? 'spotdeals-vote--compact' : 'spotdeals-vote--full';

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['views-field', 'views-field-spotdeals-vote'],
      ],
      '#attached' => [
        'library' => [
          'spotdeals_vote/vote',
        ],
      ],
      'content' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['field-content'],
        ],
        'vote' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => $classes,
            'data-spotdeals-vote' => '1',
            'data-vote-scope' => 'deal:' . $deal->id(),
            'data-vote-endpoint' => Url::fromRoute('spotdeals_vote_deal.submit')->toString(),
            'data-vote-source' => 'recommendation_card',
            'data-deal-nid' => (string) $deal->id(),
            'data-venue-nid' => (string) $venueNid,
            'data-authenticated' => $this->currentUser->isAuthenticated() ? '1' : '0',
            'data-login-url' => Url::fromRoute('user.login', [], ['query' => ['destination' => $deal->toUrl()->toString()]])->toString(),
            'data-current-worth-it' => isset($voteState['user_vote']['worth_it']) && $voteState['user_vote']['worth_it'] !== NULL ? (string) $voteState['user_vote']['worth_it'] : '',
            'data-current-would-go-again' => isset($voteState['user_vote']['would_go_again']) && $voteState['user_vote']['would_go_again'] !== NULL ? (string) $voteState['user_vote']['would_go_again'] : '',
          ],
        ],
      ],
    ];

    $build['content']['vote']['worth_it_group'] = $this->buildVoteGroup(
      'worth_it',
      (int) $deal->id(),
      $venueNid,
      $voteState,
      'Was it worth it?',
      'Worth it?',
    );

    if (!$compact) {
      $build['content']['vote']['would_go_again_group'] = $this->buildVoteGroup(
        'would_go_again',
        (int) $deal->id(),
        $venueNid,
        $voteState,
        'Would you go back?',
        'Go back?',
      );
    }

    $build['content']['vote']['message'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['spotdeals-vote__message'],
        'aria-live' => 'polite',
      ],
      '#value' => '',
    ];

    return $build;
  }

  /**
   * Builds a yes/no vote group.
   *
   * @param array<string,mixed> $voteState
   *   Vote state data.
   *
   * @return array<string,mixed>
   *   Render array.
   */
  private function buildVoteGroup(string $fieldName, int $dealNid, int $venueNid, array $voteState, string $label, ?string $mobileLabel = NULL): array {
    $currentValue = $voteState['user_vote'][$fieldName] ?? NULL;
    $aggregate = $voteState['aggregate'] ?? [];

    if ($fieldName === 'worth_it') {
      $yesCount = (int) ($aggregate['worth_it_yes'] ?? 0);
      $noCount = (int) ($aggregate['worth_it_no'] ?? 0);
    }
    else {
      $yesCount = (int) ($aggregate['would_go_again_yes'] ?? 0);
      $noCount = (int) ($aggregate['would_go_again_no'] ?? 0);
    }

    $totalCount = $yesCount + $noCount;
    $metric = $this->buildMetricParts($yesCount, $totalCount);

    $mobileLabel = $mobileLabel !== NULL && $mobileLabel !== ''
      ? $mobileLabel
      : $label;

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['spotdeals-vote__group'],
        'data-vote-group' => $fieldName,
      ],
    ];

    $build['label'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['spotdeals-vote__label'],
      ],
      'desktop' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'class' => ['spotdeals-vote__label-text', 'spotdeals-vote__label-text--desktop'],
        ],
        '#value' => $label,
      ],
      'mobile' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'class' => ['spotdeals-vote__label-text', 'spotdeals-vote__label-text--mobile'],
        ],
        '#value' => $mobileLabel,
      ],
    ];

    $build['buttons'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['spotdeals-vote__buttons'],
        'role' => 'group',
        'aria-label' => $label,
      ],
    ];

    foreach ([1 => t('Yes'), 0 => t('No')] as $value => $buttonLabel) {
      $classes = ['spotdeals-vote__button'];
      if ($currentValue !== NULL && (int) $currentValue === (int) $value) {
        $classes[] = 'is-selected';
      }

      $build['buttons']['button_' . $value] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => $classes,
          'data-vote-field' => $fieldName,
          'data-vote-value' => (string) $value,
          'data-deal-nid' => (string) $dealNid,
          'data-venue-nid' => (string) $venueNid,
          'aria-pressed' => ($currentValue !== NULL && (int) $currentValue === (int) $value) ? 'true' : 'false',
        ],
        '#value' => (string) $buttonLabel,
      ];
    }

    $build['count'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['spotdeals-vote__group-count'],
        'data-vote-group-count' => $fieldName,
      ],
    ];

    if ($metric['has_votes']) {
      $build['count']['count'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'class' => ['spotdeals-vote__count'],
        ],
        '#value' => $metric['count_label'],
      ];
      $build['count']['text'] = [
        '#markup' => ' ' . $metric['percent_label'],
      ];
    }
    else {
      $build['count']['text'] = [
        '#markup' => $metric['percent_label'],
      ];
    }

    return $build;
  }

  /**
   * Builds metric parts for a vote group.
   *
   * @return array{has_votes: bool, count_label: string, percent_label: string}
   *   Metric parts.
   */
  private function buildMetricParts(int $yesCount, int $totalCount): array {
    if ($totalCount <= 0) {
      return [
        'has_votes' => FALSE,
        'count_label' => '',
        'percent_label' => (string) t('No votes'),
      ];
    }

    return [
      'has_votes' => TRUE,
      'count_label' => '(' . $this->formatCompactCount($totalCount) . ')',
      'percent_label' => (string) t('@percent% 👍', [
        '@percent' => (string) round(($yesCount / $totalCount) * 100),
      ]),
    ];
  }

  /**
   * Formats counts compactly to prevent layout overflow.
   */
  private function formatCompactCount(int $count): string {
    if ($count < 1000) {
      return (string) $count;
    }

    if ($count < 10000) {
      $formatted = number_format($count / 1000, 1);
      return rtrim(rtrim($formatted, '0'), '.') . 'K';
    }

    if ($count < 1000000) {
      return (string) round($count / 1000) . 'K';
    }

    $formatted = number_format($count / 1000000, 1);
    return rtrim(rtrim($formatted, '0'), '.') . 'M';
  }

}
