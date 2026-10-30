# File Keyword Validator

Scans documents attached to Media items for flagged keywords (for example
"confidential", "restricted", "non-public") and records any matches in the
Media item's **revision log**.

It is an auditing tool, not a gatekeeper: it never blocks an upload, fails
validation, or prevents a content moderation transition. If scanning fails,
the error goes to the `file_keyword_validator` log channel and the save
carries on.

## Requirements

- Drupal 11 with `file`, `media`, `workflows` and `content_moderation`.
- PHP libraries (already in the project's root `composer.json`):
  - `smalot/pdfparser` for PDF
  - `phpoffice/phpword` for Word
  - `phpoffice/phpspreadsheet` for Excel

## Configuration

**Configuration → Media → File Keyword Validator Settings**
(`/admin/config/media/file-keyword-validator`). Requires the
*Administer File Keyword Validator* permission.

| Setting | Default | Meaning |
|---|---|---|
| Flagged keywords | restricted, confidential, non-public | One per line. Matching is case-insensitive substring matching. |
| Max file size for sync scanning | 10 MB | Larger files are scanned in the background. |
| Max page count for sync scanning | 500 | PDFs with more pages are scanned in the background. |
| `enabled_extensions` (config only) | pdf, docx, doc, xlsx, xls, pptx | Files with other extensions are ignored. |

## How it works

```
Media save
  │
  ├─ hook_media_presave()
  │     for each new/changed scannable file:
  │       small file  → scan now, write audit into THIS revision's log   (sync)
  │       large file  → skip (handled after save)
  │
  └─ hook_media_insert() / hook_media_update()
        large file    → add {file_id, revision_id, state} to
                        file_keyword_validator_queue

Queue run (cron or drush)
  └─ FileScannerQueueWorker
        scan file → if matches, add the audit entry to the log of the
                    queued revision, without a new revision             (async)
```

### When a scan happens

A file is scanned when:

- the Media item is **created**,
- a file is **added or replaced** on an existing Media item, or
- the Media item's **moderation state changes** (for example draft →
  published), in which case every file on it is checked again.

Ordinary re-saves that change neither the files nor the moderation state do
not trigger a scan. That is also why the queue worker's own save doesn't start
the scan over again.

Scanning is driven by Media saves only. A file uploaded through the form but
never saved as Media is not scanned.

### Sync vs async

- **Sync**: the file is at or under both limits. It is scanned during the
  request, and the audit entry is added to the revision being saved, after any
  log message the editor wrote.
- **Async**: the file is over the size limit or (for PDFs) the page limit.
  It is queued after the Media item is saved, and the worker later adds the
  audit entry to that same revision, after any log message the editor wrote.
  Until the queue runs, the revision has no audit entry for that file.

### Caching

Results are cached in `cache.default` by the file's SHA-256 hash plus the
current keyword list. Scanning the same file content again (for example on a
moderation transition) reuses the earlier result. Editing the keyword list
means files are scanned again with the new list.

## The audit log message

```
[AUDIT] Flagged keywords detected in file "<filename>": <kw1>, <kw2> [<mode> | <state>]
```

- `<mode>`: `sync` or `async`.
- `<state>`:
  - `transition: draft → published` when the save changed the moderation state,
  - `state: draft` when the Media item is moderated and the state didn't change,
  - `status: published` / `status: unpublished` when the Media type is not in
    a workflow.

Examples:

```
[AUDIT] Flagged keywords detected in file "report.docx": confidential [sync | status: unpublished]
[AUDIT] Flagged keywords detected in file "brief.docx": restricted [sync | transition: draft → published]
[AUDIT] Flagged keywords detected in file "big.pdf": non-public [async | state: draft]
```

These appear on the Media item's **Revisions** tab (`/media/{id}/revisions`).
That page only lists revisions marked `revision_translation_affected`. Core
sets that flag only when a translatable field changes, so the module sets it
itself on revisions it writes a sync entry to. Async entries are written
straight to the revision's log, without an entity save, and rely on the flag
already set by the save that created the revision. This means entity hooks
don't fire for async entries.

Re-scans on moderation transitions only happen for Media types that are added
to a workflow (e.g. *Editorial*, under Configuration → Workflow → Workflows).

## Running the queue

Cron processes the queue automatically, for up to 60 seconds per cron run.
To run it on demand:

```bash
# Process everything in the queue
ddev drush queue:run file_keyword_validator_queue

# See how many items are waiting
ddev drush queue:list

# Limit a run (useful for very large backlogs)
ddev drush queue:run file_keyword_validator_queue --time-limit=300
ddev drush queue:run file_keyword_validator_queue --items-limit=10
```

Drop the `ddev` prefix when running Drush directly on the server.

## Supported formats & known limitations

| Extension | Extracted with | Notes |
|---|---|---|
| pdf | smalot/pdfparser | Scanned or image-only PDFs have no text, so nothing is matched. |
| docx | PhpWord | Only text elements directly inside sections. Text in tables, headers and footers is not read. |
| doc | PhpWord | Legacy `.doc` usually fails to load, which is logged and treated as "no matches". |
| xlsx, xls | PhpSpreadsheet | Every cell's formatted value. |
| pptx | — | Enabled in config but no extractor exists, so it is always "no matches". |

## Files

| Path | Purpose |
|---|---|
| `file_keyword_validator.module` | Media presave, insert and update hooks that decide what to scan and how. |
| `file_keyword_validator.services.yml` | Registers the scanner and text extractor services. |
| `src/Service/KeywordScannerService.php` | Matching, caching, sync/async decision, log formatting, async log write. |
| `src/Service/TextExtractorService.php` | Pulls plain text out of each file format. |
| `src/Plugin/QueueWorker/FileScannerQueueWorker.php` | Background worker for large files. |
| `src/Form/AdminSettingsForm.php` | Settings form. |
| `config/install/file_keyword_validator.settings.yml` | Default settings. |

## Troubleshooting

- **Nothing is logged**: check that the extension is in `enabled_extensions`,
  the file actually contains extractable text, and (for large files) that the
  queue has run. Extraction errors are listed under **Reports → Recent log
  messages**, filtered by type `file_keyword_validator`.
- **After changing code or services**: run `ddev drush cr`.
