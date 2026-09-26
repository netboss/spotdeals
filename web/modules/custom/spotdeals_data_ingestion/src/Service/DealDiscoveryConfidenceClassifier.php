<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Classifies discovered deals for automatic or manual administrative routing.
 */
final class DealDiscoveryConfidenceClassifier {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DealDiscoveryContentQualityService $contentQuality,
    private readonly TimeInterface $time,
  ) {}

  /**
   * @return array{status: string, confidence: string, reasons: array<int, string>}
   */
  public function classify(array $candidate, int $locationConfidence): array {
    $config = $this->configFactory->get('spotdeals_data_ingestion.settings');
    $configuredScore = $config->get('deal_discovery_auto_approve_score');
    $configuredLocationConfidence = $config->get('deal_discovery_auto_approve_location_confidence');
    $configuredRequireSchedule = $config->get('deal_discovery_auto_approve_require_schedule');

    if (
      $configuredScore === NULL
      || $configuredLocationConfidence === NULL
      || $configuredRequireSchedule === NULL
    ) {
      return [
        'status' => 'pending',
        'confidence' => 'review',
        'reasons' => [
          'automatic-approval configuration is incomplete',
        ],
      ];
    }

    $autoApproveScore = (int) $configuredScore;
    $minimumLocationConfidence = (int) $configuredLocationConfidence;
    $requireSchedule = (bool) $configuredRequireSchedule;

    $score = (int) ($candidate['score'] ?? 0);
    $schedule = trim((string) ($candidate['schedule'] ?? ''));
    $sourceUrl = trim((string) ($candidate['source_url'] ?? ''));
    $value = trim((string) ($candidate['value'] ?? ''));
    $title = trim((string) ($candidate['title'] ?? ''));
    $quality = $this->contentQuality->assessCandidate($candidate);
    $validityIssue = $this->detectTemporalValidityIssue($candidate);

    // A current/regular price comparison with identical prices is positive
    // evidence that no discount exists. Route these obvious extraction false
    // positives out of the manual-review queue without weakening any of the
    // automatic-approval safeguards below.
    if ($this->hasIdenticalComparisonPrices($value)) {
      return [
        'status' => 'rejected',
        'confidence' => 'low',
        'reasons' => [
          'candidate is not a deal: current and regular comparison prices are identical',
        ],
      ];
    }

    // An explicit validity end date that is already in the past is equally
    // terminal: the promotion may have been legitimate, but it is no longer a
    // current deal and should not remain in the human-review queue.
    if (
      $validityIssue !== NULL
      && str_starts_with($validityIssue, 'explicit validity ended on ')
    ) {
      return [
        'status' => 'rejected',
        'confidence' => 'low',
        'reasons' => [
          'candidate is expired: ' . $validityIssue,
        ],
      ];
    }

    // Strong explicit-offer evidence may safely substitute for the legacy
    // score/schedule gates. It never bypasses location, source, required
    // content, or content-quality protections.
    $strongExplicitOffer = $quality['blockers'] === []
      && $quality['warnings'] === []
      && $validityIssue === NULL
      && (
        $this->hasStrongExplicitOfferEvidence($candidate)
        || $this->hasStrongRecurringProgramOfferEvidence($candidate)
      );

    $reasons = [];
    foreach ($quality['blockers'] as $qualityBlocker) {
      $reasons[] = 'content quality: ' . $qualityBlocker;
    }
    foreach ($quality['warnings'] as $qualityWarning) {
      $reasons[] = 'content quality review: ' . $qualityWarning;
    }
    if ($validityIssue !== NULL) {
      $reasons[] = 'validity review: ' . $validityIssue;
    }
    if ($score < $autoApproveScore && !$strongExplicitOffer) {
      $reasons[] = sprintf('score %d is below configured automatic-approval score %d', $score, $autoApproveScore);
    }
    if ($locationConfidence < $minimumLocationConfidence) {
      $reasons[] = sprintf('location confidence %d is below configured minimum %d', $locationConfidence, $minimumLocationConfidence);
    }
    if ($requireSchedule && $schedule === '' && !$strongExplicitOffer) {
      $reasons[] = 'schedule or validity context is missing';
    }
    if ($sourceUrl === '') {
      $reasons[] = 'source URL is missing';
    }
    if ($value === '') {
      $reasons[] = 'offer value is missing';
    }
    if ($title === '') {
      $reasons[] = 'offer title is missing';
    }

    if ($reasons === []) {
      return [
        'status' => 'auto_approved',
        'confidence' => 'high',
        'reasons' => ['candidate met all configured automatic-approval requirements'],
      ];
    }

    return [
      'status' => 'pending',
      'confidence' => 'review',
      'reasons' => $reasons,
    ];
  }


  /**
   * Diagnoses the exact gates behind the strong explicit-offer bypass.
   *
   * This is read-only diagnostic output. Each gate delegates to the same
   * internal methods used by classify(), so audit commands do not need to
   * duplicate or approximate automatic-approval logic.
   *
   * @return array{
   *   strong_explicit_offer: bool,
   *   gates: array<string, array{passed: bool, detail: string}>
   * }
   */
  public function diagnoseStrongExplicitOfferEvidence(array $candidate): array {
    $title = trim((string) ($candidate['title'] ?? ''));
    $value = trim((string) ($candidate['value'] ?? ''));
    $reason = trim((string) ($candidate['reason'] ?? ''));
    $quality = $this->contentQuality->assessCandidate($candidate);
    $validityIssue = $this->detectTemporalValidityIssue($candidate);

    $hasBinding = preg_match(
      '/(?:^|;\\s*)binding=([a-z0-9_-]+)/i',
      $reason,
      $bindingMatches,
    ) === 1;
    $binding = $hasBinding
      ? mb_strtolower((string) $bindingMatches[1])
      : '';

    $explicitOfferValue = $this->hasExplicitOfferValue($value);
    $promotionalTitleSignal = $this->titleHasPromotionalSignal($title);
    $weakContainerTitle = $this->isWeakPromotionalContainerTitle($title);
    $multipleOfferValues = $this->hasMultipleDistinctOfferValues($title);
    $formExtractionBleed = $this->looksLikeFormExtractionBleed($title);
    $titleValueCoherence = $this->titleAndValueAreCoherent($title, $value);

    $gates = [
      'content_quality_blockers' => [
        'passed' => $quality['blockers'] === [],
        'detail' => $quality['blockers'] === []
          ? 'none'
          : implode('; ', array_map('strval', $quality['blockers'])),
      ],
      'content_quality_warnings' => [
        'passed' => $quality['warnings'] === [],
        'detail' => $quality['warnings'] === []
          ? 'none'
          : implode('; ', array_map('strval', $quality['warnings'])),
      ],
      'temporal_validity' => [
        'passed' => $validityIssue === NULL,
        'detail' => $validityIssue ?? 'no temporal validity issue detected',
      ],
      'title_present' => [
        'passed' => $title !== '',
        'detail' => $title !== '' ? 'present' : 'missing',
      ],
      'value_present' => [
        'passed' => $value !== '',
        'detail' => $value !== '' ? 'present' : 'missing',
      ],
      'explicit_offer_value' => [
        'passed' => $explicitOfferValue,
        'detail' => $explicitOfferValue
          ? 'value matches an explicit supported offer pattern'
          : 'value does not match an explicit supported offer pattern',
      ],
      'promotional_title_signal' => [
        'passed' => $promotionalTitleSignal,
        'detail' => $promotionalTitleSignal
          ? 'title contains a promotional signal'
          : 'title does not contain a promotional signal',
      ],
      'not_weak_container_title' => [
        'passed' => !$weakContainerTitle,
        'detail' => $weakContainerTitle
          ? 'title is a generic promotional container'
          : 'title is not a generic promotional container',
      ],
      'single_offer_value' => [
        'passed' => !$multipleOfferValues,
        'detail' => $multipleOfferValues
          ? 'title contains multiple distinct offer values'
          : 'title does not contain multiple distinct offer values',
      ],
      'no_form_extraction_bleed' => [
        'passed' => !$formExtractionBleed,
        'detail' => $formExtractionBleed
          ? 'title looks like form/input extraction bleed'
          : 'no form/input extraction bleed detected',
      ],
      'title_value_coherence' => [
        'passed' => $titleValueCoherence,
        'detail' => $titleValueCoherence
          ? 'title and extracted value are coherent'
          : 'title and extracted value are not coherent enough',
      ],
      'binding_present' => [
        'passed' => $hasBinding,
        'detail' => $hasBinding ? $binding : 'binding marker missing',
      ],
      'structured_binding' => [
        'passed' => $hasBinding && $binding !== 'text_fallback',
        'detail' => !$hasBinding
          ? 'binding marker missing'
          : ($binding === 'text_fallback'
            ? 'binding uses text_fallback'
            : 'binding=' . $binding),
      ],
    ];

    $strongRecurringProgramOffer = $this->hasStrongRecurringProgramOfferEvidence($candidate);
    $gates['recurring_program_offer'] = [
      'passed' => $strongRecurringProgramOffer,
      'detail' => $strongRecurringProgramOffer
        ? 'named program is structurally bound to a recurring free-admission/ticket benefit'
        : 'candidate does not meet the narrow recurring-program evidence path',
    ];

    return [
      'strong_explicit_offer' => $quality['blockers'] === []
        && $quality['warnings'] === []
        && $validityIssue === NULL
        && (
          $this->hasStrongExplicitOfferEvidence($candidate)
          || $strongRecurringProgramOffer
        ),
      'gates' => $gates,
    ];
  }


  /**
   * Returns a conservative validity issue when temporal wording is unsafe.
   *
   * Standing/recurring wording such as "every Wednesday" is allowed. The
   * classifier blocks automatic approval when an explicit end date is already
   * past, when a month-only promotion is outside that month, or when the offer
   * depends on unspecified select/seasonal days that the extractor did not
   * capture precisely enough to establish current applicability.
   */
  private function detectTemporalValidityIssue(array $candidate): ?string {
    $title = trim((string) ($candidate['title'] ?? ''));
    $schedule = trim((string) ($candidate['schedule'] ?? ''));
    $text = trim($title . ' ' . $schedule);

    if ($text === '') {
      return NULL;
    }

    $now = (new \DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone(new \DateTimeZone('UTC'));
    $today = $now->setTime(0, 0);

    // Explicit "select days" language is not actionable unless the extractor
    // also captured some concrete date/day validity context.
    if (
      preg_match('/\bon\s+select\s+days?\b|\bselect\s+days?\b/iu', $title) === 1
      && !$this->containsConcreteValidityContext($schedule)
    ) {
      return 'offer applies only on select days, but no concrete validity dates were extracted';
    }

    // Titles that advertise a particular free-admission day or set of play
    // dates are not actionable unless the extractor captured the actual date
    // or a recurring day. Standing wording such as "Free Admission Every Day"
    // is intentionally excluded.
    if (
      (
        preg_match('/\bfree\s+admission\s+day\b/iu', $title) === 1
        || preg_match('/\bplay\s+dates?\b/iu', $title) === 1
      )
      && preg_match('/\bevery\s+day\b/iu', $title) !== 1
      && !$this->containsConcreteValidityContext($schedule)
      && !$this->containsConcreteValidityContext($title)
    ) {
      return 'offer refers to specific promotional day(s), but no concrete dates were extracted';
    }

    // A bare "starting Month Day" is ambiguous across years. Allow it only
    // when the candidate carries an explicit year/date elsewhere.
    if (
      preg_match(
        '/\bstarting\s+(?:january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{1,2}(?:st|nd|rd|th)?\b/iu',
        $title,
      ) === 1
      && !$this->containsExplicitYearOrNumericDate($text)
      && preg_match('/\b(?:19|20)\d{2}\b/u', $schedule) !== 1
    ) {
      return 'offer has a starting month and day without a concrete year';
    }

    // Named seasonal/holiday ranges are unsafe without a concrete year/date
    // context. Their calendar dates move from year to year.
    if (
      preg_match(
        '/\b(?:armed\s+forces\s+day|memorial\s+day|labor\s+day|labour\s+day)\b.{0,80}\b(?:through|until|to|-)\b.{0,80}\b(?:armed\s+forces\s+day|memorial\s+day|labor\s+day|labour\s+day)\b/iu',
        $title,
      ) === 1
      && !$this->containsExplicitYearOrNumericDate($text)
    ) {
      return 'seasonal holiday range is present without a concrete year or date';
    }

    // "during summer" and similar seasonal-only wording can describe stale
    // pages. A strong-evidence bypass should require more precise validity.
    if (
      preg_match('/\b(?:during\s+(?:(?:its|the)\s+)?|this\s+)(?:spring|summer|fall|autumn|winter)\b/iu', $title) === 1
      && !$this->containsExplicitYearOrNumericDate($text)
      && !$this->containsConcreteValidityContext($schedule)
    ) {
      return 'seasonal validity is present without concrete dates';
    }

    $monthPattern = '(January|February|March|April|May|June|July|August|September|October|November|December)';
    if (
      preg_match(
        '/\b(?:through|thru|until|expires?(?:\s+on)?|ends?(?:\s+on)?|ending)\s+'
        . $monthPattern
        . '\s+(\d{1,2})?(?:st|nd|rd|th)?(?:,\s*|\s+)?(\d{4})\b/iu',
        $text,
        $matches,
      ) === 1
    ) {
      $month = (int) (new \DateTimeImmutable($matches[1] . ' 1 2000', new \DateTimeZone('UTC')))->format('n');
      $year = (int) $matches[3];
      $day = trim((string) ($matches[2] ?? '')) !== ''
        ? (int) $matches[2]
        : (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone('UTC')))
          ->modify('last day of this month')
          ->format('j');

      if (checkdate($month, $day, $year)) {
        $end = new \DateTimeImmutable(
          sprintf('%04d-%02d-%02d', $year, $month, $day),
          new \DateTimeZone('UTC'),
        );
        if ($end < $today) {
          return sprintf(
            'explicit validity ended on %s',
            $end->format('Y-m-d'),
          );
        }
      }
    }

    if (
      preg_match(
        '/\b(?:through|thru|until|expires?(?:\s+on)?|ends?(?:\s+on)?|ending)\s+'
        . '(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})\b/iu',
        $text,
        $matches,
      ) === 1
    ) {
      $month = (int) $matches[1];
      $day = (int) $matches[2];
      $year = (int) $matches[3];

      if (checkdate($month, $day, $year)) {
        $end = new \DateTimeImmutable(
          sprintf('%04d-%02d-%02d', $year, $month, $day),
          new \DateTimeZone('UTC'),
        );
        if ($end < $today) {
          return sprintf(
            'explicit validity ended on %s',
            $end->format('Y-m-d'),
          );
        }
      }
    }

    // Month-only promotions such as "all September-long" are safe only while
    // that month is current; otherwise the page may be stale or premature.
    if (
      preg_match(
        '/\b(?:all\s+)?' . $monthPattern . '(?:[-\s]+long)?\b/iu',
        $title,
        $matches,
      ) === 1
      && preg_match('/\b\d{4}\b/u', $title) !== 1
      && preg_match('/\b(?:through|until|starting|from|since|every|each)\b/iu', $title) !== 1
    ) {
      $offerMonth = (int) (new \DateTimeImmutable($matches[1] . ' 1 2000', new \DateTimeZone('UTC')))->format('n');
      if ($offerMonth !== (int) $today->format('n')) {
        return sprintf(
          'month-specific promotion references %s, but the current month is %s',
          $matches[1],
          $today->format('F'),
        );
      }
    }

    return NULL;
  }

  /**
   * Returns TRUE when a schedule contains concrete recurring/date context.
   */
  private function containsConcreteValidityContext(string $schedule): bool {
    if (trim($schedule) === '') {
      return FALSE;
    }

    return preg_match(
      '/(?:'
      . '\b(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)s?\b'
      . '|\b(?:january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{1,2}\b'
      . '|\b\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b'
      . '|\b\d{4}\b'
      . ')/iu',
      $schedule,
    ) === 1;
  }

  /**
   * Returns TRUE when text gives a year or numeric calendar date.
   */
  private function containsExplicitYearOrNumericDate(string $text): bool {
    return preg_match(
      '/\b(?:19|20)\d{2}\b|\b\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/u',
      $text,
    ) === 1;
  }

  /**
   * Returns TRUE only for structurally bound, explicit promotional evidence.
   *
   * A concrete value such as "free admission" is strong evidence of the value,
   * but it is not sufficient on its own. The title must also communicate a
   * promotion, and the extractor must have bound the offer to DOM structure
   * rather than the unstructured text fallback.
   */
  private function hasStrongExplicitOfferEvidence(array $candidate): bool {
    $title = trim((string) ($candidate['title'] ?? ''));
    $value = trim((string) ($candidate['value'] ?? ''));
    $reason = trim((string) ($candidate['reason'] ?? ''));

    if (
      $title === ''
      || $value === ''
      || !$this->hasExplicitOfferValue($value)
      || !$this->titleHasPromotionalSignal($title)
      || $this->isWeakPromotionalContainerTitle($title)
      || $this->hasMultipleDistinctOfferValues($title)
      || $this->looksLikeFormExtractionBleed($title)
      || !$this->titleAndValueAreCoherent($title, $value)
    ) {
      return FALSE;
    }

    if (preg_match('/(?:^|;\s*)binding=([a-z0-9_-]+)/i', $reason, $matches) !== 1) {
      return FALSE;
    }

    return mb_strtolower((string) $matches[1]) !== 'text_fallback';
  }


  /**
   * Allows a narrow class of named recurring access programs to bypass score.
   *
   * Museum/attraction programs such as "Bank of America Museums on Us" or
   * "Access for All at OMA" are legitimate promotion names even though the
   * title itself may not contain words such as "deal" or "discount". This
   * path therefore requires stronger surrounding evidence than the ordinary
   * explicit-offer path: a concrete free-admission/ticket value, a short named
   * program title, a strong DOM binding, and explicit recurring schedule text.
   */
  private function hasStrongRecurringProgramOfferEvidence(array $candidate): bool {
    $title = trim((string) ($candidate['title'] ?? ''));
    $value = trim((string) ($candidate['value'] ?? ''));
    $schedule = trim((string) ($candidate['schedule'] ?? ''));
    $reason = trim((string) ($candidate['reason'] ?? ''));

    if (
      $title === ''
      || $value === ''
      || $schedule === ''
      || mb_strlen($title) > 100
      || $this->isWeakPromotionalContainerTitle($title)
      || $this->isGenericRecurringProgramTitle($title)
      || $this->hasMultipleDistinctOfferValues($title)
      || $this->looksLikeFormExtractionBleed($title)
      || preg_match('/\b(?:free|complimentary)\s+(?:admission|entry|tickets?)\b/iu', $value) !== 1
      || !$this->containsRecurringOfferContext($schedule)
    ) {
      return FALSE;
    }

    if (preg_match('/(?:^|;\s*)binding=([a-z0-9_-]+)/i', $reason, $matches) !== 1) {
      return FALSE;
    }

    return in_array(mb_strtolower((string) $matches[1]), ['heading', 'li', 'article'], TRUE);
  }

  /**
   * Rejects generic labels that cannot identify a recurring offer by name.
   */
  private function isGenericRecurringProgramTitle(string $title): bool {
    $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? $title));

    return preg_match(
      '/^(?:admission|admissions|ticketing(?: information)?|tickets?|terms(?: and| &) conditions|hours|museum & shop hours|enhance your experience|prices?|membership|memberships)$/iu',
      $normalized,
    ) === 1;
  }

  /**
   * Returns TRUE only for explicit recurring schedule language.
   */
  private function containsRecurringOfferContext(string $schedule): bool {
    if (trim($schedule) === '') {
      return FALSE;
    }

    return preg_match(
      '/(?:'
      . '\b(?:every|each)\s+(?:day|weekday|weekend|week|month|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b'
      . '|\b(?:first|second|third|fourth|fifth|last)(?:\s+full)?\s+(?:weekday|weekend|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b.{0,50}\b(?:every|each)\s+month\b'
      . '|\b(?:first|second|third|fourth|fifth|last)(?:\s+(?:and|&)\s+(?:first|second|third|fourth|fifth|last))+\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b.{0,50}\b(?:every|each)\s+month\b'
      . ')/iu',
      $schedule,
    ) === 1;
  }


  /**
   * Returns TRUE for generic promotional containers that need human context.
   *
   * These labels can sit above many unrelated offers. A strong value found
   * nearby must not turn the container heading itself into a high-confidence
   * deal title.
   */
  private function isWeakPromotionalContainerTitle(string $title): bool {
    $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? $title));

    return preg_match(
      '/^(?:free|deals?|offers?|special\s+offers?|promotions?|discounts?|savings?|ways\s+to\s+save|programs?\s*(?:&|and)\s*special\s+offers?|free\s+days?\s*(?:&|and)\s*other\s+free\s+admission\s+offers?|opportunities\s+for\s+free\s+admission)$/iu',
      $normalized,
    ) === 1;
  }

  /**
   * Detects titles that appear to contain more than one distinct offer value.
   *
   * Example: "50% Off Arcade Games ... 20% Off Food" cannot safely be bound
   * to one extracted value without additional structural evidence.
   */
  private function hasMultipleDistinctOfferValues(string $title): bool {
    preg_match_all(
      '/\b\d{1,3}(?:\.\d+)?\s*%\s*off\b|[$£€]\s*\d+(?:\.\d{1,2})?\s*off\b/iu',
      $title,
      $matches,
    );

    $values = array_map(
      static fn(string $match): string => mb_strtolower(preg_replace('/\s+/u', '', $match) ?? $match),
      $matches[0] ?? [],
    );

    return count(array_unique($values)) > 1;
  }

  /**
   * Detects form/input labels that have bled into an extracted offer title.
   */
  private function looksLikeFormExtractionBleed(string $title): bool {
    $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? $title));
    $signals = 0;

    foreach ([
      '/\bfull\s+name\b/iu',
      '/\be-?mail\b/iu',
      '/\bstudent\s+id\b/iu',
      '/\bcampus\s+code\b/iu',
      '/\bunlock\b/iu',
      '/\bsubmit\b/iu',
    ] as $pattern) {
      if (preg_match($pattern, $normalized) === 1) {
        $signals++;
      }
    }

    return $signals >= 2;
  }

  /**
   * Requires the title and extracted value to describe one coherent offer.
   *
   * Exact numeric/free wording in the title is strongest. Descriptive titles
   * such as "Military Discount" are also acceptable because "discount"
   * explicitly names the benefit even when the percentage is carried in the
   * extracted value. Generic "offer/deal/special" wording alone is not enough.
   */
  private function titleAndValueAreCoherent(string $title, string $value): bool {
    $normalizedTitle = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? $title));
    $normalizedValue = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));

    if ($normalizedTitle === '' || $normalizedValue === '') {
      return FALSE;
    }

    if (str_contains($normalizedTitle, $normalizedValue)) {
      return TRUE;
    }

    // Numeric benefits should agree with the title whenever the title states
    // a concrete amount or percentage.
    if (preg_match('/(\d{1,3}(?:\.\d+)?)\s*%\s*off/iu', $normalizedValue, $valuePercent) === 1) {
      if (preg_match_all('/(\d{1,3}(?:\.\d+)?)\s*%\s*off/iu', $normalizedTitle, $titlePercents) > 0) {
        return in_array($valuePercent[1], $titlePercents[1], TRUE);
      }
    }

    if (preg_match('/[$£€]\s*(\d+(?:\.\d{1,2})?)\s*off/iu', $normalizedValue, $valueAmount) === 1) {
      if (preg_match_all('/[$£€]\s*(\d+(?:\.\d{1,2})?)\s*off/iu', $normalizedTitle, $titleAmounts) > 0) {
        return in_array($valueAmount[1], $titleAmounts[1], TRUE);
      }
    }

    if (preg_match('/\bfree\s+(admission|entry|tickets?|games?|services?|items?)\b/iu', $normalizedValue, $freeValue) === 1) {
      if (preg_match('/\bfree\b/iu', $normalizedTitle) === 1) {
        return TRUE;
      }
    }

    // Fixed-price promotions such as "only $49.99" are coherent when the
    // same currency amount appears in the promotional title.
    if (preg_match('/\b(?:only|now|special(?:ly)? priced? at)\s+([$£€])\s*(\d+(?:\.\d{1,2})?)\b/iu', $normalizedValue, $fixedPrice) === 1) {
      $currency = preg_quote($fixedPrice[1], '/');
      $amount = preg_quote($fixedPrice[2], '/');
      if (preg_match('/' . $currency . '\s*' . $amount . '\b/u', $normalizedTitle) === 1) {
        return TRUE;
      }
    }

    // A descriptive discount/savings title can safely carry its exact amount
    // in the separately extracted value. Avoid generic offer/deal labels here.
    return preg_match(
      '/\b(?:discount|save|savings|sale|reduced|half[- ]price|complimentary)\b/iu',
      $normalizedTitle,
    ) === 1;
  }

  /**
   * Returns TRUE when a comparison value proves there is no price discount.
   *
   * Deal discovery represents current/regular price pairs as "$X vs $Y".
   * Equal prices are not a promotion; unequal comparisons remain reviewable
   * because they may represent a real sale even without explicit "off" text.
   */
  private function hasIdenticalComparisonPrices(string $value): bool {
    if (preg_match(
      '/^\s*([$£€])\s*(\d+(?:\.\d{1,2})?)\s+vs\s+([$£€])\s*(\d+(?:\.\d{1,2})?)\s*$/iu',
      $value,
      $matches,
    ) !== 1) {
      return FALSE;
    }

    if ($matches[1] !== $matches[3]) {
      return FALSE;
    }

    return (float) $matches[2] === (float) $matches[4];
  }

  /**
   * Returns TRUE when the extracted value itself is a concrete deal value.
   */
  private function hasExplicitOfferValue(string $value): bool {
    return preg_match(
      '/(?:'
      . '\b\d{1,3}(?:\.\d+)?\s*%\s*off\b'
      . '|\b(?:save|savings?)\s+(?:up\s+to\s+)?(?:[$£€]\s*\d+(?:\.\d{1,2})?\b|\d{1,3}(?:\.\d+)?\s*%(?![\p{L}\p{N}]))'
      . '|[$£€]\s*\d+(?:\.\d{1,2})?\s+off\b'
      . '|\bbuy\s+(?:one|\d+)\b.{0,80}\bget\s+(?:one|\d+)\s+free\b'
      . '|\bfree\s+(?:admission|entry|ticket|tickets|game|games|service|services|item|items)\b'
      . '|\bcomplimentary\s+(?:admission|entry|ticket|tickets)\b'
      . '|\b(?:only|now|special(?:ly)? priced? at)\s+[$£€]\s*\d+(?:\.\d{1,2})?\b'
      . ')/iu',
      $value,
    ) === 1;
  }

  /**
   * Returns TRUE when the title itself reads like a promotion.
   */
  private function titleHasPromotionalSignal(string $title): bool {
    return preg_match(
      '/(?:'
      . '\b(?:deal|discount|offer|promo(?:tion)?|special|coupon|save|savings|sale|free|complimentary|reduced|half[- ]price|happy\s+hour)\b'
      . '|\b\d{1,3}(?:\.\d+)?\s*%\s*off\b'
      . '|[$£€]\s*\d+(?:\.\d{1,2})?\s+off\b'
      . '|\bbuy\s+(?:one|\d+)\b.{0,80}\bget\s+(?:one|\d+)\s+free\b'
      . ')/iu',
      $title,
    ) === 1;
  }

}
