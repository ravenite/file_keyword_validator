<?php

namespace Drupal\file_keyword_validator\Service;

use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\content_moderation\ModerationInformationInterface;

/**
 * Service to manage fingerprinting, cache lookups, and keyword matching.
 */
class KeywordScannerService {

  /**
   * SHA-256 fingerprints already calculated in this request, keyed by file ID.
   *
   * @var string[]
   */
  protected array $fingerprints = [];

  /**
   * Scans waiting for the revision ID, keyed by the media being saved.
   */
  protected \WeakMap $deferredScans;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected TextExtractorService $extractor,
    protected CacheBackendInterface $cache,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModerationInformationInterface $moderationInformation,
    protected Connection $database,
  ) {
    $this->deferredScans = new \WeakMap();
  }

  /**
   * Whether the file has an extension enabled for scanning.
   */
  public function isScannable(FileInterface $file): bool {
    $config = $this->configFactory->get('file_keyword_validator.settings');
    $allowed_extensions = $config->get('enabled_extensions') ?? ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt'];
    $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));

    return in_array($extension, $allowed_extensions, TRUE);
  }

  /**
   * Whether the file exceeds the size or page thresholds for inline scanning.
   */
  public function requiresAsync(FileInterface $file): bool {
    $config = $this->configFactory->get('file_keyword_validator.settings');

    if ((int) $file->getSize() > (int) $config->get('async_threshold_size')) {
      return TRUE;
    }

    $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    if ($extension === 'pdf') {
      // An unknown page count goes to the queue, so a PDF is never parsed in
      // the request just to size it.
      $pages = $this->getPageCount($file);
      return $pages === NULL || $pages > (int) $config->get('async_threshold_pages');
    }

    return FALSE;
  }

  /**
   * Returns a PDF's page count, reading the file once per fingerprint.
   */
  protected function getPageCount(FileInterface $file): ?int {
    $hash = $this->getFileFingerprint($file);
    if ($hash === '') {
      return NULL;
    }

    $cid = 'file_keyword_validator:pages:' . $hash;
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $pages = $this->extractor->getPdfPageCount($file);
    $this->cache->set($cid, $pages, CacheBackendInterface::CACHE_PERMANENT);

    return $pages;
  }

  /**
   * Holds scans for a media save until its revision ID is known.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media being saved.
   * @param array[] $scans
   *   Queue item data, with file_id and state.
   */
  public function deferScans(MediaInterface $media, array $scans): void {
    $this->deferredScans[$media] = $scans;
  }

  /**
   * Returns and clears the scans deferred for a media save.
   *
   * @return array[]
   *   Queue item data, with file_id and state.
   */
  public function takeDeferredScans(MediaInterface $media): array {
    $scans = $this->deferredScans[$media] ?? [];
    unset($this->deferredScans[$media]);

    return $scans;
  }

  /**
   * Scan a file for flagged keywords, using the SHA-256 result cache.
   */
  public function scanFile(FileInterface $file): array {
    $config = $this->configFactory->get('file_keyword_validator.settings');
    $keywords = $config->get('flagged_keywords') ?? [];

    if (empty($keywords)) {
      return [];
    }

    $hash = $this->getFileFingerprint($file);
    if ($hash === '') {
      return [];
    }

    // Keyed on the keyword list too, so editing keywords invalidates results.
    $cid = 'file_keyword_validator:' . $hash . ':' . hash('crc32b', implode("\n", $keywords));

    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $text = $this->extractor->extractText($file);
    $matched_keywords = [];

    foreach ($keywords as $kw) {
      if (mb_stripos($text, $kw) !== FALSE) {
        $matched_keywords[] = $kw;
      }
    }

    // Cache permanently until clear/re-file hash change.
    $this->cache->set($cid, $matched_keywords, CacheBackendInterface::CACHE_PERMANENT);

    return $matched_keywords;
  }

  /**
   * Scan a file and record media revision log if keywords are found.
   */
  public function scanFileAndLog(FileInterface $file, int $revision_id, string $state): array {
    $matched_keywords = $this->scanFile($file);

    if (!empty($matched_keywords)) {
      $this->attachRevisionLog($revision_id, $file->getFilename(), $matched_keywords, $state);
    }

    return $matched_keywords;
  }

  /**
   * Build the audit entry written to the revision log.
   */
  public function formatLogEntry(string $filename, array $matches, string $state, string $mode): string {
    return sprintf(
      '[AUDIT] Flagged keywords detected in file "%s": %s [%s | %s]',
      $filename,
      implode(', ', $matches),
      $mode,
      $state
    );
  }

  /**
   * Describes the media's moderation transition/state, or published status.
   */
  public function describeState(MediaInterface $media, ?MediaInterface $previous): string {
    if (!$this->moderationInformation->isModeratedEntity($media)) {
      return 'status: ' . ($media->isPublished() ? 'published' : 'unpublished');
    }

    $state = $media->get('moderation_state')->value;
    $previous_state = $previous?->get('moderation_state')->value;

    if ($previous_state && $previous_state !== $state) {
      return sprintf('transition: %s → %s', $previous_state, $state);
    }
    return 'state: ' . $state;
  }

  /**
   * Returns the revision a media save started from, or NULL for new media.
   *
   * $media->getOriginal() is the default revision, not this revision's
   * predecessor. With a pending draft they differ, so comparing against it
   * reports a state or file change on every save of that draft.
   */
  public function getPreviousRevision(MediaInterface $media): ?MediaInterface {
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('media');
    $revision_id = $media->getLoadedRevisionId();

    return $revision_id ? $storage->loadRevisionUnchanged($revision_id) : NULL;
  }

  /**
   * Adds an audit entry to the log of the revision that was scanned.
   */
  public function attachRevisionLog(int $revision_id, string $filename, array $matches, string $state): void {
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('media');
    /** @var \Drupal\media\MediaInterface $media */
    $media = $storage->loadRevision($revision_id);

    // The revision may have been deleted while the item was queued.
    if (!$media) {
      return;
    }

    $entry = $this->formatLogEntry($filename, $matches, $state, 'async');
    $existing = trim((string) $media->getRevisionLogMessage());

    // A queue item is retried if its lease expires, so don't log it twice.
    if (str_contains($existing, $entry)) {
      return;
    }

    // Only the log changes, so it is written directly. An entity save would
    // run moderation and file usage for nothing, and core refuses to save a
    // revision in place once a newer one has replaced it as the default.
    $entity_type = $media->getEntityType();
    $this->database->update($entity_type->getRevisionTable())
      ->fields([
        $entity_type->getRevisionMetadataKey('revision_log_message') => implode("\n", array_filter([$existing, $entry])),
      ])
      ->condition($entity_type->getKey('revision'), $revision_id)
      ->execute();

    $storage->resetCache([$media->id()]);
    Cache::invalidateTags($media->getCacheTagsToInvalidate());
  }

  /**
   * Flags the revision as affecting its translation.
   *
   * The revisions UI only lists revisions with revision_translation_affected
   * set, which core only sets when a translatable field changes. Audit-only
   * revisions change nothing but the log, so they would otherwise be hidden.
   */
  public function markRevisionAffected(MediaInterface $media): void {
    $media->setRevisionTranslationAffected(TRUE);
    $media->setRevisionTranslationAffectedEnforced(TRUE);
  }

  /**
   * Calculate SHA-256 fingerprint for caching content scans.
   */
  public function getFileFingerprint(FileInterface $file): string {
    if (isset($this->fingerprints[$file->id()])) {
      return $this->fingerprints[$file->id()];
    }

    $real_path = \Drupal::service('file_system')->realpath($file->getFileUri());
    if (!$real_path || !file_exists($real_path)) {
      return '';
    }
    return $this->fingerprints[$file->id()] = hash_file('sha256', $real_path) ?: '';
  }

}
