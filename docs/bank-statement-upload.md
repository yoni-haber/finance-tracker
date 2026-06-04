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

### `imported_transactions`
Staging table. Records live here until the user commits the import, then real `Transaction` rows are created.

Key columns: `hash`, `original_hash`, `is_duplicate`, `is_committed`, `category_id` (nullable, no FK).

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

## Queue Processing

### ParseBankStatementJob

| Property | Value |
|---|---|
| Max attempts | 3 (`JOB_MAX_TRIES`) |
| Timeout | 60 s (`JOB_TIMEOUT_SECONDS`) |
| Queue | `default` |

**Dispatch:** `StatementImportManager::uploadStatement()` calls `ParseBankStatementJob::dispatch($import->id)` immediately after storing the CSV and creating the import record.

**Claim mechanism:** The processor atomically transitions the import from `uploaded` (or `failed`) → `parsing` using a single `UPDATE … WHERE status IN ('uploaded', 'failed')`. If that UPDATE affects 0 rows, meaning another worker already holds the claim or the import is in a terminal state, processing is skipped. An import already in `parsing` is treated as actively owned by another worker and is never claimed, preventing concurrent processing.

**Retriable vs non-retriable failures:**
- **Non-retriable** (missing CSV file, missing bank profile): the processor catches these, marks the import `failed`, and returns `false` without throwing. The job completes without triggering a retry.
- **Retriable** (unexpected exceptions): the processor lets these propagate. Laravel retries the job up to 3 times. Once all attempts are exhausted, the job's `failed()` callback marks the import `failed`.

**Atomicity:** Row inserts and the `parsed` status update happen inside a single database transaction. Existing staged rows are deleted first, so re-processing a `failed` import is safe and idempotent.

### Recovering a Stuck Import

An import stuck at `parsing` means a worker died mid-job. Reset it to `uploaded` and re-dispatch - see [setup.md](setup.md#queue) for queue commands and Tinker snippets.

### UI polling
`StatementImportManager` uses a Livewire polling action (`checkImportStatus`) that fires every 2 seconds (`wire:poll.2s`). When the import transitions to `parsed`, the component redirects the user to the review page. If it transitions to `failed`, a red "Failed" badge is shown alongside a Delete Import button - there is no automatic retry; the user must delete the import and re-upload.



## End-to-End Flow

1. User selects a CSV and a bank profile on `/statements/import`.
2. `StatementImportManager::uploadStatement()` stores the file as `statements/{import_id}.csv`, creates a `BankStatementImport` record, and dispatches `ParseBankStatementJob`.
3. The job atomically claims the import (`uploaded` or `failed` → `parsing`) - an import already in `parsing` is skipped to prevent concurrent processing. The job then delegates to `BankStatementImportProcessor`.
4. The processor reads the CSV, parses each row via `TransactionRowParser`, runs `DuplicateDetector::detectDuplicates()`, and bulk-inserts results into `imported_transactions`. Both `hash` and `original_hash` are set to the same value at this point. Import status → `parsed`.
5. The UI polls for status and redirects to `/statements/review/{importId}` on completion.
6. The user can edit transaction details, assign categories, and correct income/expense type. Edits regenerate `hash`; `original_hash` is never touched. **Bulk actions** are available when ≥1 non-duplicate rows are selected: bulk category assignment (all selected must share the same type; chosen category type must match) and bulk delete (requires confirmation). Both clear the selection on success.
7. On commit, `StatementImportCommitter` creates a `Transaction` for each non-duplicate, non-committed staged row, sets `Transaction.hash = original_hash`, marks staged rows `is_committed = true`, updates import status → `committed`, and deletes the CSV file.

## Deduplication

Hash input: `userId|date|amount|description` (SHA-1).

A transaction is flagged as a duplicate if its hash (or `original_hash`) matches either:
- a `Transaction.hash` in the user's permanent history, or
- a `hash` or `original_hash` on any of the user's `imported_transactions`.

Duplicates are stored and shown in the review UI but excluded from the commit.

## Error Handling

| Scenario | Behaviour |
|---|---|
| Malformed CSV row | Row skipped, warning logged, processing continues |
| Missing bank profile | Import marked `failed` immediately (non-retriable) |
| Job exception | Retried up to 3 times; `failed()` callback marks import `failed` |
| Re-upload of same file | All transactions flagged duplicate; commit creates no new records |
| Profile deletion conflict | Blocked if profile is used by any import |

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
