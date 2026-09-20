# Bank Statement Upload

Users upload CSV files exported from their bank or credit card provider. The system parses them asynchronously, stages the transactions for review, deduplicates against existing data, and commits them to the transaction history only after explicit user confirmation.

## Import Lifecycle

```
uploaded → parsing → parsed → committed
                  ↘ failed
```

| Status | Meaning |
|---|---|
| `uploaded` | File stored, job queued |
| `parsing` | Worker is processing the CSV |
| `parsed` | Staged transactions ready for review |
| `failed` | Processing failed (retriable or non-retriable error) |
| `committed` | User confirmed; real transactions created |

## Data Model

### `bank_profiles`
User-owned configuration that tells the parser how to read a specific bank's CSV format.

- `config` (JSON) — 0-based column indices for `date`, `description`, and either `amount` or `debit`/`credit`; also stores `date_format` and `has_header`
- `statement_type` — `bank` (positive = income) or `credit_card` (positive = expense)

> The UI presents 1-based column numbers; the model converts to 0-based on save.

### `bank_statement_imports`
Tracks one upload operation end-to-end.

- `status` — current lifecycle state
- `bank_profile_id` — which profile to parse with
- `statement_type` — copied from the profile at upload time
- `profile_config` — immutable parsing configuration captured at upload time; legacy unprocessed imports snapshot their profile on first processing
- `processing_token` / `processing_started_at` — stable per-job ownership for safe queue retries
- `total_rows`, `valid_rows`, `rejected_rows`, `parse_errors` — strict validation outcome (up to the first 100 row errors)
- `file_cleanup_status` / `file_deleted_at` — durable source-file cleanup state

### `imported_transactions`
Staging table. Records live here until the user commits the import, then real `Transaction` rows are created.

Key columns: `hash`, `original_hash`, `is_duplicate`, `duplicate_reason`, `duplicate_override`, `is_committed`, `category_id` (nullable, no FK).

- **`hash`** — updated if the user edits a transaction's description/amount/date on the review page
- **`original_hash`** — set at parse time from the raw CSV data; never changed. Used as `Transaction.hash` on commit so re-uploading the same file is always detected as a duplicate.

## Key Classes

| Class | Responsibility |
|---|---|
| `BankProfileManager` | Livewire CRUD for bank profiles |
| `StatementImportManager` | File upload, job dispatch, status polling |
| `StatementImportReview` | Review UI: edit, categorise, bulk-categorise, commit, bulk/single delete |
| `ParseBankStatementJob` | Queue job — 3 retries, 60s timeout |
| `BankStatementImportProcessor` | Orchestrates parse → stage pipeline |
| `CsvFileReader` | Reads raw CSV rows via `SplFileObject` |
| `TransactionRowParser` | Maps a CSV row to a transaction array using the bank profile |
| `DuplicateDetector` | Generates hashes and checks for duplicates |
| `StatementImportCommitter` | Creates `Transaction` records and marks the import committed |
| `StatementFileCleaner` | Verifies deletion of uploaded source files and records cleanup state |
| `DeleteStatementFileJob` | Retries a failed committed-import cleanup |
| `SweepStatementFileCleanupJob` | Hourly recovery sweep for committed imports with pending/failed cleanup |

## File Storage

Uploaded CSVs are stored on the **statements disk**, configured by the
`STATEMENTS_DISK` env var (`config/filesystems.php` → `statements_disk`,
default `local`). The web request writes the file and a separate queue worker
reads it back, so in production these must share a disk. On Laravel Cloud the
web and worker run on **separate machines with non-shared, ephemeral local
disks**, so set `STATEMENTS_DISK=s3` (an attached bucket). The processor copies
the file from this disk to a local temp file for `SplFileObject`, then deletes
the temp file. Helpers: `BankStatementConfig::statementsDisk()` and
`BankStatementConfig::statementPath($importId)`.

## Queue Processing

### ParseBankStatementJob

| Property | Value |
|---|---|
| Max attempts | 3 (`JOB_MAX_TRIES`) |
| Timeout | 60 s (`JOB_TIMEOUT_SECONDS`) |
| Queue | `default` |

**Dispatch:** `StatementImportManager::uploadStatement()` calls `ParseBankStatementJob::dispatch($import->id)` immediately after storing the CSV and creating the import record.

**Claim mechanism:** Every dispatched job owns a stable UUID for all of its attempts. The processor atomically transitions `uploaded` → `parsing`, or reclaims `parsing` only when the stored token matches that same job. A duplicate job with another token cannot process the import. Unexpected exceptions leave the token in place so Laravel can safely retry the owning job.

**Retriable vs non-retriable failures:**
- **Non-retriable** (missing CSV file, missing bank profile): the processor catches these, marks the import `failed`, and returns `false` without throwing. The job completes without triggering a retry.
- **Retriable** (unexpected exceptions): the processor lets these propagate. Laravel retries the job up to 3 times. Once all attempts are exhausted, the job's `failed()` callback marks the import `failed`.

**Atomicity:** Row inserts and the `parsed` status update happen inside a single database transaction. Staging is rebuilt by the owning job, so retrying after an exception is idempotent.

### Recovering a Stuck Import

An import stuck at `parsing` means a worker died mid-job. Reset it to `uploaded` and re-dispatch - see [setup.md](setup.md#queue) for queue commands and Tinker snippets.

### UI polling
`StatementImportManager` uses a Livewire polling action (`checkImportStatus`) that fires every 2 seconds (`wire:poll.2s`). When the import transitions to `parsed`, the component redirects the user to the review page. If it transitions to `failed`, a red "Failed" badge is shown alongside a Delete Import button - there is no automatic retry; the user must delete the import and re-upload.



## End-to-End Flow

1. User selects a CSV and a bank profile on `/statements/import`.
2. `StatementImportManager::uploadStatement()` stores the file as `statements/{import_id}.csv`, creates a `BankStatementImport` record, and dispatches `ParseBankStatementJob`.
3. The job atomically claims the import with its stable processing token and delegates to `BankStatementImportProcessor`.
4. The processor lazily reads the CSV twice. The first pass strictly validates every non-blank data row; any invalid date, amount, required value, or an empty statement rejects the whole import and records counts plus the first 100 errors. The second pass detects duplicates in batches and stages rows without loading the whole file into memory. Both `hash` and `original_hash` are set to the same value. Import status → `parsed`.
5. The UI polls for status and redirects to `/statements/review/{importId}` on completion.
6. The 50-row review paginator defaults all committable rows to selected and stores only inclusion/exclusion exceptions, so large imports are never represented as an in-memory ID list. The user can edit details, assign categories, correct income/expense type, deselect rows across pages, and use bulk actions. Type edits accept only `income` or `expense` and clear an incompatible category.
7. On commit, ownership and category type are revalidated, then `StatementImportCommitter` creates a `Transaction` for each selected, non-duplicate, non-committed staged row. It sets `Transaction.hash = original_hash`, marks staged rows committed, and updates the import status. File deletion is attempted after the database commit; failure is recorded and retried by the queue and hourly sweep.

## Deduplication

Hash input: `userId|date|amount|description` (SHA-1).

A transaction is flagged with a reason if its hash (or `original_hash`) matches:
- a `Transaction.hash` in the user's permanent history, or
- a `hash` or `original_hash` on an earlier import, or
- an earlier row in the same upload.

Duplicates are stored and shown in the review UI, excluded by default, and may be explicitly included with the duplicate override. For credit-card profiles, charges remain expenses and credits/refunds remain income throughout parsing, review, and commit.

## Error Handling

| Scenario | Behaviour |
|---|---|
| Malformed CSV row | Entire import rejected; row counts and errors displayed |
| Missing bank profile | Import marked `failed` immediately (non-retriable) |
| Job exception | Retried up to 3 times; `failed()` callback marks import `failed` |
| Re-upload of same file | All transactions flagged duplicate; commit creates no new records |
| Profile deletion conflict | Blocked if profile is used by any import |
| Upload storage failure | Incomplete import is removed after confirming no file remains |
| Cancel storage failure | Import records remain intact and an actionable error is shown |
| Commit cleanup failure | Import remains committed; cleanup is queued and swept hourly |
| Account cleanup failure | Account deletion stops before deleting any database records |

## Routes

| Route | Component |
|---|---|
| `/statements/import` | `StatementImportManager` |
| `/statements/bank-profiles` | `BankProfileManager` |
| `/statements/review/{importId}` | `StatementImportReview` |

## Tests

Feature tests: `BankProfileManagerTest`, `StatementImportManagerTest`, `StatementImportReviewTest`, `ParseBankStatementJobTest`

Unit tests: `BankProfileTest`, `BankStatementImportTest`, `ImportedTransactionTest`, `Support/BankStatementImportProcessorTest`, `Support/DuplicateDetectorTest`, `Support/StatementImportCommitterTest`

```bash
make test f=tests/Feature/StatementImportReviewTest.php
```
