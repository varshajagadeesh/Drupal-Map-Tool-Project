<?php

namespace Drupal\Tests\secure_location_map\Unit;

use Drupal\secure_location_map\Service\CsvValidationService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests CSV mapping and row validation.
 *
 * @group secure_location_map
 */
class CsvValidationServiceTest extends UnitTestCase {

  protected CsvValidationService $validator;

  protected function setUp(): void {
    parent::setUp();
    $this->validator = new CsvValidationService();
  }

  public function testRealHeadersMapToRequiredFields(): void {
    $mapping = $this->validator->detectColumnMapping(['cid', 'title', 'latitude', 'longitude', 'postal_code']);
    $this->assertSame([], $this->validator->validateRequiredMapping($mapping));
    $this->assertSame('external_id', $mapping['cid']);
    $this->assertSame('name', $mapping['title']);
    $this->assertSame('zip', $mapping['postal_code']);
  }

  public function testZipNormalization(): void {
    $this->assertSame('19120', $this->validator->normalizeZip('19120.0'));
    $this->assertSame('02110', $this->validator->normalizeZip('02110'));
  }

  public function testInvalidCoordinatesAreRejected(): void {
    $mapping = $this->validator->detectColumnMapping(['name', 'latitude', 'longitude']);
    $result = $this->validator->normalizeRow([
      'name' => 'Invalid point',
      'latitude' => '91',
      'longitude' => '-181',
    ], $mapping);
    $this->assertCount(2, $result['errors']);
  }

  public function testPressForwardTypeInference(): void {
    $mapping = $this->validator->detectColumnMapping(['title', 'categories', 'latitude', 'longitude']);
    $result = $this->validator->normalizeRow([
      'title' => 'Example station',
      'categories' => '["Radio broadcaster"]',
      'latitude' => '40',
      'longitude' => '-75',
    ], $mapping, ['dataset_machine_name' => 'press_forward']);
    $this->assertSame('radio', $result['row']['type']);
  }

  public function testBankcuraCategoryWinsOverDefaultFallback(): void {
    $mapping = $this->validator->detectColumnMapping(['title', 'categories', 'latitude', 'longitude']);
    $result = $this->validator->normalizeRow([
      'title' => 'Example credit union',
      'categories' => '["Credit union"]',
      'latitude' => '40',
      'longitude' => '-75',
    ], $mapping, ['dataset_machine_name' => 'bankcura', 'default_type' => 'bank']);
    $this->assertSame('credit_union', $result['row']['type']);
  }

}
