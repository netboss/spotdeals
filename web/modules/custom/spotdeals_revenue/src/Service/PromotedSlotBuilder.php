<?php

namespace Drupal\spotdeals_revenue\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Utility\Xss;

/**
 * Builds the search-only promoted slot foundation.
 */
class PromotedSlotBuilder {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs the promoted slot builder.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->configFactory = $configFactory;
  }

  /**
   * Builds the promoted slot render array for search pages only.
   */
  public function buildSearchSlot(): array {
    $config = $this->configFactory->get('spotdeals_revenue.settings');

    if (!$config->get('promoted_slot_enabled')) {
      return [];
    }

    $markup = trim((string) $config->get('promoted_slot_markup'));
    if ($markup === '') {
      return [];
    }

    return [
      '#theme' => 'spotdeals_promoted_slot',
      '#label' => trim((string) $config->get('promoted_slot_label')) ?: 'Sponsored',
      '#markup' => Xss::filterAdmin($markup),
    ];
  }

}
