<?php

namespace Drupal\file_keyword_validator\Plugin\QueueWorker;

use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file_keyword_validator\Service\KeywordScannerService;

/**
 * Asynchronous worker for scanning large files.
 */
#[QueueWorker(
  id: 'file_keyword_validator_queue',
  title: new TranslatableMarkup('File Keyword Validator Async Queue Worker'),
  cron: ['time' => 60],
)]
class FileScannerQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected KeywordScannerService $scanner,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('file_keyword_validator.scanner')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (empty($data['file_id']) || empty($data['revision_id'])) {
      return;
    }

    /** @var \Drupal\file\FileInterface $file */
    $file = $this->entityTypeManager->getStorage('file')->load($data['file_id']);
    if (!$file) {
      return;
    }

    $this->scanner->scanFileAndLog($file, $data['revision_id'], $data['state']);
  }

}
