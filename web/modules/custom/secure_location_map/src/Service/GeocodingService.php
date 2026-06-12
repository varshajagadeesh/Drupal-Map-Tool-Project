<?php

namespace Drupal\secure_location_map\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;

/**
 * Resolves user-submitted addresses through OpenStreetMap Nominatim.
 */
class GeocodingService {

  public function __construct(
    protected ClientInterface $httpClient,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Returns the first matching address, or NULL when no match is found.
   */
  public function geocode(string $address): ?array {
    $address = trim($address);
    if ($address === '') {
      return NULL;
    }

    $cache_id = 'secure_location_map:geocode:' . hash('sha256', mb_strtolower($address));
    if ($cached = $this->cache->get($cache_id)) {
      return is_array($cached->data) ? $cached->data : NULL;
    }

    $response = $this->httpClient->request('GET', 'https://nominatim.openstreetmap.org/search', [
      'headers' => [
        'Accept' => 'application/json',
        'User-Agent' => 'SecureLocationMapDrupalModule/1.0',
      ],
      'query' => [
        'format' => 'jsonv2',
        'limit' => 1,
        'q' => $address,
      ],
      'timeout' => 10,
    ]);
    $matches = json_decode((string) $response->getBody(), TRUE);
    $match = is_array($matches) ? reset($matches) : NULL;
    if (!is_array($match) || !is_numeric($match['lat'] ?? NULL) || !is_numeric($match['lon'] ?? NULL)) {
      return NULL;
    }

    $result = [
      'lat' => (float) $match['lat'],
      'lng' => (float) $match['lon'],
      'display_name' => mb_substr(trim(strip_tags((string) ($match['display_name'] ?? $address))), 0, 500),
    ];
    $this->cache->set($cache_id, $result, time() + 86400);
    return $result;
  }

  /**
   * Returns the address at the supplied coordinates, or NULL when unavailable.
   */
  public function reverse(float $lat, float $lng): ?array {
    $cache_id = 'secure_location_map:reverse_geocode:' . hash('sha256', round($lat, 5) . ',' . round($lng, 5));
    if ($cached = $this->cache->get($cache_id)) {
      return is_array($cached->data) ? $cached->data : NULL;
    }

    $response = $this->httpClient->request('GET', 'https://nominatim.openstreetmap.org/reverse', [
      'headers' => [
        'Accept' => 'application/json',
        'User-Agent' => 'SecureLocationMapDrupalModule/1.0',
      ],
      'query' => [
        'format' => 'jsonv2',
        'lat' => $lat,
        'lon' => $lng,
        'zoom' => 18,
      ],
      'timeout' => 10,
    ]);
    $match = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($match) || empty($match['display_name'])) {
      return NULL;
    }

    $result = [
      'lat' => $lat,
      'lng' => $lng,
      'display_name' => mb_substr(trim(strip_tags((string) $match['display_name'])), 0, 500),
    ];
    $this->cache->set($cache_id, $result, time() + 86400);
    return $result;
  }

}
