<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

/**
 * Normalizes and validates discovered deal content before publishing.
 */
final class DealDiscoveryContentQualityService {

  /**
   * Applies only deterministic, meaning-preserving candidate normalization.
   *
   * @param array<string, mixed> $candidate
   *   Discovery candidate using extractor keys (title/value/schedule) or stored
   *   keys (offer_title/offer_value/schedule).
   *
   * @return array<string, mixed>
   *   Candidate with normalized text values.
   */
  public function normalizeCandidate(array $candidate): array {
    if (array_key_exists('title', $candidate)) {
      $candidate['title'] = $this->normalizeTitle((string) $candidate['title']);
    }
    if (array_key_exists('offer_title', $candidate)) {
      $candidate['offer_title'] = $this->normalizeTitle((string) $candidate['offer_title']);
    }
    if (array_key_exists('value', $candidate)) {
      $candidate['value'] = $this->normalizeText((string) $candidate['value']);
    }
    if (array_key_exists('offer_value', $candidate)) {
      $candidate['offer_value'] = $this->normalizeText((string) $candidate['offer_value']);
    }
    if (array_key_exists('schedule', $candidate)) {
      $candidate['schedule'] = $this->normalizeText((string) $candidate['schedule']);
    }
    if (array_key_exists('snippet', $candidate)) {
      $candidate['snippet'] = $this->normalizeText((string) $candidate['snippet']);
    }

    return $candidate;
  }

  /**
   * Returns deterministic normalizations and quality blockers/warnings.
   *
   * @return array{
   *   normalized: array<string, mixed>,
   *   corrections: array<string, array{from: string, to: string}>,
   *   blockers: array<int, string>,
   *   warnings: array<int, string>
   * }
   */
  public function assessCandidate(array $candidate): array {
    $normalized = $this->normalizeCandidate($candidate);
    $corrections = [];

    foreach ([
      'title' => 'title',
      'offer_title' => 'offer_title',
      'value' => 'value',
      'offer_value' => 'offer_value',
      'schedule' => 'schedule',
    ] as $sourceKey => $normalizedKey) {
      if (!array_key_exists($sourceKey, $candidate) || !array_key_exists($normalizedKey, $normalized)) {
        continue;
      }

      $from = (string) $candidate[$sourceKey];
      $to = (string) $normalized[$normalizedKey];
      if ($from !== $to) {
        $corrections[$sourceKey] = [
          'from' => $from,
          'to' => $to,
        ];
      }
    }

    $titleKey = array_key_exists('offer_title', $normalized) ? 'offer_title' : 'title';
    $valueKey = array_key_exists('offer_value', $normalized) ? 'offer_value' : 'value';
    $title = trim((string) ($normalized[$titleKey] ?? ''));
    $value = trim((string) ($normalized[$valueKey] ?? ''));

    $blockers = [];
    $warnings = [];

    if ($title === '') {
      $blockers[] = 'Offer title is empty after deterministic content normalization.';
    }
    if ($value === '') {
      $blockers[] = 'Offer value is empty after deterministic content normalization.';
    }

    if ($title !== '') {
      if (preg_match('#https?://|www\.#i', $title) === 1) {
        $blockers[] = 'Offer title contains a URL and requires manual review.';
      }
      if (preg_match('/^[\p{P}\p{S}]+$/u', $title) === 1) {
        $blockers[] = 'Offer title contains only punctuation or symbols.';
      }
      if (preg_match('/(?:[:;|]\s*){2,}$/u', $title) === 1) {
        $blockers[] = 'Offer title ends with repeated delimiter punctuation.';
      }
      if (preg_match('/\b(?:learn more|read more|click here|view details)\s*$/i', $title) === 1) {
        $warnings[] = 'Offer title appears to contain website-navigation boilerplate.';
      }
      if (mb_strlen($title) > 160) {
        $warnings[] = 'Offer title is unusually long and should be reviewed for extraction bleed.';
      }
    }

    return [
      'normalized' => $normalized,
      'corrections' => $corrections,
      'blockers' => $blockers,
      'warnings' => $warnings,
    ];
  }

  /**
   * Audits one already-published title without changing it.
   *
   * @return array{normalized: string, issues: array<int, string>}
   */
  public function assessPublishedTitle(string $title): array {
    $normalized = $this->normalizeTitle($title);
    $issues = [];

    if ($title !== $normalized) {
      $issues[] = 'Published title differs from deterministic normalized title.';
    }
    if ($normalized === '') {
      $issues[] = 'Published title is empty after deterministic normalization.';
    }
    if (preg_match('#https?://|www\.#i', $normalized) === 1) {
      $issues[] = 'Published title contains a URL.';
    }
    if (preg_match('/\b(?:learn more|read more|click here|view details)\s*$/i', $normalized) === 1) {
      $issues[] = 'Published title appears to contain website-navigation boilerplate.';
    }
    if (mb_strlen($normalized) > 160) {
      $issues[] = 'Published title is unusually long and may contain extraction bleed.';
    }

    return [
      'normalized' => $normalized,
      'issues' => $issues,
    ];
  }

  /**
   * Normalizes title artifacts that are safe to correct mechanically.
   */
  public function normalizeTitle(string $title): string {
    $title = $this->normalizeText($title);

    // HTML headings frequently include a colon/semicolon as a visual delimiter
    // before body copy. It is not part of the deal title when it trails it.
    $title = preg_replace('/\s*[:;]+\s*$/u', '', $title) ?? $title;

    // Remove whitespace that leaked before punctuation during DOM text joins.
    $title = preg_replace('/\s+([,:;!?])/u', '$1', $title) ?? $title;

    return trim($title);
  }

  private function normalizeText(string $text): string {
    $text = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], ' ', $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
  }

}
