<?php

namespace Drupal\secure_location_map\Service;

/**
 * Calculates geographic distances.
 */
class DistanceService {

  /**
   * Returns the Haversine distance in miles.
   */
  public function miles(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earth_radius_miles = 3958.7613;
    $lat_delta = deg2rad($lat2 - $lat1);
    $lng_delta = deg2rad($lng2 - $lng1);
    $a = sin($lat_delta / 2) ** 2
      + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lng_delta / 2) ** 2;
    return $earth_radius_miles * 2 * atan2(sqrt($a), sqrt(1 - $a));
  }

  /**
   * Returns a bounding box around a point for a radius in miles.
   */
  public function boundingBox(float $lat, float $lng, float $radius): array {
    $lat_delta = $radius / 69.0;
    $cos = max(cos(deg2rad($lat)), 0.01);
    $lng_delta = $radius / (69.172 * $cos);
    return [$lng - $lng_delta, $lat - $lat_delta, $lng + $lng_delta, $lat + $lat_delta];
  }

}

