<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ensures extracted excerpts survive candidate creation and refresh.
 */
#[Group('spotdeals_data_ingestion')]
#[RunTestsInSeparateProcesses]
final class DealDiscoverySnippetStorageTest extends KernelTestBase {

  protected static $modules = ['system'];

  protected function setUp(): void {
    parent::setUp();
    // Install only the candidate table. Enabling the full module would compile
    // unrelated services, including the Pathauto-dependent translation service.
    require_once DRUPAL_ROOT . '/modules/custom/spotdeals_data_ingestion/spotdeals_data_ingestion.install';
    $definitions = spotdeals_data_ingestion_schema();
    $this->container->get('database')->schema()->createTable(
      'spotdeals_deal_discovery_candidate',
      $definitions['spotdeals_deal_discovery_candidate'],
    );
  }

  public function testSnippetPersistsAndRefreshPreservesReviewDecision(): void {
    $storage = new DealDiscoveryStorage(
      $this->container->get('database'),
      $this->container->get('datetime.time'),
    );
    $values = [
      'external_source' => 'geoapify',
      'external_id' => 'test-venue',
      'source_url' => 'https://example.org/offer',
      'offer_title' => 'Test offer',
      'offer_value' => '15% off',
      'snippet' => 'First complete source excerpt.',
      'status' => 'approved',
    ];
    $id = $storage->createOrRefresh($values);
    self::assertSame('First complete source excerpt.', $storage->load($id)['snippet']);

    $values['snippet'] = 'Refreshed source excerpt.';
    $values['status'] = 'pending';
    self::assertSame($id, $storage->createOrRefresh($values));
    $stored = $storage->load($id);
    self::assertSame('Refreshed source excerpt.', $stored['snippet']);
    self::assertSame('approved', $stored['status']);
  }

}
