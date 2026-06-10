<?php

namespace Drupal\secure_location_map\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Global module settings form.
 */
class SettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'secure_location_map_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['secure_location_map.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('secure_location_map.settings');
    $form['default_result_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Default API result limit'),
      '#min' => 1,
      '#max' => 10000,
      '#required' => TRUE,
      '#default_value' => $config->get('default_result_limit'),
    ];
    $form['max_api_result_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum API result limit'),
      '#min' => 1,
      '#max' => 50000,
      '#required' => TRUE,
      '#default_value' => $config->get('max_api_result_limit'),
    ];
    $form['enable_marker_clustering'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable marker clustering'),
      '#default_value' => $config->get('enable_marker_clustering'),
    ];
    $form['enable_public_email'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable public email checkbox by default during import'),
      '#default_value' => $config->get('enable_public_email'),
    ];
    $form['default_radius_options'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Radius options in miles'),
      '#description' => $this->t('Comma-separated positive numbers.'),
      '#default_value' => $config->get('default_radius_options'),
      '#required' => TRUE,
    ];
    $form['map_tile_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Map tile URL'),
      '#default_value' => $config->get('map_tile_url'),
      '#required' => TRUE,
    ];
    $form['attribution_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Attribution text'),
      '#default_value' => $config->get('attribution_text'),
      '#required' => TRUE,
    ];
    $form['#attached']['library'][] = 'secure_location_map/admin';
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $default = (int) $form_state->getValue('default_result_limit');
    $max = (int) $form_state->getValue('max_api_result_limit');
    if ($default > $max) {
      $form_state->setErrorByName('default_result_limit', $this->t('The default limit cannot exceed the maximum limit.'));
    }
    foreach (explode(',', (string) $form_state->getValue('default_radius_options')) as $radius) {
      if (!is_numeric(trim($radius)) || (float) $radius <= 0) {
        $form_state->setErrorByName('default_radius_options', $this->t('Every radius option must be a positive number.'));
        break;
      }
    }
    if (!preg_match('/^https?:\/\//i', trim((string) $form_state->getValue('map_tile_url')))) {
      $form_state->setErrorByName('map_tile_url', $this->t('Map tile URL must begin with http:// or https://.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('secure_location_map.settings')
      ->set('default_result_limit', (int) $form_state->getValue('default_result_limit'))
      ->set('max_api_result_limit', (int) $form_state->getValue('max_api_result_limit'))
      ->set('enable_marker_clustering', (bool) $form_state->getValue('enable_marker_clustering'))
      ->set('enable_public_email', (bool) $form_state->getValue('enable_public_email'))
      ->set('default_radius_options', trim((string) $form_state->getValue('default_radius_options')))
      ->set('map_tile_url', trim((string) $form_state->getValue('map_tile_url')))
      ->set('attribution_text', trim((string) $form_state->getValue('attribution_text')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
