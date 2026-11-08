<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Kernel\Service;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies that generated deal descriptions survive real entity persistence.
 *
 * This exercises Drupal's field storage and text-format configuration without
 * publishing discovery candidates or touching the site's existing database.
 */
#[Group('spotdeals_data_ingestion')]
#[RunTestsInSeparateProcesses]
final class DealDiscoveryBodyPersistenceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'node', 'filter']);

    NodeType::create([
      'type' => 'deal',
      'name' => 'Deal',
    ])->save();

    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'status' => TRUE,
      'filters' => [],
    ])->save();

    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'body',
      'type' => 'text_with_summary',
    ])->save();

    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'deal',
      'field_name' => 'body',
      'label' => 'Body',
      'required' => FALSE,
      'settings' => [
        'allowed_formats' => ['basic_html', 'full_html'],
      ],
    ])->save();

    // Text-format validation checks the current user's format permissions.
    // Grant only the format permission needed by this persistence test.
    $role = Role::create([
      'id' => 'description_editor',
      'label' => 'Description editor',
    ]);
    $role->grantPermission('use text format basic_html');
    $role->save();

    $editor = User::create([
      'name' => 'description_editor',
      'status' => 1,
      'roles' => ['description_editor'],
    ]);
    $editor->save();
    $this->container->get('current_user')->setAccount($editor);
  }

  public function testApprovedBasicHtmlDescriptionPersistsAndReloads(): void {
    $description = 'Free admission is available to qualifying visitors on selected dates. Bring proof of eligibility to the admissions desk before entering.';
    $deal = Node::create([
      'type' => 'deal',
      'title' => 'Test offer',
      'langcode' => 'en',
      'status' => 1,
      'body' => [
        'value' => $description,
        'format' => 'basic_html',
      ],
    ]);
    $violations = $deal->validate();
    self::assertCount(0, $violations, (string) $violations);
    $deal->save();

    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$deal->id()]);
    $reloaded = Node::load($deal->id());
    self::assertNotNull($reloaded);
    self::assertSame($description, $reloaded->get('body')->value);
    self::assertSame('basic_html', $reloaded->get('body')->format);
    self::assertSame('en', $reloaded->language()->getId());
  }

  public function testDealWithoutDescriptionPersistsWithEmptyBody(): void {
    $deal = Node::create([
      'type' => 'deal',
      'title' => 'Offer without usable excerpt',
      'langcode' => 'en',
      'status' => 1,
    ]);
    $violations = $deal->validate();
    self::assertCount(0, $violations, (string) $violations);
    $deal->save();

    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$deal->id()]);
    $reloaded = Node::load($deal->id());
    self::assertNotNull($reloaded);
    self::assertTrue($reloaded->get('body')->isEmpty());
  }

}
