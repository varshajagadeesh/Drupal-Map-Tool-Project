<?php

namespace Drupal\secure_location_map\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\secure_location_map\Service\DatasetRepository;
use Drupal\secure_location_map\Service\LocationRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the dataset administration dashboard.
 */
class AdminDashboardController extends ControllerBase {

  public function __construct(
    protected DatasetRepository $datasets,
    protected LocationRepository $locations,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('secure_location_map.dataset_repository'),
      $container->get('secure_location_map.location_repository'),
      $container->get('date.formatter'),
    );
  }

  public function dashboard(): array {
    $counts = $this->locations->countsByDataset();
    $imports = $this->locations->latestImportsByDataset();
    $rows = [];
    foreach ($this->datasets->all() as $dataset) {
      $id = (int) $dataset['id'];
      $public_path = (string) $dataset['public_path'];
      $public_url = $public_path !== '' && str_starts_with($public_path, '/') && !str_starts_with($public_path, '//')
        ? Url::fromUserInput($dataset['public_path'])
        : Url::fromRoute('secure_location_map.map', ['dataset_machine_name' => $dataset['machine_name']]);
      $operations = [
        '#type' => 'operations',
        '#links' => [
          'edit' => [
            'title' => $this->t('Edit'),
            'url' => Url::fromRoute('secure_location_map.dataset_edit', ['dataset_id' => $id]),
          ],
          'upload' => [
            'title' => $this->t('Upload CSV'),
            'url' => Url::fromRoute('secure_location_map.csv_upload', ['dataset_id' => $id]),
          ],
        ],
      ];
      $rows[] = [
        'data' => [
          $dataset['label'],
          ['data' => ['#markup' => '<code>' . htmlspecialchars($dataset['machine_name'], ENT_QUOTES, 'UTF-8') . '</code>']],
          $dataset['organization_name'],
          [
            'data' => [
              '#markup' => $dataset['status']
                ? '<span class="slm-admin-status slm-admin-status--active">' . $this->t('Active') . '</span>'
                : '<span class="slm-admin-status slm-admin-status--disabled">' . $this->t('Disabled') . '</span>',
            ],
          ],
          number_format($counts[$id] ?? 0),
          isset($imports[$id]) ? $this->dateFormatter->format($imports[$id], 'short') : $this->t('Never'),
          ['data' => Link::fromTextAndUrl($this->t('Map'), $public_url)->toRenderable()],
          ['data' => Link::fromTextAndUrl($this->t('Report'), Url::fromRoute('secure_location_map.report', ['dataset_machine_name' => $dataset['machine_name']]))->toRenderable()],
          ['data' => $operations],
        ],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['secure-location-map-dashboard']],
      'actions' => [
        '#type' => 'actions',
        'add' => [
          '#type' => 'link',
          '#title' => $this->t('Add dataset'),
          '#url' => Url::fromRoute('secure_location_map.dataset_add'),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Dataset'),
          $this->t('Machine name'),
          $this->t('Organization'),
          $this->t('Status'),
          $this->t('Locations'),
          $this->t('Last import'),
          $this->t('Public map'),
          $this->t('Report'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No datasets exist.'),
      ],
      '#attached' => ['library' => ['secure_location_map/admin']],
    ];
  }

}
