<?php

namespace Drupal\spotdeals_admin\Service;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Builds the public "Claim this listing" link for a venue.
 */
final class ClaimLinkBuilderService {

  use StringTranslationTrait;

  /**
   * Constructs a ClaimLinkBuilderService object.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Builds the claim link render array for a venue.
   *
   * Anonymous users are sent to login with the claim form preserved as the
   * destination. Authenticated users are sent directly to the claim form.
   *
   * @param \Drupal\node\NodeInterface $venue
   *   The venue being claimed.
   * @param string[] $classes
   *   Additional CSS classes for the link.
   * @param int|null $weight
   *   Optional render-array weight.
   *
   * @return array
   *   The claim link render array.
   */
  public function build(NodeInterface $venue, array $classes = [], ?int $weight = NULL): array {
    $destination = '/create/claim?venue=' . $venue->id();
    $url = $this->currentUser->isAnonymous()
      ? Url::fromRoute('user.login', [], [
        'query' => [
          'destination' => $destination,
        ],
      ])
      : Url::fromUri('internal:' . $destination);

    $classes[] = 'claim-this-listing-link';

    $build = [
      '#type' => 'link',
      '#title' => $this->t('Claim this listing'),
      '#url' => $url,
      '#attributes' => [
        'class' => array_values(array_unique($classes)),
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => $venue->getCacheTags(),
      ],
    ];

    if ($weight !== NULL) {
      $build['#weight'] = $weight;
    }

    return $build;
  }

}
