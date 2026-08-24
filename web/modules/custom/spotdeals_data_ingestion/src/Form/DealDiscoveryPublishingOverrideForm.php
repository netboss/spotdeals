<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves publishing blockers with explicit administrative overrides.
 */
final class DealDiscoveryPublishingOverrideForm extends FormBase {

  private array $candidate = [];

  public function __construct(
    private readonly DealDiscoveryStorage $storage,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $account,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_data_ingestion.deal_discovery_storage'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'spotdeals_deal_discovery_publishing_override_form';
  }

  /**
   * @param array<string, string> $blocking_fields
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?int $candidate_id = NULL,
    array $blocking_fields = [],
    array $blocking_suggestions = [],
  ): array {
    $this->candidate = $this->storage->load((int) $candidate_id) ?? [];
    if ($this->candidate === []) {
      throw new NotFoundHttpException();
    }

    $form['#tree'] = TRUE;

    $form['candidate_id'] = [
      '#type' => 'hidden',
      '#value' => (int) $this->candidate['id'],
    ];

    $form['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Resolve a supported blocker here. Select an existing taxonomy term, or explicitly create a new term and assign it to this candidate. Nothing is created unless you submit this form.',
      ) . '</p>',
    ];

    $supportedBlockers = 0;

    if (isset($blocking_fields['field_day_of_week'])) {
      $supportedBlockers++;
      $form['field_day_of_week'] = $this->buildTaxonomyResolver(
        vocabulary: 'day_of_week',
        title: (string) $this->t('Resolve Day of Week'),
        currentTid: (int) ($this->candidate['override_day_of_week_tid'] ?? 0),
        createPermission: 'create terms in day_of_week',
        suggestion: is_array($blocking_suggestions['field_day_of_week'] ?? NULL)
          ? $blocking_suggestions['field_day_of_week']
          : NULL,
      );
    }

    if (isset($blocking_fields['field_deal_category'])) {
      $supportedBlockers++;
      $form['field_deal_category'] = $this->buildTaxonomyResolver(
        vocabulary: 'deal_category',
        title: (string) $this->t('Resolve Deal Category'),
        currentTid: (int) ($this->candidate['override_deal_category_tid'] ?? 0),
        createPermission: 'create terms in deal_category',
        suggestion: is_array($blocking_suggestions['field_deal_category'] ?? NULL)
          ? $blocking_suggestions['field_deal_category']
          : NULL,
      );
    }

    if ($supportedBlockers === 0) {
      $form['unsupported'] = [
        '#type' => 'item',
        '#markup' => $this->t(
          'This candidate has no taxonomy blocker that can be resolved with a publishing override.',
        ),
      ];

      return $form;
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save override and recheck preview'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach ([
      'field_day_of_week' => 'Day of Week',
      'field_deal_category' => 'Deal Category',
    ] as $key => $label) {
      if (!isset($form[$key])) {
        continue;
      }

      $values = (array) $form_state->getValue($key, []);
      $existingTid = (int) ($values['existing_tid'] ?? 0);
      $newTerm = trim((string) ($values['new_term'] ?? ''));

      if ($existingTid > 0 && $newTerm !== '') {
        $form_state->setError(
          $form[$key],
          $this->t(
            'For @field, choose an existing term or enter a new term, not both.',
            ['@field' => $label],
          ),
        );
      }

      if ($existingTid <= 0 && $newTerm === '') {
        $form_state->setError(
          $form[$key],
          $this->t(
            'Choose an existing @field term or enter a new term.',
            ['@field' => $label],
          ),
        );
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $candidateId = (int) $form_state->getValue('candidate_id');
    $candidate = $this->storage->load($candidateId);
    if ($candidate === NULL) {
      throw new NotFoundHttpException();
    }

    $overrides = [];

    if (isset($form['field_day_of_week'])) {
      $overrides['override_day_of_week_tid'] = $this->resolveSubmittedTerm(
        values: (array) $form_state->getValue('field_day_of_week', []),
        vocabulary: 'day_of_week',
        createPermission: 'create terms in day_of_week',
        fieldLabel: 'Day of Week',
      );
    }

    if (isset($form['field_deal_category'])) {
      $overrides['override_deal_category_tid'] = $this->resolveSubmittedTerm(
        values: (array) $form_state->getValue('field_deal_category', []),
        vocabulary: 'deal_category',
        createPermission: 'create terms in deal_category',
        fieldLabel: 'Deal Category',
      );
    }

    $this->storage->savePublishingOverrides($candidateId, $overrides);

    $this->messenger()->addStatus(
      $this->t('The publishing override was saved. The preview has been recalculated.'),
    );

    $form_state->setRedirect(
      'spotdeals_data_ingestion.deal_discovery_publish_preview',
      ['candidate_id' => $candidateId],
    );
  }

  /**
   * Builds one existing-or-new taxonomy resolver.
   */
  private function buildTaxonomyResolver(
    string $vocabulary,
    string $title,
    int $currentTid,
    string $createPermission,
    ?array $suggestion = NULL,
  ): array {
    $element = [
      '#type' => 'fieldset',
      '#title' => $title,
    ];

    if ($suggestion !== NULL) {
      $suggestedName = (string) ($suggestion['name'] ?? '');
      $confidence = strtoupper((string) ($suggestion['confidence'] ?? ''));
      $usageCount = (int) ($suggestion['usage_count'] ?? 0);
      $reason = (string) ($suggestion['reason'] ?? '');

      $element['suggestion'] = [
        '#type' => 'item',
        '#title' => $this->t('Suggested resolution'),
        '#markup' => '<p><strong>'
          . htmlspecialchars($suggestedName)
          . '</strong> — '
          . $this->t('@confidence confidence', ['@confidence' => $confidence])
          . '</p><p>'
          . htmlspecialchars($reason)
          . ' '
          . $this->t('Current usage: @count deal nodes.', ['@count' => $usageCount])
          . '</p>',
      ];
    }

    $suggestedTid = (int) ($suggestion['target_id'] ?? 0);
    $defaultTid = $currentTid > 0 ? $currentTid : $suggestedTid;

    $element['existing_tid'] = [
      '#type' => 'select',
      '#title' => $this->t('Use existing taxonomy term'),
      '#options' => $this->taxonomyOptions($vocabulary),
      '#empty_option' => $this->t('- Select an existing term -'),
      '#default_value' => $defaultTid > 0 ? $defaultTid : '',
      '#description' => $this->t(
        'Choose this when an existing taxonomy term accurately represents the candidate.',
      ),
    ];

    if ($this->canCreateTerm($createPermission)) {
      $element['new_term'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Or create a new taxonomy term'),
        '#maxlength' => 255,
        '#description' => $this->t(
          'Use this only when no existing term accurately represents the candidate. The new term will be created explicitly and immediately assigned to this candidate.',
        ),
      ];
    }
    else {
      $element['new_term'] = [
        '#type' => 'value',
        '#value' => '',
      ];
      $element['create_notice'] = [
        '#type' => 'item',
        '#markup' => $this->t(
          'Your account can assign existing terms but does not have permission to create new terms in this vocabulary.',
        ),
      ];
    }

    return $element;
  }

  /**
   * Resolves one submitted existing or newly created taxonomy term.
   */
  private function resolveSubmittedTerm(
    array $values,
    string $vocabulary,
    string $createPermission,
    string $fieldLabel,
  ): int {
    $existingTid = (int) ($values['existing_tid'] ?? 0);
    if ($existingTid > 0) {
      $term = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->load($existingTid);

      if ($term === NULL || $term->bundle() !== $vocabulary) {
        throw new \RuntimeException(
          sprintf('The selected %s taxonomy term is invalid.', $fieldLabel),
        );
      }

      return (int) $term->id();
    }

    $newName = trim((string) ($values['new_term'] ?? ''));
    if ($newName === '') {
      throw new \RuntimeException(
        sprintf('No %s taxonomy term was supplied.', $fieldLabel),
      );
    }

    if (!$this->canCreateTerm($createPermission)) {
      throw new \RuntimeException(
        sprintf('You do not have permission to create %s taxonomy terms.', $fieldLabel),
      );
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existingIds = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabulary)
      ->condition('name', $newName)
      ->range(0, 1)
      ->execute();

    if ($existingIds !== []) {
      return (int) reset($existingIds);
    }

    $term = $storage->create([
      'vid' => $vocabulary,
      'name' => $newName,
    ]);
    $term->save();

    return (int) $term->id();
  }

  /**
   * @return array<int, string>
   */
  private function taxonomyOptions(string $vocabulary): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabulary)
      ->sort('weight', 'ASC')
      ->sort('name', 'ASC')
      ->execute();

    if ($ids === []) {
      return [];
    }

    $options = [];
    foreach ($storage->loadMultiple($ids) as $term) {
      $options[(int) $term->id()] = (string) $term->label();
    }

    return $options;
  }

  private function canCreateTerm(string $createPermission): bool {
    return $this->account->hasPermission('administer taxonomy')
      || $this->account->hasPermission($createPermission);
  }

}
