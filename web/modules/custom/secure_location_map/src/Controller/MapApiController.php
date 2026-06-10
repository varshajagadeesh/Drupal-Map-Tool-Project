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
        'name' => $this->publicText($location['name'], 255),
        'type' => $this->publicText($location['type'], 128),
        'category' => $this->publicText($location['category'], 1000),
        'address' => $this->publicText($location['address'], 1024),
        'city' => $this->publicText($location['city'], 255),
        'state' => $this->publicText($location['state'], 255),
        'zip' => $this->publicText($location['zip'], 32),
        'country' => $this->publicText($location['country'], 128),
        'website' => $this->publicUrl($location['website']),
        'phone' => $this->publicText($location['phone'], 128),
        'hours' => $this->publicText($location['hours'], 5000),
        'rating' => $location['rating'] !== NULL ? (float) $location['rating'] : NULL,
        'review_count' => $location['review_count'] !== NULL ? (int) $location['review_count'] : NULL,
        'price_range' => $this->publicText($location['price_range'], 128),
        'is_verified' => $location['is_verified'] !== NULL ? (bool) $location['is_verified'] : NULL,
        'latitude' => (float) $location['latitude'],
        'longitude' => (float) $location['longitude'],
      ];
      if (isset($location['distance_miles'])) {
        $properties['distance_miles'] = (float) $location['distance_miles'];
      }
      $metadata = json_decode((string) ($location['metadata'] ?? ''), TRUE);
      $metadata = is_array($metadata) ? $metadata : [];
      $properties += [
        'description' => $this->publicText($metadata['description'] ?? '', 5000),
        'categories' => $this->publicList($metadata['categories'] ?? []),
        'attributes' => $this->publicList($metadata['attributes'] ?? []),
        'socials' => $this->publicLinks($metadata['socials'] ?? []),
        'reservation_links' => $this->publicLinks($metadata['reservations'] ?? []),
        'order_online_links' => $this->publicLinks($metadata['order_online'] ?? []),
        'has_menu' => filter_var($metadata['has_menu'] ?? FALSE, FILTER_VALIDATE_BOOLEAN),
        'plus_code' => $this->publicText($metadata['plus_code'] ?? ''),
        'timezone' => $this->publicText($metadata['timezone'] ?? ''),
        'rating_distribution' => $this->publicRatingDistribution($metadata['rating_distribution'] ?? []),
        'source_created_at' => $this->publicText($metadata['created_at'] ?? ''),
        'source_updated_at' => $this->publicText($metadata['updated_at'] ?? ''),
      ];
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

  protected function publicText(mixed $value, int $length = 500): string {
    $text = preg_replace_callback(
      '/\\\\u([0-9a-fA-F]{4})/',
      static fn(array $matches): string => mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UTF-16BE'),
      (string) $value,
    );
    return mb_substr(trim(strip_tags((string) $text)), 0, $length);
  }

  protected function publicUrl(mixed $value): string {
    $url = trim((string) $value);
    return UrlHelper::isValid($url, TRUE) && preg_match('/^https?:\/\//i', $url) ? $url : '';
  }

  protected function publicList(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $items = [];
    foreach ($value as $item) {
      if (!is_scalar($item)) {
        continue;
      }
      $text = $this->publicText($item, 200);
      if ($text !== '') {
        $items[] = $text;
      }
      if (count($items) >= 30) {
        break;
      }
    }
    return array_values(array_unique($items));
  }

  protected function publicLinks(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $links = [];
    foreach ($value as $key => $item) {
      $url = '';
      $label = '';
      if (is_string($item)) {
        $url = $this->publicUrl($item);
        $label = is_string($key) ? $key : '';
      }
      elseif (is_array($item)) {
        $url = $this->publicUrl($item['link'] ?? $item['url'] ?? '');
        $label = $this->publicText($item['source'] ?? $item['name'] ?? $item['title'] ?? '', 100);
      }
      if ($url !== '') {
        $links[] = [
          'label' => $label !== '' ? ucfirst(str_replace('_', ' ', $label)) : parse_url($url, PHP_URL_HOST),
          'url' => $url,
        ];
      }
      if (count($links) >= 20) {
        break;
      }
    }
    return $links;
  }

  protected function publicRatingDistribution(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $distribution = [];
    foreach (['5_star', '4_star', '3_star', '2_star', '1_star'] as $key) {
      if (isset($value[$key]) && is_numeric($value[$key])) {
        $distribution[$key] = max(0, (int) $value[$key]);
      }
    }
    return array_sum($distribution) > 0 ? $distribution : [];
  }

}
