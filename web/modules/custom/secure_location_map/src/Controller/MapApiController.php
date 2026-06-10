<?php

namespace Drupal\secure_location_map\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Utility\UrlHelper;
use Drupal\secure_location_map\Service\DatasetRepository;
use Drupal\secure_location_map\Service\LocationRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns public-safe GeoJSON for one dataset.
 */
class MapApiController extends ControllerBase {

  public function __construct(
    protected DatasetRepository $datasets,
    protected LocationRepository $locations,
    protected ConfigFactoryInterface $configFactoryService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('secure_location_map.dataset_repository'),
      $container->get('secure_location_map.location_repository'),
      $container->get('config.factory'),
    );
  }

  public function locations(string $dataset_machine_name, Request $request): JsonResponse {
    $dataset = $this->datasets->loadByMachineName($dataset_machine_name, TRUE);
    if (!$dataset) {
      throw new NotFoundHttpException();
    }
    $config = $this->configFactoryService->get('secure_location_map.settings');
    $default = max(1, (int) $config->get('default_result_limit'));
    $max = max($default, (int) $config->get('max_api_result_limit'));
    $requested_limit = $request->query->get('limit', $default);
    $limit = min($max, max(1, is_scalar($requested_limit) ? (int) $requested_limit : $default));
    $filters = [];
    foreach (['q', 'city', 'zip', 'type', 'category', 'lat', 'lng', 'radius', 'bbox'] as $key) {
      $value = $request->query->get($key);
      $filters[$key] = is_scalar($value) ? mb_substr((string) $value, 0, 500) : '';
    }
    $features = [];
    foreach ($this->locations->search((int) $dataset['id'], $filters, $limit) as $location) {
      $properties = [
        'id' => (int) $location['id'],
        'name' => $this->publicText($location['name']),
        'type' => $this->publicText($location['type']),
        'category' => $this->publicText($location['category']),
        'address' => $this->publicText($location['address']),
        'city' => $this->publicText($location['city']),
        'state' => $this->publicText($location['state']),
        'zip' => $this->publicText($location['zip']),
        'website' => $this->publicUrl($location['website']),
        'phone' => $this->publicText($location['phone']),
        'hours' => $this->publicText($location['hours']),
        'rating' => $location['rating'] !== NULL ? (float) $location['rating'] : NULL,
        'review_count' => $location['review_count'] !== NULL ? (int) $location['review_count'] : NULL,
      ];
      if (isset($location['distance_miles'])) {
        $properties['distance_miles'] = (float) $location['distance_miles'];
      }
      $metadata = json_decode((string) ($location['metadata'] ?? ''), TRUE);
      if (!empty($metadata['public_email']) && filter_var($location['email'], FILTER_VALIDATE_EMAIL)) {
        $properties['email'] = $location['email'];
      }
      $features[] = [
        'type' => 'Feature',
        'geometry' => [
          'type' => 'Point',
          'coordinates' => [(float) $location['longitude'], (float) $location['latitude']],
        ],
        'properties' => $properties,
      ];
    }
    $response = new JsonResponse([
      'type' => 'FeatureCollection',
      'features' => $features,
      'meta' => [
        'dataset' => $dataset['machine_name'],
        'visible_count' => count($features),
        'dataset_total' => $this->locations->countForDataset((int) $dataset['id']),
        'last_updated' => $this->locations->latestImport((int) $dataset['id']),
      ],
    ]);
    $response->headers->set('Content-Type', 'application/geo+json');
    $response->headers->set('Cache-Control', 'public, max-age=60, s-maxage=60');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

  protected function publicText(mixed $value): string {
    return trim(strip_tags((string) $value));
  }

  protected function publicUrl(mixed $value): string {
    $url = trim((string) $value);
    return UrlHelper::isValid($url, TRUE) && preg_match('/^https?:\/\//i', $url) ? $url : '';
  }

}
