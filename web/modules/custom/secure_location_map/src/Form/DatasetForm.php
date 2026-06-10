<?php

namespace Drupal\secure_location_map\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\secure_location_map\Service\DatasetRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Adds and edits map datasets.
 */
class DatasetForm extends FormBase {

  protected ?array $dataset = NULL;

  public function __construct(protected DatasetRepository $datasets) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('secure_location_map.dataset_repository'));
  }

  public function getFormId(): string {
    return 'secure_location_map_dataset_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $dataset_id = NULL): array {
    if ($dataset_id) {
      $this->dataset = $this->datasets->load($dataset_id);
      if (!$this->dataset) {
        throw new NotFoundHttpException();
      }
    }
    $dataset = $this->dataset ?? [];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Map name'),
      '#required' => TRUE,
      '#default_value' => $dataset['label'] ?? '',
      '#maxlength' => 255,
    ];
    $form['machine_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Machine name'),
      '#required' => TRUE,
      '#default_value' => $dataset['machine_name'] ?? '',
      '#machine_name' => ['exists' => [$this, 'machineNameExists']],
      '#disabled' => !empty($dataset),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $dataset['description'] ?? '',
      '#rows' => 3,
    ];
    $form['organization_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization name'),
      '#required' => TRUE,
      '#default_value' => $dataset['organization_name'] ?? '',
      '#maxlength' => 255,
    ];
    $form['organization_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Organization URL'),
      '#default_value' => $dataset['organization_url'] ?? '',
    ];
    $form['map_defaults'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Map defaults'),
    ];
    $form['map_defaults']['default_lat'] = [
      '#type' => 'number',
      '#title' => $this->t('Latitude'),
      '#required' => TRUE,
      '#step' => 0.000001,
      '#min' => -90,
      '#max' => 90,
      '#default_value' => $dataset['default_lat'] ?? 39.8283,
    ];
    $form['map_defaults']['default_lng'] = [
      '#type' => 'number',
      '#title' => $this->t('Longitude'),
      '#required' => TRUE,
      '#step' => 0.000001,
      '#min' => -180,
      '#max' => 180,
      '#default_value' => $dataset['default_lng'] ?? -98.5795,
    ];
    $form['map_defaults']['default_zoom'] = [
      '#type' => 'number',
      '#title' => $this->t('Zoom'),
      '#required' => TRUE,
      '#min' => 1,
      '#max' => 19,
      '#default_value' => $dataset['default_zoom'] ?? 4,
    ];
    $form['allowed_types'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed types'),
      '#description' => $this->t('Enter one per line or separate with commas. Use machine-readable values such as credit_union.'),
      '#required' => TRUE,
      '#default_value' => implode("\n", $dataset['allowed_types'] ?? []),
      '#rows' => 5,
    ];
    $form['public_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public path'),
      '#description' => $this->t('For documentation and dashboard links. The generic route always remains available. Add a Drupal URL alias for custom paths.'),
      '#default_value' => $dataset['public_path'] ?? '',
      '#maxlength' => 255,
      '#field_prefix' => $this->getRequest()->getSchemeAndHttpHost(),
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Active'),
      '#default_value' => $dataset['status'] ?? 1,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save dataset'),
      '#button_type' => 'primary',
    ];
    $form['#attached']['library'][] = 'secure_location_map/admin';
    return $form;
  }

  public function machineNameExists(string $value): bool {
    return $this->datasets->machineNameExists($value, isset($this->dataset['id']) ? (int) $this->dataset['id'] : NULL);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $machine_name = (string) $form_state->getValue('machine_name');
    if (!preg_match('/^[a-z0-9_]+$/', $machine_name)) {
      $form_state->setErrorByName('machine_name', $this->t('Machine name may contain only lowercase letters, numbers, and underscores.'));
    }
    $public_path = trim((string) $form_state->getValue('public_path'));
    if ($public_path !== '' && (!str_starts_with($public_path, '/') || str_starts_with($public_path, '//'))) {
      $form_state->setErrorByName('public_path', $this->t('Public path must begin with one slash.'));
    }
    foreach (preg_split('/[\r\n,]+/', (string) $form_state->getValue('allowed_types')) ?: [] as $type) {
      $type = trim($type);
      if ($type !== '' && !preg_match('/^[a-z0-9_]+$/', $type)) {
        $form_state->setErrorByName('allowed_types', $this->t('Allowed types may contain only lowercase letters, numbers, and underscores.'));
        break;
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $values['machine_name'] = $this->dataset['machine_name'] ?? $values['machine_name'];
    $values['allowed_types'] = array_values(array_unique(array_filter(array_map(
      static fn(string $value): string => strtolower(trim($value)),
      preg_split('/[\r\n,]+/', (string) $values['allowed_types']) ?: [],
    ))));
    $values['marker_colors'] = $this->dataset['marker_colors'] ?? [];
    $id = $this->datasets->save($values, isset($this->dataset['id']) ? (int) $this->dataset['id'] : NULL);
    $this->messenger()->addStatus($this->t('Saved the %label dataset.', ['%label' => $values['label']]));
    $form_state->setRedirectUrl(Url::fromRoute('secure_location_map.dataset_edit', ['dataset_id' => $id]));
  }

}
