<?php

namespace Drupal\secure_location_map\Commands;

use Drupal\secure_location_map\Service\CsvImportService;
use Drupal\secure_location_map\Service\DatasetRepository;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Secure Location Map.
 */
class SecureLocationMapCommands extends DrushCommands {

  public function __construct(
    protected DatasetRepository $datasets,
    protected CsvImportService $importer,
  ) {
    parent::__construct();
  }

  /**
   * Imports a CSV file into a map dataset.
   *
   * @command slm:import
   * @param string $dataset_machine_name
   *   Dataset machine name.
   * @param string $path
   *   Local path to a CSV file.
   * @option type
   *   Default location type for all imported rows.
   * @option mode
   *   Import mode: replace, append, or update.
   * @option expose-email
   *   Expose the first valid email publicly.
   * @option store-original
   *   Store all original columns in metadata.
   * @usage drush slm:import press_forward /path/to/newspaper.csv --type=newspaper --mode=replace
   */
  public function import(string $dataset_machine_name, string $path, array $options = [
    'type' => '',
    'mode' => 'append',
    'expose-email' => FALSE,
    'store-original' => TRUE,
  ]): void {
    $dataset = $this->datasets->loadByMachineName($dataset_machine_name);
    if (!$dataset) {
      throw new \InvalidArgumentException(sprintf('Dataset "%s" does not exist.', $dataset_machine_name));
    }
    $summary = $this->importer->import($path, (int) $dataset['id'], [
      'filename' => basename($path),
      'mode' => $options['mode'],
      'default_type' => $options['type'],
      'expose_email' => (bool) $options['expose-email'],
      'store_original' => (bool) $options['store-original'],
      'created_by' => 0,
    ]);
    $this->logger()->success(sprintf(
      'Import complete: %d inserted, %d updated, %d failed.',
      $summary['imported'],
      $summary['updated'],
      $summary['failed'],
    ));
    foreach (array_slice($summary['errors'], 0, 10) as $error) {
      $this->logger()->warning(sprintf('Row %d: %s', $error['row'], implode(' ', $error['messages'])));
    }
  }

}

