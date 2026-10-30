<?php

namespace Drupal\file_keyword_validator\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin configuration form for manage keywords and scanner thresholds.
 */
class AdminSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['file_keyword_validator.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'file_keyword_validator_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('file_keyword_validator.settings');

    $keywords = $config->get('flagged_keywords') ?? [];

    $form['flagged_keywords'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Flagged Keywords'),
      '#description' => $this->t('Enter one keyword or phrase per line. Scanning is case-insensitive.'),
      '#default_value' => implode("\n", $keywords),
      '#rows' => 10,
    ];

    $form['async_thresholds'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Asynchronous Processing Thresholds'),
    ];

    $form['async_thresholds']['async_threshold_size_mb'] = [
      '#type' => 'number',
      '#title' => $this->t('Max File Size for Synchronous Scanning (MB)'),
      '#description' => $this->t('Files larger than this size will be offloaded to the QueueWorker.'),
      '#default_value' => ($config->get('async_threshold_size') ?? 10485760) / 1048576,
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['async_thresholds']['async_threshold_pages'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Page Count for Synchronous Scanning'),
      '#description' => $this->t('PDF files with more pages than this threshold will be processed asynchronously.'),
      '#default_value' => $config->get('async_threshold_pages') ?? 500,
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $raw_keywords = $form_state->getValue('flagged_keywords');
    $keywords_array = array_filter(
      array_map('trim', explode("\n", $raw_keywords)),
      fn($item) => $item !== ''
    );

    $size_in_bytes = (int) $form_state->getValue('async_threshold_size_mb') * 1048576;

    $this->config('file_keyword_validator.settings')
      ->set('flagged_keywords', array_values($keywords_array))
      ->set('async_threshold_size', $size_in_bytes)
      ->set('async_threshold_pages', (int) $form_state->getValue('async_threshold_pages'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
