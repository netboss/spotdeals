<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote;

/**
 * Shared vote field definitions.
 */
final class VoteFields {

  /**
   * Supported vote fields.
   *
   * @var array<int,string>
   */
  public const ALLOWED_FIELDS = [
    'worth_it',
    'would_go_again',
  ];

  /**
   * Returns the allowed field names.
   *
   * @return array<int,string>
   *   Allowed field names.
   */
  public function all(): array {
    return self::ALLOWED_FIELDS;
  }

  /**
   * Checks whether a field is supported.
   */
  public function isAllowed(string $fieldName): bool {
    return in_array($fieldName, self::ALLOWED_FIELDS, TRUE);
  }

}
