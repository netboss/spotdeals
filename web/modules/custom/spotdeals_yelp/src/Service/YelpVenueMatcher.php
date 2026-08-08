<?php

declare(strict_types=1);

namespace Drupal\spotdeals_yelp\Service;

use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Matches SpotDeals venues to Yelp businesses.
 */
final class YelpVenueMatcher {

  /**
   * Constructs the matcher.
   */
  public function __construct(
    private readonly YelpApiClient $apiClient,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Attempts to match a venue.
   */
  public function matchVenue(NodeInterface $venue): array {
    $phone = $this->normalizePhone($this->getPlainFieldValue($venue, 'field_phone'));
    if (!empty($phone)) {
      try {
        $response = $this->apiClient->searchByPhone($phone);
        foreach (($response['businesses'] ?? []) as $candidate) {
          $confidence = $this->calculateConfidence($venue, $candidate, TRUE);
          if ($confidence >= 0.90) {
            return $this->buildResult(TRUE, $candidate, $confidence, 'phone_exact');
          }
        }
      }
      catch (\Throwable $exception) {
        $this->logger->warning('Yelp phone match failed for venue @nid: @message', [
          '@nid' => $venue->id(),
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    try {
      $response = $this->apiClient->matchBusiness($this->buildBusinessMatchPayload($venue));
      $bestCandidate = NULL;
      $bestConfidence = 0.0;
      foreach (($response['businesses'] ?? []) as $candidate) {
        $confidence = $this->calculateConfidence($venue, $candidate, FALSE);
        if ($confidence > $bestConfidence) {
          $bestConfidence = $confidence;
          $bestCandidate = $candidate;
        }
      }

      if (is_array($bestCandidate) && $bestConfidence >= 0.90) {
        return $this->buildResult(TRUE, $bestCandidate, $bestConfidence, 'business_match');
      }

      if (is_array($bestCandidate) && $bestConfidence >= 0.70) {
        return $this->buildResult(FALSE, $bestCandidate, $bestConfidence, 'needs_review');
      }
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Yelp business match failed for venue @nid: @message', [
        '@nid' => $venue->id(),
        '@message' => $exception->getMessage(),
      ]);
      return [
        'matched' => FALSE,
        'business_id' => NULL,
        'confidence' => 0.0,
        'status' => 'error',
        'reason' => $exception->getMessage(),
        'candidate' => [],
      ];
    }

    return [
      'matched' => FALSE,
      'business_id' => NULL,
      'confidence' => 0.0,
      'status' => 'unmatched',
      'reason' => 'No Yelp match found.',
      'candidate' => [],
    ];
  }

  /**
   * Normalizes a venue phone number to a digit-only US format.
   */
  protected function normalizePhone(?string $phone): ?string {
    if ($phone === NULL || trim($phone) === '') {
      return NULL;
    }

    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
      return NULL;
    }

    if (strlen($digits) === 10) {
      return '+1' . $digits;
    }

    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
      return '+' . $digits;
    }

    return str_starts_with($digits, '+') ? $digits : '+' . $digits;
  }

  /**
   * Builds the Yelp business match request payload.
   */
  protected function buildBusinessMatchPayload(NodeInterface $venue): array {
    $addressField = $venue->get('field_address');
    $address = !$addressField->isEmpty() ? $addressField->first() : NULL;

    return [
      'name' => $venue->label(),
      'address1' => $address?->address_line1 ?? '',
      'city' => $address?->locality ?? '',
      'state' => $address?->administrative_area ?? '',
      'zip_code' => $address?->postal_code ?? '',
      'country' => $address?->country_code ?? 'US',
    ];
  }

  /**
   * Estimates the confidence for a candidate match.
   */
  protected function calculateConfidence(NodeInterface $venue, array $candidate, bool $phoneMatched): float {
    $confidence = $phoneMatched ? 0.60 : 0.0;

    $venueLabel = mb_strtolower(trim($venue->label()));
    $candidateLabel = mb_strtolower(trim((string) ($candidate['name'] ?? '')));
    similar_text($venueLabel, $candidateLabel, $nameSimilarity);
    $confidence += ($nameSimilarity / 100) * 0.25;

    $addressField = $venue->get('field_address');
    $address = !$addressField->isEmpty() ? $addressField->first() : NULL;
    $venueAddressLine1 = mb_strtolower(trim((string) ($address?->address_line1 ?? '')));
    $candidateAddressLine1 = mb_strtolower(trim((string) ($candidate['location']['address1'] ?? '')));
    if ($venueAddressLine1 !== '' && $venueAddressLine1 === $candidateAddressLine1) {
      $confidence += 0.10;
    }

    $venueCity = mb_strtolower(trim((string) ($address?->locality ?? '')));
    $candidateCity = mb_strtolower(trim((string) ($candidate['location']['city'] ?? '')));
    if ($venueCity !== '' && $venueCity === $candidateCity) {
      $confidence += 0.03;
    }

    $venueState = mb_strtolower(trim((string) ($address?->administrative_area ?? '')));
    $candidateState = mb_strtolower(trim((string) ($candidate['location']['state'] ?? '')));
    if ($venueState !== '' && $venueState === $candidateState) {
      $confidence += 0.02;
    }

    return min(1.0, round($confidence, 4));
  }

  /**
   * Returns a field value as plain text when available.
   */
  private function getPlainFieldValue(NodeInterface $venue, string $fieldName): ?string {
    if (!$venue->hasField($fieldName) || $venue->get($fieldName)->isEmpty()) {
      return NULL;
    }

    return trim((string) $venue->get($fieldName)->value);
  }

  /**
   * Builds a normalized match result array.
   */
  private function buildResult(bool $matched, array $candidate, float $confidence, string $reason): array {
    return [
      'matched' => $matched,
      'business_id' => $candidate['id'] ?? NULL,
      'confidence' => $confidence,
      'status' => $matched ? 'matched' : 'needs_review',
      'reason' => $reason,
      'candidate' => $candidate,
    ];
  }

}
