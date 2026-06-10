<?php

namespace Drupal\secure_location_map\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Component\Utility\UrlHelper;
use Drupal\secure_location_map\Service\DatasetRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds public map pages.
 */
class MapPageController extends ControllerBase {

  public function __construct(
    protected DatasetRepository $datasets,
    protected ConfigFactoryInterface $configFactoryService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('secure_location_map.dataset_repository'),
      $container->get('config.factory'),
    );
  }

  public function pressForward(): array {
    return $this->map('press_forward');
  }

  public function bankcura(): array {
    return $this->map('bankcura');
  }

  public function pressForwardTitle(): string {
    return $this->title('press_forward');
  }

  public function bankcuraTitle(): string {
    return $this->title('bankcura');
  }

  public function map(string $dataset_machine_name): array {
    $dataset = $this->loadActive($dataset_machine_name);
    $dataset['organization_url'] = $this->safeOrganizationUrl($dataset['organization_url']);
    return [
      '#theme' => 'secure_location_map',
      '#dataset' => $dataset,
      '#api_url' => Url::fromRoute('secure_location_map.api', ['dataset_machine_name' => $dataset_machine_name])->toString(),
      '#report_url' => Url::fromRoute('secure_location_map.report', ['dataset_machine_name' => $dataset_machine_name])->toString(),
      '#options' => $this->defaultOptions(),
      '#attached' => ['library' => ['secure_location_map/frontend']],
      '#cache' => ['tags' => ['config:secure_location_map.settings'], 'max-age' => 300],
    ];
  }

  public function title(string $dataset_machine_name): string {
    return (string) $this->loadActive($dataset_machine_name)['label'];
  }

  protected function loadActive(string $machine_name): array {
    $dataset = $this->datasets->loadByMachineName($machine_name, TRUE);
    if (!$dataset) {
      throw new NotFoundHttpException();
    }
    return $dataset;
  }

  protected function safeOrganizationUrl(mixed $value): string {
    $url = trim((string) $value);
    return UrlHelper::isValid($url, TRUE) && preg_match('/^https?:\/\//i', $url) ? $url : '';
  }

  protected function defaultOptions(): array {
    $config = $this->configFactoryService->get('secure_location_map.settings');
    return [
      'show_results' => TRUE,
      'show_filters' => TRUE,
      'show_footer' => TRUE,
      'compact' => FALSE,
      'default_radius' => '',
      'radius_options' => array_values(array_filter(array_map('trim', explode(',', (string) $config->get('default_radius_options'))))),
      'tile_url' => $config->get('map_tile_url'),
      'attribution' => strip_tags((string) $config->get('attribution_text')),
      'clustering' => (bool) $config->get('enable_marker_clustering'),
    ];
  }

}
