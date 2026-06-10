<?php

namespace Drupal\secure_location_map\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Imports validated CSV rows into one selected dataset.
 */
class CsvImportService {

  public function __construct(
    protected Connection $database,
    protected CsvValidationService $validator,
    protected DatasetRepository $datasets,
    protected TimeInterface $time,
  ) {}

  /**
   * Imports a local CSV path and returns an import summary.
   */
  public function import(string $path, int $dataset_id, array $options = []): array {
    $dataset = $this->datasets->load($dataset_id);
    if (!$dataset) {
      throw new \InvalidArgumentException('The selected dataset does not exist.');
    }
    $filename = (string) ($options['filename'] ?? basename($path));
    $file_errors = $this->validator->validateFile($path, $filename);
    if ($file_errors) {
      throw new \InvalidArgumentException(implode(' ', $file_errors));
    }
    $handle = fopen($path, 'rb');
    if (!$handle) {
      throw new \RuntimeException('Unable to open CSV file.');
    }
    $headers = fgetcsv($handle, NULL, ',', '"', '');
    if (!$headers) {
      fclose($handle);
      throw new \InvalidArgumentException('The CSV file has no header row.');
    }
    $headers = array_map([$this->validator, 'normalizeHeader'], $headers);
    if (count($headers) !== count(array_unique($headers))) {
      fclose($handle);
      throw new \InvalidArgumentException('The CSV header contains duplicate column names.');
    }
    $mapping = $this->validator->detectColumnMapping($headers);
    $mapping_errors = $this->validator->validateRequiredMapping($mapping);
    if ($mapping_errors) {
      fclose($handle);
      throw new \InvalidArgumentException(implode(' ', $mapping_errors));
    }

    $mode = in_array($options['mode'] ?? '', ['replace', 'append', 'update'], TRUE) ? $options['mode'] : 'append';
    $summary = ['total' => 0, 'imported' => 0, 'updated' => 0, 'failed' => 0, 'errors' => [], 'warnings' => []];
    $seen_external_ids = [];
    $transaction = $this->database->startTransaction();
    if ($mode === 'replace') {
      $this->database->delete('secure_location_map_location')
        ->condition('dataset_id', $dataset_id)
        ->execute();
    }

    $line = 1;
    while (($values = fgetcsv($handle, NULL, ',', '"', '')) !== FALSE) {
      $line++;
      if ($this->isBlankRow($values)) {
        continue;
      }
      $summary['total']++;
      if (count($values) !== count($headers)) {
        $summary['failed']++;
        $summary['errors'][] = ['row' => $line, 'messages' => ['Column count does not match the header row.']];
        continue;
      }
      $source_row = array_combine($headers, $values);
      $normalized = $this->validator->normalizeRow($source_row, $mapping, [
        'dataset_machine_name' => $dataset['machine_name'],
        'default_type' => $options['default_type'] ?? '',
        'expose_email' => !empty($options['expose_email']),
        'store_original' => !empty($options['store_original']),
      ]);
      if ($normalized['errors']) {
        $summary['failed']++;
        $summary['errors'][] = ['row' => $line, 'messages' => $normalized['errors']];
        continue;
      }
      foreach ($normalized['warnings'] as $warning) {
        $summary['warnings'][] = ['row' => $line, 'message' => $warning];
      }
      $row = $normalized['row'];
      if ($row['type'] !== '' && !in_array($row['type'], $dataset['allowed_types'], TRUE)) {
        $summary['failed']++;
        $summary['errors'][] = ['row' => $line, 'messages' => [sprintf('Type "%s" is not allowed for this dataset.', $row['type'])]];
        continue;
      }
      if ($row['external_id'] !== '') {
        if (isset($seen_external_ids[$row['external_id']])) {
          $summary['failed']++;
          $summary['errors'][] = ['row' => $line, 'messages' => ['Duplicate external_id within this import.']];
          continue;
        }
        $seen_external_ids[$row['external_id']] = TRUE;
      }
      $now = $this->time->getRequestTime();
      $row['dataset_id'] = $dataset_id;
      $row['changed'] = $now;
      if ($mode === 'update' && $row['external_id'] !== '') {
        $existing_id = $this->database->select('secure_location_map_location', 'l')
          ->fields('l', ['id'])
          ->condition('dataset_id', $dataset_id)
          ->condition('external_id', $row['external_id'])
          ->execute()
          ->fetchField();
        if ($existing_id) {
          $this->database->update('secure_location_map_location')
            ->fields($row)
            ->condition('dataset_id', $dataset_id)
            ->condition('id', (int) $existing_id)
            ->execute();
          $summary['updated']++;
          continue;
        }
      }
      $row['created'] = $now;
      $this->database->insert('secure_location_map_location')->fields($row)->execute();
      $summary['imported']++;
    }
    fclose($handle);

    $error_log = ['errors' => $summary['errors'], 'warnings' => $summary['warnings']];
    $this->database->insert('secure_location_map_import')->fields([
      'dataset_id' => $dataset_id,
      'filename' => mb_substr($filename, 0, 255),
      'file_uri' => $options['file_uri'] ?? '',
      'import_mode' => $mode,
      'rows_total' => $summary['total'],
      'rows_imported' => $summary['imported'],
      'rows_updated' => $summary['updated'],
      'rows_failed' => $summary['failed'],
      'errors' => json_encode($error_log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
      'created_by' => (int) ($options['created_by'] ?? 0),
      'created' => $this->time->getRequestTime(),
    ])->execute();
    unset($transaction);
    return $summary;
  }

  /**
   * Returns TRUE when all row cells are blank.
   */
  protected function isBlankRow(array $values): bool {
    foreach ($values as $value) {
      if (trim((string) $value) !== '') {
        return FALSE;
      }
    }
    return TRUE;
  }

}
