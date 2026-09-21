<?php

namespace Drupal\spotdeals_revenue\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Utility\Xss;

/**
 * Builds SpotDeals promoted slot render arrays.
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
    return $this->buildConfiguredSlot('promoted_slot_enabled');
  }

  /**
   * Builds the promoted slot for restricted private profile pages.
   */
  public function buildProfileSlot(): array {
    return $this->buildConfiguredSlot('profile_promoted_slot_enabled');
  }

  /**
   * Builds a promoted slot using the shared label and markup configuration.
   */
  private function buildConfiguredSlot(string $enabledKey): array {
    $config = $this->configFactory->get('spotdeals_revenue.settings');

    if (!$config->get($enabledKey)) {
      return [];
    }

    $adsense_client = trim((string) $config->get('adsense_client'));
    $adsense_slot = trim((string) $config->get('adsense_slot'));
    $markup = trim((string) $config->get('promoted_slot_markup'));

    if ($adsense_client === '' || $adsense_slot === '') {
      if ($markup === '') {
        return [];
      }

      return [
        '#theme' => 'spotdeals_promoted_slot',
        '#label' => trim((string) $config->get('promoted_slot_label')) ?: 'Sponsored',
        '#markup' => Xss::filterAdmin($markup),
        '#cache' => [
          'tags' => ['config:spotdeals_revenue.settings'],
        ],
      ];
    }

    return [
      '#theme' => 'spotdeals_promoted_slot',
      '#label' => trim((string) $config->get('promoted_slot_label')) ?: 'Sponsored',
      '#markup' => '',
      '#adsense_client' => $adsense_client,
      '#adsense_slot' => $adsense_slot,
      '#attached' => [
        'library' => ['spotdeals_revenue/adsense'],
      ],
      '#cache' => [
        'tags' => ['config:spotdeals_revenue.settings'],
      ],
    ];
  }

}
