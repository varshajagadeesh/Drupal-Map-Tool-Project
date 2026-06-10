<?php

namespace Drupal\secure_location_map\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\secure_location_map\Service\CsvImportService;
use Drupal\secure_location_map\Service\DatasetRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Imports a CSV into a selected dataset.
 */
class CsvUploadForm extends FormBase {

  public function __construct(
    protected DatasetRepository $datasets,
    protected CsvImportService $importer,
    protected FileSystemInterface $fileSystem,
    protected AccountProxyInterface $account,
    protected ConfigFactoryInterface $configFactoryService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('secure_location_map.dataset_repository'),
      $container->get('secure_location_map.csv_import'),
      $container->get('file_system'),
      $container->get('current_user'),
      $container->get('config.factory'),
    );
  }

  public function getFormId(): string {
    return 'secure_location_map_csv_upload_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $dataset_id = NULL): array {
    $selected = $dataset_id ? $this->datasets->load($dataset_id) : NULL;
    if ($dataset_id && !$selected) {
      throw new NotFoundHttpException();
    }
    $datasets = $this->datasets->all();
    $form['dataset_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Dataset'),
      '#required' => TRUE,
      '#options' => array_map(static fn(array $dataset): string => $dataset['label'], $datasets),
      '#default_value' => $dataset_id,
    ];
    $form['csv_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('CSV file'),
      '#required' => TRUE,
      '#upload_location' => 'temporary://secure-location-map-imports',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'csv'],
      ],
      '#description' => $this->t('Required after mapping: name, latitude, and longitude. Only CSV files are accepted.'),
    ];
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Import mode'),
      '#required' => TRUE,
      '#default_value' => 'append',
      '#options' => [
        'replace' => $this->t('Replace all existing locations for this dataset'),
        'append' => $this->t('Append new rows'),
        'update' => $this->t('Update existing rows by external_id; insert when not found'),
      ],
    ];
    $allowed_types = [];
    foreach ($datasets as $dataset) {
      $allowed_types = array_merge($allowed_types, $dataset['allowed_types']);
    }
    $allowed_types = array_values(array_unique($allowed_types));
    $form['default_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Default type'),
      '#empty_option' => $this->t('- Infer from CSV categories -'),
      '#options' => array_combine($allowed_types, array_map(static fn(string $type): string => ucwords(str_replace('_', ' ', $type)), $allowed_types)) ?: [],
      '#description' => $this->t('For another selected dataset, the importer validates this value against that dataset.'),
    ];
    $form['expose_email'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expose the first valid email publicly'),
      '#default_value' => (bool) $this->configFactoryService->get('secure_location_map.settings')->get('enable_public_email'),
    ];
    $form['store_original'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Store original columns in metadata'),
      '#default_value' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import CSV'),
      '#button_type' => 'primary',
    ];
    $form['#attached']['library'][] = 'secure_location_map/admin';
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fids = $form_state->getValue('csv_file');
    $file = $fids ? File::load((int) reset($fids)) : NULL;
    if (!$file) {
      $this->messenger()->addError($this->t('The uploaded CSV could not be loaded.'));
      return;
    }
    $path = $this->fileSystem->realpath($file->getFileUri());
    try {
      $summary = $this->importer->import($path, (int) $form_state->getValue('dataset_id'), [
        'filename' => $file->getFilename(),
        'file_uri' => $file->getFileUri(),
        'mode' => $form_state->getValue('mode'),
        'default_type' => $form_state->getValue('default_type'),
        'expose_email' => (bool) $form_state->getValue('expose_email'),
        'store_original' => (bool) $form_state->getValue('store_original'),
        'created_by' => (int) $this->account->id(),
      ]);
      $this->messenger()->addStatus($this->t('Import complete: @imported inserted, @updated updated, @failed failed.', [
        '@imported' => $summary['imported'],
        '@updated' => $summary['updated'],
        '@failed' => $summary['failed'],
      ]));
      foreach (array_slice($summary['errors'], 0, 20) as $error) {
        $this->messenger()->addWarning($this->t('Row @row: @messages', [
          '@row' => $error['row'],
          '@messages' => implode(' ', $error['messages']),
        ]));
      }
      if (count($summary['errors']) > 20) {
        $this->messenger()->addWarning($this->t('Additional row errors are stored in import history.'));
      }
      $form_state->setRedirectUrl(Url::fromRoute('secure_location_map.admin'));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Import failed: @message', ['@message' => $exception->getMessage()]));
    }
  }

}
