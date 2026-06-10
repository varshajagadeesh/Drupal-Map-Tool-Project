<?php

namespace Drupal\secure_location_map\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Provides dataset persistence and lookup.
 */
class DatasetRepository {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Loads a dataset by numeric ID.
   */
  public function load(int $id): ?array {
    $record = $this->database->select('secure_location_map_dataset', 'd')
      ->fields('d')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return $record ? $this->decode($record) : NULL;
  }

  /**
   * Loads a dataset by machine name.
   */
  public function loadByMachineName(string $machine_name, bool $active_only = FALSE): ?array {
    $query = $this->database->select('secure_location_map_dataset', 'd')
      ->fields('d')
      ->condition('machine_name', $machine_name);
    if ($active_only) {
      $query->condition('status', 1);
    }
    $record = $query->execute()->fetchAssoc();
    return $record ? $this->decode($record) : NULL;
  }

  /**
   * Lists all datasets.
   */
  public function all(bool $active_only = FALSE): array {
    $query = $this->database->select('secure_location_map_dataset', 'd')
      ->fields('d')
      ->orderBy('label');
    if ($active_only) {
      $query->condition('status', 1);
    }
    $datasets = [];
    foreach ($query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC) as $id => $record) {
      $datasets[$id] = $this->decode($record);
    }
    return $datasets;
  }

  /**
   * Creates or updates a dataset.
   */
  public function save(array $values, ?int $id = NULL): int {
    $now = $this->time->getRequestTime();
    $fields = [
      'machine_name' => $values['machine_name'],
      'label' => $values['label'],
      'description' => $values['description'] ?? '',
      'organization_name' => $values['organization_name'],
      'organization_url' => $values['organization_url'] ?? '',
      'default_lat' => (float) $values['default_lat'],
      'default_lng' => (float) $values['default_lng'],
      'default_zoom' => (int) $values['default_zoom'],
      'allowed_types' => json_encode(array_values($values['allowed_types'] ?? [])),
      'public_path' => $values['public_path'] ?? '',
      'marker_colors' => json_encode($values['marker_colors'] ?? []),
      'changed' => $now,
      'status' => !empty($values['status']) ? 1 : 0,
    ];
    if ($id) {
      $this->database->update('secure_location_map_dataset')
        ->fields($fields)
        ->condition('id', $id)
        ->execute();
      return $id;
    }
    $fields['created'] = $now;
    return (int) $this->database->insert('secure_location_map_dataset')
      ->fields($fields)
      ->execute();
  }

  /**
   * Checks machine-name uniqueness, optionally excluding one dataset.
   */
  public function machineNameExists(string $machine_name, ?int $exclude_id = NULL): bool {
    $query = $this->database->select('secure_location_map_dataset', 'd')
      ->condition('machine_name', $machine_name)
      ->countQuery();
    if ($exclude_id) {
      $query->condition('id', $exclude_id, '<>');
    }
    return (bool) $query->execute()->fetchField();
  }

  /**
   * Decodes JSON fields for application use.
   */
  protected function decode(array $record): array {
    foreach (['allowed_types', 'marker_colors'] as $field) {
      $decoded = json_decode((string) ($record[$field] ?? ''), TRUE);
      $record[$field] = is_array($decoded) ? $decoded : [];
    }
    return $record;
  }

}

