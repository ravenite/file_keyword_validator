# Technical Requirements Specification: Drupal File Keyword Validator Module (`file_keyword_validator`)

**Last updated:** 2026-10-30, to match the implemented module.

Requirements that were specified but are not built yet are kept in this document and marked **Not implemented**. Section 9 lists all of them in one place.

## 1. Overview

This specification details the architecture and functional requirements for a custom Drupal module (`file_keyword_validator`) designed to inspect documents attached to Media items (PDFs and Microsoft Office files) for flagged keywords. The module operates strictly as a non-blocking auditing and workflow-support tool. It targets **PHP 8.3+** and **Drupal 11+**, prioritizing low resource consumption, asynchronous queue processing for large files, and audit logging within Media entity revisions.

## 2. Platform & Compatibility Requirements

* **PHP Version**: `^8.3`
* **Drupal Version**: `^11.0`
* **Machine Name**: `file_keyword_validator`
* **Module Dependencies**: `file`, `media`, `workflows`, `content_moderation`
* **Enforcement Policy**: **Non-blocking**. Identified keywords will not interrupt file uploads, reject entity validation, or prevent content moderation state transitions. All findings are logged directly into Media entity revisions for administrative review.

## 3. Supported File Formats & Libraries

### 3.1 Supported File Types

Files are selected by **file extension**, using the `enabled_extensions` setting, not by MIME type. Only fields of type `file` on a Media item are scanned, which excludes `image` fields. Images, videos, audio files, and plain text uploads are ignored.

| Format | Extension | Enabled by default | Status |
|---|---|---|---|
| PDF | `.pdf` | Yes | Implemented. Scanned or image-only PDFs contain no text and produce no matches. |
| MS Word | `.docx` | Yes | Partial. Only text elements directly inside sections are read. Text in tables, headers and footers is not read. |
| MS Word (legacy) | `.doc` | Yes | Partial. Legacy files usually fail to load, which is logged and treated as no matches. |
| MS Excel | `.xlsx`, `.xls` | Yes | Implemented. The formatted value of every cell is read. |
| MS PowerPoint | `.pptx` | Yes | **Not implemented.** No extractor exists, so the result is always no matches. |
| MS PowerPoint (legacy) | `.ppt` | No | **Not implemented.** |

### 3.2 Parser Dependencies

To avoid abandoned software, the module relies on actively maintained, open-source PHP parsing libraries declared in `composer.json`:

1. **Smalot PDF Parser** (`smalot/pdfparser`): Text extraction and page counting for PDF files.
2. **PHPOffice / PHPWord** (`phpoffice/phpword`): Text extraction from `.doc` and `.docx` files.
3. **PHPOffice / PhpSpreadsheet** (`phpoffice/phpspreadsheet`): Text extraction from `.xls` and `.xlsx` spreadsheets.
4. **PHPOffice / PHPPresentation** (`phpoffice/phppresentation`): **Not implemented.** Intended for `.ppt` and `.pptx`. The library is not a dependency yet.

## 4. Configuration & Administration UI

### 4.1 Settings Form (`/admin/config/media/file-keyword-validator`)

* **Keyword Management**:
  * Textarea with one keyword or phrase per line.
  * Matching is always case-insensitive substring matching, so `restricted` also matches `unrestricted`.
  * Regular expression support: **Not implemented.**
* **Threshold Settings**:
  * **File Size Threshold**: Default `10MB` (stored in bytes as `async_threshold_size`).
  * **Page Count Threshold**: Default `500 pages` (`async_threshold_pages`). Applies to PDFs only.
  * **Memory Limit Cap**: Configurable hard limit per process. **Not implemented.**
* **Configuration Only**:
  * `enabled_extensions`: The file extensions to scan. It has no field on the form.
* **Default Keywords**: `restricted`, `confidential`, `non-public`.
* **Permissions**:
  * Administer file keyword validator settings (`administer file keyword validator`).

## 5. File Fingerprinting & Caching Mechanism

To preserve execution time and minimize server load:

1. **Hashing Algorithm**: SHA-256 hash computed on the binary content of the file.
2. **Cache Key Structure**: `file_keyword_validator:{sha256_hash}:{keyword_list_hash}`, where the keyword list hash is a CRC32b of the current keyword list.
3. **Storage**: The `cache.default` bin, with a permanent lifetime. Rebuilding Drupal's caches clears the results, and the next scan parses the file again.
4. **Execution Logic**:
   * Before parsing, look up the cache key.
   * On a hit, bypass parsing and reuse the cached result. This is what makes a re-scan on a moderation transition cheap.
   * If the file content or the keyword list changes, the key changes, so the file is parsed again on the next trigger. Old entries are not actively removed.
   * An extraction failure produces empty text, so "no matches" is cached for that file and keyword list.

## 6. Event Hook & Queue Processing Architecture

### 6.1 Triggering Mechanisms

* **Trigger Events**: Scanning is driven by Media saves only, through `hook_media_presave()`, `hook_media_insert()` and `hook_media_update()`. File entity hooks are not used, so a file that is uploaded but never saved on a Media item is not scanned.
* **What Gets Scanned**:
  * **New Media**: every scannable file.
  * **Existing Media**: only files added since the previous revision.
  * **Moderation state change**: every scannable file on the Media item, checked again.
* **Change Detection**: A save is compared with **the revision it started from** (`getLoadedRevisionId()`), never with `$media->getOriginal()`. `getOriginal()` returns the default revision, so with a pending draft it would report a state or file change on every save. For large files, that causes an endless loop of queue items and revisions.
* **Deletion Protection**: File deletion hooks (`hook_file_delete()`, `hook_entity_delete()`) are explicitly **excluded**.

### 6.2 Synchronous vs. Asynchronous Evaluation

When a scan is triggered, each file is evaluated by size and, for PDFs, page count:

1. **Synchronous Mode** (File $\le$ 10MB AND $\le$ 500 pages):
   * Execute text parsing during `hook_media_presave()`.
   * Write the audit entry into the revision being saved.
2. **Asynchronous Mode** (File $>$ 10MB OR $>$ 500 pages):
   * After the save, enqueue a job via Drupal Queue API (`file_keyword_validator_queue`).
   * **Queue Item**: `{file_id, revision_id, state}`, where `revision_id` is the revision that triggered the scan and `state` is the moderation state description. The state is captured when queueing because the transition cannot be worked out from the revision once it has been saved.
   * A Queue Worker processes the file in the background via Cron (up to 60 seconds per run) or the Drush queue runner.
   * Until the queue runs, the revision has no audit entry for that file.
   * Dispatching a `FileKeywordScanEvent` on completion: **Not implemented.** The worker writes the log entry directly through `KeywordScannerService`.

## 7. Media Entity Revision Log & Non-Blocking Workflow Integration

### 7.1 Non-Blocking Principle

Under no circumstances will the validator block a file save, throw a form exception, or prevent a content moderation state transition (e.g., transitioning from `Draft` to `Published`). Errors while scanning or queueing are caught and written to the `file_keyword_validator` log channel, and the save carries on.

### 7.2 Revision Logging

1. **One Revision per Save**:
   * A scan must not add a revision of its own. Audit entries go on the revision that triggered the scan, in both modes.
   * If keywords are flagged, append an entry to the Media item's **Revision Log Message** field (`revision_log_message`), after any message the editor wrote.
2. **Log Format**:
   > `[AUDIT] Flagged keywords detected in file "annual_report.pdf": confidential, internal draft [sync | transition: draft → ready_for_review]`

   The final block is `[<mode> | <state>]`:
   * `<mode>`: `sync` or `async`.
   * `<state>`: `transition: <from> → <to>` when the save changed the moderation state, `state: <state>` when a moderated item's state did not change, or `status: published` / `status: unpublished` when the Media type is not in a workflow.
3. **Workflow Transition Support**:
   * Moderation state transitions are detected on Media types assigned to a workflow (e.g., `Editorial`).
   * Each file is checked again during the transition, using the cached result where available.
   * If flagged keywords are still present, the entry is written to the revision created by the transition.
4. **Asynchronous Log Writes**:
   * The worker updates `revision_log_message` directly on the queued revision's row in the revision table, then clears that entity's caches and invalidates its cache tags. It does **not** save the Media entity.
   * This is required because core refuses to save a revision in place after a newer revision has replaced it as the default, which happens during normal editing of unpublished Media.
   * As a result, the write does not fire entity hooks, content moderation, or file usage tracking, and it cannot change which revision is the default. Anything that reacts to Media saves, such as a search index of the log field, does not see async entries.
   * Repeating a queue item must not duplicate the entry. If the revision has been deleted, the item is skipped.
5. **Revisions UI**:
   * `/media/{id}/revisions` only lists revisions marked `revision_translation_affected`. The synchronous path sets this flag on any revision it writes an entry to. The asynchronous path relies on the flag already set by the save that created the revision.

## 8. Performance & Resource Optimization Strategies

* **PHP 8.3 Performance Adjustments**: Leverage explicit type declarations and memory optimizations available in PHP 8.3+.
* **Result Caching**: Implemented as described in section 5.
* **Streaming Reader Interfaces**: Use XML / ZipArchive streaming techniques for MS Office formats rather than loading entire documents into memory. **Not implemented.** Office files are loaded whole by PHPWord and PhpSpreadsheet.
* **PDF Page Chunking**: Analyze PDF documents page-by-page and exit gracefully if memory consumption approaches standard limits. **Not implemented.** PDFs are parsed whole, including for the page count, so a PDF under the size threshold is fully parsed during the request.
* **Execution Time Management**: Reserve `set_time_limit()` overrides exclusively for asynchronous queue workers to protect synchronous web execution timeouts. **Not implemented.** No overrides are set.

## 9. Not Implemented & Known Gaps

| Area | Requirement | Current behavior |
|---|---|---|
| 3.1 | `.pptx` and `.ppt` extraction | `.pptx` is enabled but always gives no matches. `.ppt` is not enabled. |
| 3.1 | Full `.docx` text | Tables, headers and footers are not read. |
| 3.1 | Legacy `.doc` extraction | Usually fails to load, which is logged and treated as no matches. |
| 4.1 | Regular expression keywords | Plain case-insensitive substring matching only. |
| 4.1 | Memory limit cap | No setting exists. |
| 6.2 | `FileKeywordScanEvent` | No event class exists. The worker writes the log directly. |
| 8 | Streaming Office readers | Files are loaded whole. |
| 8 | PDF page chunking and memory exit | PDFs are parsed whole. |
| 8 | `set_time_limit()` in queue workers | Not set. |
| 2 | PHP `^8.3` | `file_keyword_validator.info.yml` declares `php: '8.4'`. |
| 3.2 | Library versions | The module's `composer.json` requires `phpoffice/phpspreadsheet` `^2.0`, while the project requires and installs `^5.10`. |
