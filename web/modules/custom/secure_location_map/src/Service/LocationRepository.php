<?php

namespace Drupal\secure_location_map\Service;

use Drupal\Core\Database\Connection;

/**
 * Provides dataset-scoped location queries.
 */
class LocationRepository {

  public function __construct(
    protected Connection $database,
    protected DistanceService $distance,
  ) {}

  /**
   * Returns counts keyed by dataset ID.
   */
  public function countsByDataset(): array {
    $query = $this->database->select('secure_location_map_location', 'l');
    $query->addField('l', 'dataset_id');
    $query->addExpression('COUNT(*)', 'location_count');
    $query->condition('status', 1);
    $query->groupBy('dataset_id');
    return array_map('intval', $query->execute()->fetchAllKeyed());
  }

  /**
   * Returns the most recent import timestamp keyed by dataset ID.
   */
  public function latestImportsByDataset(): array {
    $query = $this->database->select('secure_location_map_import', 'i');
    $query->addField('i', 'dataset_id');
    $query->addExpression('MAX(created)', 'last_import');
    $query->groupBy('dataset_id');
    return array_map('intval', $query->execute()->fetchAllKeyed());
  }

  /**
   * Returns the most recent import timestamp for one dataset.
   */
  public function latestImport(int $dataset_id): ?int {
    $query = $this->database->select('secure_location_map_import', 'i');
    $query->addExpression('MAX(created)', 'last_import');
    $value = $query
      ->condition('dataset_id', $dataset_id)
      ->execute()
      ->fetchField();
    return $value ? (int) $value : NULL;
  }

  /**
   * Returns the number of active records in one dataset.
   */
  public function countForDataset(int $dataset_id): int {
    return (int) $this->database->select('secure_location_map_location', 'l')
      ->condition('dataset_id', $dataset_id)
      ->condition('status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Searches active locations, always scoped by dataset ID.
   */
  public function search(int $dataset_id, array $filters = [], int $limit = 500): array {
    $query = $this->database->select('secure_location_map_location', 'l')
      ->fields('l')
      ->condition('dataset_id', $dataset_id)
      ->condition('status', 1);

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
      $like = '%' . $this->database->escapeLike($q) . '%';
      $or = $query->orConditionGroup()
        ->condition('name', $like, 'LIKE')
        ->condition('city', $like, 'LIKE')
        ->condition('zip', $like, 'LIKE')
        ->condition('address', $like, 'LIKE');
      $query->condition($or);
    }
    if (!empty($filters['city'])) {
      $query->condition('city', '%' . $this->database->escapeLike(trim((string) $filters['city'])) . '%', 'LIKE');
    }
    if (!empty($filters['zip'])) {
      $query->condition('zip', trim((string) $filters['zip']));
    }
    if (!empty($filters['type'])) {
      $types = array_values(array_filter(array_map('trim', explode(',', (string) $filters['type']))));
      if ($types) {
        $query->condition('type', $types, 'IN');
      }
    }
    if (!empty($filters['category'])) {
      $query->condition('category', '%' . $this->database->escapeLike(trim((string) $filters['category'])) . '%', 'LIKE');
    }

    $bbox = $this->parseBbox($filters['bbox'] ?? NULL);
    $has_radius = is_numeric($filters['lat'] ?? NULL)
      && is_numeric($filters['lng'] ?? NULL)
      && is_numeric($filters['radius'] ?? NULL)
      && (float) $filters['lat'] >= -90
      && (float) $filters['lat'] <= 90
      && (float) $filters['lng'] >= -180
      && (float) $filters['lng'] <= 180
      && (float) $filters['radius'] > 0
      && (float) $filters['radius'] <= 25000;
    if ($has_radius) {
      $bbox = $this->distance->boundingBox((float) $filters['lat'], (float) $filters['lng'], (float) $filters['radius']);
    }
    if ($bbox) {
      [$west, $south, $east, $north] = $bbox;
      $query->condition('longitude', [$west, $east], 'BETWEEN');
      $query->condition('latitude', [$south, $north], 'BETWEEN');
    }

    $query->orderBy('name');
    $query->range(0, $has_radius ? min($limit * 5, 10000) : $limit);
    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    if ($has_radius) {
      $origin_lat = (float) $filters['lat'];
      $origin_lng = (float) $filters['lng'];
      $radius = (float) $filters['radius'];
      foreach ($results as $key => &$result) {
        $miles = $this->distance->miles($origin_lat, $origin_lng, (float) $result['latitude'], (float) $result['longitude']);
        if ($miles > $radius) {
          unset($results[$key]);
          continue;
        }
        $result['distance_miles'] = round($miles, 2);
      }
      unset($result);
      usort($results, static fn(array $a, array $b): int => ($a['distance_miles'] ?? 0) <=> ($b['distance_miles'] ?? 0));
      $results = array_slice($results, 0, $limit);
    }

    return array_values($results);
  }

  /**
   * Loads a location by external ID within one dataset.
   */
  public function loadByExternalId(int $dataset_id, string $external_id): ?array {
    $record = $this->database->select('secure_location_map_location', 'l')
      ->fields('l')
      ->condition('dataset_id', $dataset_id)
      ->condition('external_id', $external_id)
      ->execute()
      ->fetchAssoc();
    return $record ?: NULL;
  }

  /**
   * Parses west,south,east,north into validated coordinates.
   */
  protected function parseBbox(mixed $value): ?array {
    $parts = is_array($value) ? $value : explode(',', (string) $value);
    if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
      return NULL;
    }
    $bbox = array_map('floatval', $parts);
    if ($bbox[0] < -180 || $bbox[2] > 180 || $bbox[1] < -90 || $bbox[3] > 90 || $bbox[0] > $bbox[2] || $bbox[1] > $bbox[3]) {
      return NULL;
    }
    return $bbox;
  }

}
