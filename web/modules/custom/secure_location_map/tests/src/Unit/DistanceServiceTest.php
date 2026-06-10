<?php

namespace Drupal\Tests\secure_location_map\Unit;

use Drupal\secure_location_map\Service\DistanceService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests Haversine distance calculation.
 *
 * @group secure_location_map
 */
class DistanceServiceTest extends UnitTestCase {

  public function testPhiladelphiaToNewYorkDistance(): void {
    $distance = (new DistanceService())->miles(39.9526, -75.1652, 40.7128, -74.0060);
    $this->assertEqualsWithDelta(80.5, $distance, 1.0);
  }

  public function testSamePointIsZero(): void {
    $this->assertSame(0.0, (new DistanceService())->miles(40.0, -75.0, 40.0, -75.0));
  }

}

