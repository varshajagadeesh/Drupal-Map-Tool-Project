<?php

namespace Drupal\secure_location_map\Service;

/**
 * Validates CSV files and normalizes supported row formats.
 */
class CsvValidationService {

  /**
   * Supported source-to-storage column mappings.
   */
  protected const COLUMN_MAP = [
    'cid' => 'external_id',
    'title' => 'name',
    'postal_code' => 'zip',
    'review_rating' => 'rating',
    'open_hours' => 'hours',
  ];

  /**
   * Validates a CSV filename and local path.
   */
  public function validateFile(string $path, string $filename = ''): array {
    $errors = [];
    $name = $filename ?: basename($path);
    if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
      $errors[] = 'Only .csv files are allowed.';
    }
    if (!is_file($path) || !is_readable($path)) {
      $errors[] = 'The CSV file is not readable.';
    }
    if (!$errors && function_exists('finfo_open')) {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $mime = $finfo ? finfo_file($finfo, $path) : FALSE;
      if ($finfo) {
        finfo_close($finfo);
      }
      $allowed_mimes = ['text/csv', 'text/x-csv', 'text/plain', 'application/csv', 'application/x-csv', 'application/vnd.ms-excel', 'application/octet-stream'];
      if ($mime && !in_array($mime, $allowed_mimes, TRUE)) {
        $errors[] = 'The uploaded file does not appear to be CSV text.';
      }
    }
    return $errors;
  }

  /**
   * Detects mappings for normalized lowercase CSV headers.
   */
  public function detectColumnMapping(array $headers): array {
    $mapping = [];
    foreach ($headers as $header) {
      $source = $this->normalizeHeader((string) $header);
      $mapping[$source] = self::COLUMN_MAP[$source] ?? $source;
    }
    return $mapping;
  }

  /**
   * Validates that the detected mapping contains required fields.
   */
  public function validateRequiredMapping(array $mapping): array {
    $targets = array_values($mapping);
    $errors = [];
    foreach (['name', 'latitude', 'longitude'] as $required) {
      if (!in_array($required, $targets, TRUE)) {
        $errors[] = sprintf('Missing required column: %s.', $required);
      }
    }
    return $errors;
  }

  /**
   * Normalizes and validates one associative CSV row.
   */
  public function normalizeRow(array $source_row, array $mapping, array $options = []): array {
    $mapped = [];
    foreach ($source_row as $source => $value) {
      $source = $this->normalizeHeader((string) $source);
      $target = $mapping[$source] ?? $source;
      $mapped[$target] = is_string($value) ? trim($value) : $value;
    }

    $errors = [];
    $warnings = [];
    $metadata = [];
    $name = trim((string) ($mapped['name'] ?? ''));
    $name = (string) preg_replace('/^[\p{P}\p{S}\s]+(?=[\p{L}\p{N}])/u', '', $name);
    if ($name === '') {
      $errors[] = 'Missing name.';
    }
    elseif (!preg_match('/[\p{L}\p{N}]/u', $name)) {
      $errors[] = 'Name must contain at least one letter or number.';
    }
    $latitude = filter_var($mapped['latitude'] ?? NULL, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($mapped['longitude'] ?? NULL, FILTER_VALIDATE_FLOAT);
    if ($latitude === FALSE || $latitude < -90 || $latitude > 90) {
      $errors[] = 'Latitude must be between -90 and 90.';
    }
    if ($longitude === FALSE || $longitude < -180 || $longitude > 180) {
      $errors[] = 'Longitude must be between -180 and 180.';
    }

    $category_value = $source_row['categories'] ?? $mapped['categories'] ?? $mapped['category'] ?? '';
    $categories = $this->parseJsonLike($category_value, 'categories', $metadata, $warnings);
    if ($category_value !== '') {
      $metadata['categories'] = $categories;
    }
    $category_values = is_array($categories) ? array_values(array_filter($categories, 'is_scalar')) : [];
    $category = $category_values ? implode(', ', array_map('strval', array_slice($category_values, 0, 5))) : trim(is_scalar($categories) ? (string) $categories : '');
    $type = $this->inferType(
      (string) ($mapped['type'] ?? ''),
      $category,
      (string) ($options['default_type'] ?? ''),
      (string) ($options['dataset_machine_name'] ?? ''),
    );

    foreach ([
      'attributes',
      'socials',
      'owner_name',
      'description',
      'rating_distribution',
      'plus_code',
      'timezone',
      'popular_times',
      'reservations',
      'order_online',
      'has_menu',
      'created_at',
      'updated_at',
    ] as $field) {
      if (!isset($source_row[$field]) || trim((string) $source_row[$field]) === '') {
        continue;
      }
      $metadata[$field] = in_array($field, ['attributes', 'socials', 'rating_distribution', 'popular_times', 'reservations', 'order_online'], TRUE)
        ? $this->parseJsonLike($source_row[$field], $field, $metadata, $warnings)
        : trim((string) $source_row[$field]);
    }

    if (!empty($options['store_original'])) {
      $metadata['original'] = $source_row;
    }
    $email = '';
    if (!empty($options['expose_email'])) {
      $email = $this->firstEmail($mapped['email'] ?? $source_row['emails'] ?? '');
      if ($email !== '') {
        $metadata['public_email'] = TRUE;
      }
    }
    elseif (!empty($source_row['emails'])) {
      $metadata['emails'] = $this->parseJsonLike($source_row['emails'], 'emails', $metadata, $warnings);
    }
    elseif (!empty($mapped['email'])) {
      $metadata['email_private'] = trim((string) $mapped['email']);
    }

    $row = [
      'external_id' => $this->cleanString($mapped['external_id'] ?? ''),
      'name' => $this->cleanString($name, 255),
      'type' => $this->cleanString($type, 128),
      'category' => $this->cleanString($category, 255),
      'address' => $this->cleanString($mapped['address'] ?? '', 1024),
      'city' => $this->cleanString($mapped['city'] ?? '', 255),
      'state' => $this->cleanString($mapped['state'] ?? '', 255),
      'zip' => $this->normalizeZip($mapped['zip'] ?? ''),
      'country' => $this->cleanString($mapped['country'] ?? '', 128),
      'latitude' => $latitude === FALSE ? 0 : (float) $latitude,
      'longitude' => $longitude === FALSE ? 0 : (float) $longitude,
      'website' => $this->safeUrl($mapped['website'] ?? ''),
      'phone' => $this->cleanString($mapped['phone'] ?? '', 128),
      'email' => $email,
      'hours' => $this->normalizeHours($mapped['hours'] ?? ''),
      'rating' => is_numeric($mapped['rating'] ?? NULL) ? (float) $mapped['rating'] : NULL,
      'review_count' => is_numeric($mapped['review_count'] ?? NULL) ? (int) $mapped['review_count'] : NULL,
      'price_range' => $this->cleanString($mapped['price_range'] ?? '', 128),
      'is_verified' => $this->normalizeBoolean($mapped['is_verified'] ?? NULL),
      'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : NULL,
      'status' => 1,
    ];
    return ['row' => $row, 'errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Normalizes spreadsheet-formatted ZIP codes without losing leading zeros.
   */
  public function normalizeZip(mixed $value): string {
    $zip = trim((string) $value);
    if (preg_match('/^(\d+)\.0+$/', $zip, $matches)) {
      return $matches[1];
    }
    return mb_substr($zip, 0, 32);
  }

  /**
   * Normalizes a header name.
   */
  public function normalizeHeader(string $header): string {
    $header = preg_replace('/^\xEF\xBB\xBF/', '', trim($header));
    return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $header));
  }

  /**
   * Safely parses JSON-like values and retains malformed raw values.
   */
  protected function parseJsonLike(mixed $value, string $field, array &$metadata, array &$warnings): mixed {
    $raw = trim((string) $value);
    if ($raw === '') {
      return '';
    }
    if ($raw[0] !== '[' && $raw[0] !== '{') {
      return $raw;
    }
    $decoded = json_decode($raw, TRUE);
    if (json_last_error() === JSON_ERROR_NONE) {
      return $decoded;
    }
    $metadata[$field . '_raw'] = $raw;
    $warnings[] = sprintf('Malformed JSON in %s; raw value stored in metadata.', $field);
    return $raw;
  }

  /**
   * Infers a supported location type.
   */
  protected function inferType(string $explicit, string $category, string $default, string $dataset): string {
    if ($dataset === 'press_forward' && $default !== '') {
      return strtolower(trim($default));
    }
    if ($explicit !== '') {
      return strtolower(trim($explicit));
    }
    $haystack = strtolower($category);
    $rules = $dataset === 'press_forward'
      ? ['newspaper' => 'newspaper', 'radio' => 'radio', 'television' => 'television']
      : ['credit union' => 'credit_union', 'bank' => 'bank', 'financial institution' => 'bank'];
    foreach ($rules as $needle => $type) {
      if (str_contains($haystack, $needle)) {
        return $type;
      }
    }
    return strtolower(trim($default));
  }

  /**
   * Extracts the first valid email address.
   */
  protected function firstEmail(mixed $value): string {
    $raw = trim((string) $value);
    $decoded = json_decode($raw, TRUE);
    $candidates = is_array($decoded) ? $decoded : preg_split('/[,;\s]+/', $raw);
    foreach ($candidates ?: [] as $candidate) {
      if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
        return mb_substr($candidate, 0, 320);
      }
    }
    return '';
  }

  /**
   * Normalizes hours to text or compact JSON.
   */
  protected function normalizeHours(mixed $value): string {
    $raw = trim((string) $value);
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : $raw;
  }

  /**
   * Returns a safe web URL or an empty string.
   */
  protected function safeUrl(mixed $value): string {
    $url = trim((string) $value);
    return filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $url)
      ? mb_substr($url, 0, 2048)
      : '';
  }

  /**
   * Normalizes a nullable boolean.
   */
  protected function normalizeBoolean(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
  }

  /**
   * Trims and bounds a string.
   */
  protected function cleanString(mixed $value, int $length = 255): string {
    return mb_substr(trim((string) $value), 0, $length);
  }

}
