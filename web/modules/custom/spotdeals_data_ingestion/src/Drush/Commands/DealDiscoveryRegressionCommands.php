<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryFieldDeriver;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Regression commands for deal-discovery publishing field derivation.
 */
final class DealDiscoveryRegressionCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly DealDiscoveryFieldDeriver $fieldDeriver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Runs the shadow-publishing field-derivation regression matrix.
   */
  #[CLI\Command(
    name: 'spotdeals:deal-publish-regression',
    aliases: ['sd:deal-publish-regression'],
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-publish-regression',
    description: 'Runs read-only regression checks for discovered deal field derivation.',
  )]
  public function regression(): int {
    $this->io()->title('SpotDeals Deal Publishing — Regression Matrix');

    $dayTerms = $this->loadVocabularyTerms('day_of_week');
    $categoryTerms = $this->loadVocabularyTerms('deal_category');

    if ($dayTerms === []) {
      $this->io()->error('The day_of_week taxonomy has no terms.');
      return 1;
    }

    if ($categoryTerms === []) {
      $this->io()->error('The deal_category taxonomy has no terms.');
      return 1;
    }

    $cases = $this->cases();
    $rows = [];
    $failed = 0;

    foreach ($cases as $name => $case) {
      $schedule = $case['schedule'];
      $categoryContext = $case['category_context'];

      $days = $this->fieldDeriver->deriveTaxonomyScheduleTerms(
        $schedule,
        $dayTerms,
      );
      $dayNames = array_values(array_map(
        static fn (array $term): string => (string) $term['name'],
        $days,
      ));

      $startTime = $this->fieldDeriver->deriveStartTime($schedule);
      $recurring = $this->fieldDeriver->deriveRecurring($schedule);
      $category = $categoryContext !== ''
        ? $this->fieldDeriver->deriveExactTaxonomyTerm(
          $categoryContext,
          $categoryTerms,
        )
        : NULL;

      $actual = [
        'days' => $dayNames,
        'start_time' => $startTime,
        'recurring' => $recurring,
        'category' => $category['name'] ?? NULL,
      ];

      $errors = $this->compare($case['expected'], $actual);
      $passed = $errors === [];
      if (!$passed) {
        $failed++;
      }

      $rows[] = [
        $name,
        $passed ? 'PASS' : 'FAIL',
        $this->display($actual['days']),
        $actual['start_time'] !== '' ? $actual['start_time'] : '—',
        $actual['recurring'] === NULL ? '—' : (string) $actual['recurring'],
        $actual['category'] ?? '—',
        $passed ? '' : implode('; ', $errors),
      ];
    }

    $this->io()->table(
      [
        'Case',
        'Result',
        'Day of week',
        'Start time',
        'Recurring',
        'Category',
        'Failure',
      ],
      $rows,
    );

    if ($failed > 0) {
      $this->io()->error(sprintf(
        '%d of %d regression cases failed. No data was written.',
        $failed,
        count($cases),
      ));
      return 1;
    }

    $this->io()->success(sprintf(
      'All %d regression cases passed. No data was written.',
      count($cases),
    ));

    return 0;
  }

  /**
   * Returns the regression matrix built from manually validated candidates.
   *
   * @return array<string, array{
   *   schedule: string,
   *   category_context: string,
   *   expected: array{
   *     days: array<int, string>,
   *     start_time: string,
   *     recurring: ?int,
   *     category: ?string
   *   }
   * }>
   */
  private function cases(): array {
    return [
      'NIGHTOWL' => [
        'schedule' => 'Get $5 off per player at 10 pm or later Sunday through Friday.',
        'category_context' => 'NIGHTOWL $5 off promotion Get $5 off per player at 10 pm or later Sunday through Friday.',
        'expected' => [
          'days' => ['Sunday, Monday, Tuesday, Wednesday, Thursday, Friday'],
          'start_time' => '10 pm',
          'recurring' => NULL,
          'category' => 'Promotion',
        ],
      ],
      'EARLYBIRD' => [
        'schedule' => 'This offer is valid for missions before noon Sunday through Friday.',
        'category_context' => 'EARLYBIRD SAVE 15% promotion This offer is valid for missions before noon Sunday through Friday.',
        'expected' => [
          'days' => ['Sunday, Monday, Tuesday, Wednesday, Thursday, Friday'],
          'start_time' => '',
          'recurring' => NULL,
          'category' => 'Promotion',
        ],
      ],
      'Access for All at OMA' => [
        'schedule' => 'On the third Thursday of every month, enjoy extended hours from 10 am to 8 pm.',
        'category_context' => 'Access for All at OMA Free admission promotion On the third Thursday of every month, enjoy extended hours from 10 am to 8 pm.',
        'expected' => [
          'days' => ['Thursday'],
          'start_time' => '10 am',
          'recurring' => 1,
          'category' => 'Promotion',
        ],
      ],
      'Opportunities for Free Admission' => [
        'schedule' => 'Complimentary admission on the first full weekend of each month.',
        'category_context' => 'Opportunities for Free Admission Free admission promotion Complimentary admission on the first full weekend of each month.',
        'expected' => [
          'days' => ['Weekends'],
          'start_time' => '',
          'recurring' => 1,
          'category' => 'Promotion',
        ],
      ],
      'Renaissance group discount' => [
        'schedule' => '',
        'category_context' => 'Group discount: take 20% off when you buy 8 or more tickets.',
        'expected' => [
          'days' => [],
          'start_time' => '',
          'recurring' => NULL,
          'category' => 'Group Discount',
        ],
      ],
      'Reject reversed/extra range semantics' => [
        'schedule' => 'Valid Sunday through Friday.',
        'category_context' => '',
        'expected' => [
          'days' => ['Sunday, Monday, Tuesday, Wednesday, Thursday, Friday'],
          'start_time' => '',
          'recurring' => NULL,
          'category' => NULL,
        ],
      ],
    ];
  }

  /**
   * Compares one expected and actual derivation result.
   *
   * @return array<int, string>
   */
  private function compare(array $expected, array $actual): array {
    $errors = [];

    foreach (['days', 'start_time', 'recurring', 'category'] as $key) {
      if ($expected[$key] === $actual[$key]) {
        continue;
      }

      $errors[] = sprintf(
        '%s expected %s, got %s',
        $key,
        $this->display($expected[$key]),
        $this->display($actual[$key]),
      );
    }

    return $errors;
  }

  /**
   * Loads taxonomy terms in their configured order.
   *
   * @return array<int, array{tid: int, name: string, weight: int}>
   */
  private function loadVocabularyTerms(string $vocabulary): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabulary)
      ->sort('weight', 'ASC')
      ->sort('tid', 'ASC')
      ->execute();

    if ($ids === []) {
      return [];
    }

    $terms = [];
    foreach ($storage->loadMultiple($ids) as $term) {
      $terms[] = [
        'tid' => (int) $term->id(),
        'name' => (string) $term->label(),
        'weight' => (int) $term->getWeight(),
      ];
    }

    return $terms;
  }

  private function display(mixed $value): string {
    if ($value === NULL || $value === '') {
      return '—';
    }

    if (is_array($value)) {
      if ($value === []) {
        return '[]';
      }

      return implode(' | ', array_map(
        static fn (mixed $item): string => is_scalar($item)
          ? (string) $item
          : (json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '?'),
        $value,
      ));
    }

    return (string) $value;
  }

}
