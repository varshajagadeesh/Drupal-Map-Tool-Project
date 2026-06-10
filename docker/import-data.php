<?php

/**
 * @file
 * Imports the repository's example data during the one-click local setup.
 */

use Drupal\secure_location_map\Service\CsvImportService;
use Drupal\secure_location_map\Service\DatasetRepository;

/** @var \Drupal\secure_location_map\Service\DatasetRepository $datasets */
$datasets = \Drupal::service('secure_location_map.dataset_repository');
/** @var \Drupal\secure_location_map\Service\CsvImportService $importer */
$importer = \Drupal::service('secure_location_map.csv_import');

$imports = [
  ['press_forward', '/data/newspaper.csv', 'newspaper', 'replace'],
  ['press_forward', '/data/radio.csv', 'radio', 'append'],
  ['press_forward', '/data/television.csv', 'television', 'append'],
  ['bankcura', '/data/banks.csv', '', 'replace'],
];

foreach ($imports as [$machine_name, $path, $default_type, $mode]) {
  $dataset = $datasets->loadByMachineName($machine_name);
  if (!$dataset) {
    throw new RuntimeException(sprintf('Dataset "%s" was not found.', $machine_name));
  }

  print sprintf("Importing %s into %s...\n", basename($path), $machine_name);
  $summary = $importer->import($path, (int) $dataset['id'], [
    'filename' => basename($path),
    'mode' => $mode,
    'default_type' => $default_type,
    'expose_email' => FALSE,
    'store_original' => FALSE,
    'created_by' => 1,
  ]);
  print sprintf(
    "Finished %s: %d inserted, %d updated, %d failed.\n",
    basename($path),
    $summary['imported'],
    $summary['updated'],
    $summary['failed'],
  );
}

