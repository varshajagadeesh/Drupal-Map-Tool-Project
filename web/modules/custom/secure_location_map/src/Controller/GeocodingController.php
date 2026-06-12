<?php

namespace Drupal\secure_location_map\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\secure_location_map\Service\DatasetRepository;
use Drupal\secure_location_map\Service\GeocodingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves a user-submitted address for distance sorting.
 */
class GeocodingController extends ControllerBase {

  public function __construct(
    protected DatasetRepository $datasets,
    protected GeocodingService $geocoder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('secure_location_map.dataset_repository'),
      $container->get('secure_location_map.geocoding'),
    );
  }

  public function geocode(string $dataset_machine_name, Request $request): JsonResponse {
    if (!$this->datasets->loadByMachineName($dataset_machine_name, TRUE)) {
      return new JsonResponse(['message' => 'The requested map dataset was not found.'], 404);
    }

    $value = $request->query->get('address', '');
    $address = is_scalar($value) ? mb_substr(trim((string) $value), 0, 500) : '';
    $lat_value = $request->query->get('lat', '');
    $lng_value = $request->query->get('lng', '');
    $has_coordinates = is_scalar($lat_value)
      && is_scalar($lng_value)
      && is_numeric($lat_value)
      && is_numeric($lng_value)
      && (float) $lat_value >= -90
      && (float) $lat_value <= 90
      && (float) $lng_value >= -180
      && (float) $lng_value <= 180;

    try {
      if ($address !== '') {
        $result = $this->geocoder->geocode($address);
      }
      elseif ($has_coordinates) {
        $result = $this->geocoder->reverse((float) $lat_value, (float) $lng_value);
      }
      else {
        return new JsonResponse(['message' => 'Enter an address or provide valid coordinates.'], 400);
      }
    }
    catch (\Throwable) {
      return new JsonResponse(['message' => 'The address service is temporarily unavailable.'], 503);
    }
    if (!$result) {
      $message = $address !== ''
        ? 'No matching address was found. Try including a city, state, or ZIP code.'
        : 'No address was found for the current location.';
      return new JsonResponse(['message' => $message], 404);
    }

    $response = new JsonResponse($result);
    $response->headers->set('Cache-Control', 'private, max-age=0, no-store');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

}
