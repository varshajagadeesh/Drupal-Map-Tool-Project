<?php

namespace Drupal\secure_location_map\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\secure_location_map\Service\DatasetRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a reusable dataset map block.
 *
 * @Block(
 *   id = "secure_location_map",
 *   admin_label = @Translation("Secure Location Map"),
 *   category = @Translation("Maps")
 * )
 */
class SecureLocationMapBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected DatasetRepository $datasets,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('secure_location_map.dataset_repository'),
      $container->get('config.factory'),
    );
  }

  public function defaultConfiguration(): array {
    return [
      'dataset' => 'press_forward',
      'title_override' => '',
      'default_radius' => '',
      'default_lat' => '',
      'default_lng' => '',
      'default_zoom' => '',
      'show_results' => TRUE,
      'show_filters' => TRUE,
      'show_footer' => TRUE,
      'compact' => FALSE,
    ] + parent::defaultConfiguration();
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $options = [];
    foreach ($this->datasets->all(TRUE) as $dataset) {
      $options[$dataset['machine_name']] = $dataset['label'];
    }
    $form['dataset'] = [
      '#type' => 'select',
      '#title' => $this->t('Dataset'),
      '#required' => TRUE,
      '#options' => $options,
      '#default_value' => $this->configuration['dataset'],
    ];
    $form['title_override'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Map title override'),
      '#default_value' => $this->configuration['title_override'],
    ];
    $form['default_radius'] = [
      '#type' => 'number',
      '#title' => $this->t('Default radius in miles'),
      '#min' => 1,
      '#default_value' => $this->configuration['default_radius'],
    ];
    foreach (['default_lat' => 'Center latitude', 'default_lng' => 'Center longitude', 'default_zoom' => 'Zoom'] as $key => $label) {
      $form[$key] = [
        '#type' => 'number',
        '#title' => $this->t($label),
        '#step' => $key === 'default_zoom' ? 1 : 0.000001,
        '#default_value' => $this->configuration[$key],
      ];
    }
    foreach (['show_results' => 'Show result list', 'show_filters' => 'Show filters', 'show_footer' => 'Show footer/status bar', 'compact' => 'Compact mode'] as $key => $label) {
      $form[$key] = [
        '#type' => 'checkbox',
        '#title' => $this->t($label),
        '#default_value' => $this->configuration[$key],
      ];
    }
    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    foreach (array_keys($this->defaultConfiguration()) as $key) {
      if ($form_state->hasValue($key)) {
        $this->configuration[$key] = $form_state->getValue($key);
      }
    }
  }

  public function build(): array {
    $dataset = $this->datasets->loadByMachineName((string) $this->configuration['dataset'], TRUE);
    if (!$dataset) {
      return ['#markup' => $this->t('The selected map dataset is unavailable.')];
    }
    $organization_url = trim((string) $dataset['organization_url']);
    $dataset['organization_url'] = UrlHelper::isValid($organization_url, TRUE) && preg_match('/^https?:\/\//i', $organization_url)
      ? $organization_url
      : '';
    if ($this->configuration['title_override'] !== '') {
      $dataset['label'] = $this->configuration['title_override'];
    }
    foreach (['default_lat', 'default_lng', 'default_zoom'] as $key) {
      if ($this->configuration[$key] !== '') {
        $dataset[$key] = $this->configuration[$key];
      }
    }
    $settings = $this->configFactory->get('secure_location_map.settings');
    $options = $this->configuration + [
      'radius_options' => array_values(array_filter(array_map('trim', explode(',', (string) $settings->get('default_radius_options'))))),
      'tile_url' => $settings->get('map_tile_url'),
      'attribution' => strip_tags((string) $settings->get('attribution_text')),
      'clustering' => (bool) $settings->get('enable_marker_clustering'),
    ];
    return [
      '#theme' => 'secure_location_map',
      '#dataset' => $dataset,
      '#api_url' => Url::fromRoute('secure_location_map.api', ['dataset_machine_name' => $dataset['machine_name']])->toString(),
      '#geocode_url' => Url::fromRoute('secure_location_map.geocode', ['dataset_machine_name' => $dataset['machine_name']])->toString(),
      '#report_url' => Url::fromRoute('secure_location_map.report', ['dataset_machine_name' => $dataset['machine_name']])->toString(),
      '#options' => $options,
      '#attached' => ['library' => ['secure_location_map/frontend']],
      '#cache' => ['tags' => ['config:secure_location_map.settings'], 'max-age' => 300],
    ];
  }

}
