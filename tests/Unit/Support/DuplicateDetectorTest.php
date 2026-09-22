<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\ImportedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BankStatement\DuplicateDetector;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DuplicateDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_transaction_hash_is_deterministic(): void
    {
        $duplicateDetector = new DuplicateDetector(1);

        $hash1 = $duplicateDetector->generateTransactionHash(1, '2024-01-15', 100.00, 'Coffee Shop');
        $hash2 = $duplicateDetector->generateTransactionHash(1, '2024-01-15', 100.00, 'Coffee Shop');

        $this->assertSame($hash1, $hash2);
    }

    public function test_generate_transaction_hash_differs_for_different_user_ids(): void
    {
        $duplicateDetector = new DuplicateDetector(1);

        $hash1 = $duplicateDetector->generateTransactionHash(1, '2024-01-15', 100.00, 'Coffee Shop');
        $hash2 = $duplicateDetector->generateTransactionHash(2, '2024-01-15', 100.00, 'Coffee Shop');

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_generate_transaction_hash_accepts_carbon_date_same_as_string(): void
    {
        $duplicateDetector = new DuplicateDetector(1);

        $hashFromString = $duplicateDetector->generateTransactionHash(1, '2024-01-15', 100.00, 'Coffee Shop');
        $hashFromCarbon = $duplicateDetector->generateTransactionHash(1, Carbon::parse('2024-01-15'), 100.00, 'Coffee Shop');

        $this->assertSame($hashFromString, $hashFromCarbon);
    }

    public function test_detect_duplicates_marks_unique_hash_as_not_duplicate(): void
    {
        $user = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);

        $transactions = [
            ['date' => '2024-01-15', 'amount' => 100.00, 'description' => 'Unique Transaction'],
        ];

        $transactions = $duplicateDetector->detectDuplicates($transactions);

        $this->assertFalse($transactions[0]['is_duplicate']);
        $this->assertNull($transactions[0]['duplicate_reason']);
        $this->assertArrayHasKey('hash', $transactions[0]);
    }

    public function test_detect_duplicates_marks_as_duplicate_when_hash_matches_transaction(): void
    {
        $user = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);

        $date = '2024-01-15';
        $amount = 100.00;
        $description = 'Coffee Shop';

        $hash = $duplicateDetector->generateTransactionHash($user->id, $date, $amount, $description);
        Transaction::factory()->for($user)->create(['hash' => $hash, 'user_id' => $user->id]);

        $transactions = [
            ['date' => $date, 'amount' => $amount, 'description' => $description],
        ];

        $transactions = $duplicateDetector->detectDuplicates($transactions);

        $this->assertTrue($transactions[0]['is_duplicate']);
        $this->assertSame('existing_transaction', $transactions[0]['duplicate_reason']);
    }

    public function test_detect_duplicates_marks_as_duplicate_when_hash_matches_imported_transaction(): void
    {
        $user = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);

        $date = '2024-01-15';
        $amount = 50.00;
        $description = 'Supermarket';

        $hash = $duplicateDetector->generateTransactionHash($user->id, $date, $amount, $description);

        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();
        ImportedTransaction::factory()->for($import)->create(['hash' => $hash]);

        $transactions = [
            ['date' => $date, 'amount' => $amount, 'description' => $description],
        ];

        $transactions = $duplicateDetector->detectDuplicates($transactions);

        $this->assertTrue($transactions[0]['is_duplicate']);
        $this->assertSame('previous_import', $transactions[0]['duplicate_reason']);
    }

    public function test_detect_duplicates_checks_original_hash_and_ignores_the_current_import(): void
    {
        $user = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);
        $row = ['date' => '2024-01-15', 'amount' => 50.00, 'description' => 'Supermarket'];
        $hash = $duplicateDetector->generateTransactionHash($user->id, $row['date'], $row['amount'], $row['description']);
        $profile = BankProfile::factory()->for($user)->create();
        $current = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();
        ImportedTransaction::factory()->for($current, 'bankStatementImport')->create([
            'hash' => 'edited-hash',
            'original_hash' => $hash,
        ]);

        $withoutExclusion = $duplicateDetector->detectDuplicates([$row]);
        $seenHashes = [];
        $withExclusion = $duplicateDetector->detectDuplicates([$row], $seenHashes, $current->id);

        $this->assertTrue($withoutExclusion[0]['is_duplicate']);
        $this->assertSame('previous_import', $withoutExclusion[0]['duplicate_reason']);
        $this->assertFalse($withExclusion[0]['is_duplicate']);
        $this->assertNull($withExclusion[0]['duplicate_reason']);
    }

    public function test_detect_duplicates_tracks_seen_hashes_across_batches_and_within_a_batch(): void
    {
        $user = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);
        $row = ['date' => '2024-01-15', 'amount' => 50.00, 'description' => 'Repeated'];
        $seenHashes = [];

        $firstBatch = $duplicateDetector->detectDuplicates([$row, $row], $seenHashes);
        $secondBatch = $duplicateDetector->detectDuplicates([$row], $seenHashes);

        $this->assertFalse($firstBatch[0]['is_duplicate']);
        $this->assertNull($firstBatch[0]['duplicate_reason']);
        $this->assertTrue($firstBatch[1]['is_duplicate']);
        $this->assertSame('same_file', $firstBatch[1]['duplicate_reason']);
        $this->assertTrue($secondBatch[0]['is_duplicate']);
        $this->assertSame('same_file', $secondBatch[0]['duplicate_reason']);
        $this->assertSame([$firstBatch[0]['hash'] => true], $seenHashes);
    }

    public function test_existing_transaction_reason_has_priority_over_previous_import_and_seen_hash(): void
    {
        $user = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);
        $row = ['date' => '2024-01-15', 'amount' => 50.00, 'description' => 'Repeated'];
        $hash = $duplicateDetector->generateTransactionHash($user->id, $row['date'], $row['amount'], $row['description']);
        Transaction::factory()->for($user)->create(['hash' => $hash]);
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['hash' => $hash, 'original_hash' => $hash]);
        $seenHashes = [$hash => true];

        $result = $duplicateDetector->detectDuplicates([$row], $seenHashes);

        $this->assertTrue($result[0]['is_duplicate']);
        $this->assertSame('existing_transaction', $result[0]['duplicate_reason']);
    }

    public function test_duplicate_detection_is_scoped_to_the_detector_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);
        $row = ['date' => '2024-01-15', 'amount' => 50.00, 'description' => 'Scoped'];
        $hash = $duplicateDetector->generateTransactionHash($user->id, $row['date'], $row['amount'], $row['description']);
        Transaction::factory()->for($other)->create(['hash' => $hash]);
        $profile = BankProfile::factory()->for($other)->create();
        $import = BankStatementImport::factory()->for($other)->for($profile, 'bankProfile')->create();
        ImportedTransaction::factory()->for($import, 'bankStatementImport')->create(['hash' => $hash, 'original_hash' => $hash]);

        $result = $duplicateDetector->detectDuplicates([$row]);

        $this->assertFalse($result[0]['is_duplicate']);
        $this->assertNull($result[0]['duplicate_reason']);
    }

    public function test_detect_duplicates_handles_empty_collection(): void
    {
        $user = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);

        $transactions = [];

        $transactions = $duplicateDetector->detectDuplicates($transactions);

        $this->assertSame([], $transactions);
    }

    // ─── isDuplicateExcluding ────────────────────────────────────────────────

    public function test_is_duplicate_excluding_returns_false_when_no_match(): void
    {
        $user = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);

        $hash = $duplicateDetector->generateTransactionHash($user->id, '2024-01-15', 99.99, 'Unknown');

        $this->assertFalse($duplicateDetector->isDuplicateExcluding($hash));
    }

    public function test_is_duplicate_excluding_returns_true_when_hash_matches_transaction(): void
    {
        $user = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);

        $hash = $duplicateDetector->generateTransactionHash($user->id, '2024-01-15', 100.00, 'Coffee Shop');
        Transaction::factory()->for($user)->create(['hash' => $hash, 'user_id' => $user->id]);

        $this->assertTrue($duplicateDetector->isDuplicateExcluding($hash));
    }

    public function test_is_duplicate_excluding_returns_true_when_hash_matches_imported_transaction_not_excluded(): void
    {
        $user = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);

        $hash = $duplicateDetector->generateTransactionHash($user->id, '2024-01-15', 50.00, 'Supermarket');

        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();
        $importedTransaction = ImportedTransaction::factory()->for($import)->create(['hash' => $hash]);

        $this->assertTrue($duplicateDetector->isDuplicateExcluding($hash, $importedTransaction->id + 999));
    }

    public function test_is_duplicate_excluding_returns_false_when_only_match_is_excluded_imported_transaction(): void
    {
        $user = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);

        $hash = $duplicateDetector->generateTransactionHash($user->id, '2024-01-15', 50.00, 'Supermarket');

        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();
        $importedTransaction = ImportedTransaction::factory()->for($import)->create(['hash' => $hash]);

        $this->assertFalse($duplicateDetector->isDuplicateExcluding($hash, $importedTransaction->id));
    }

    public function test_is_duplicate_excluding_with_null_exclusion_still_finds_imported_transaction(): void
    {
        $user = User::factory()->create();
        $duplicateDetector = new DuplicateDetector($user->id);

        $hash = $duplicateDetector->generateTransactionHash($user->id, '2024-01-15', 50.00, 'Supermarket');

        $profile = BankProfile::factory()->create();
        $import = BankStatementImport::factory()->for($user)->for($profile, 'bankProfile')->create();
        ImportedTransaction::factory()->for($import)->create(['hash' => $hash]);

        $this->assertTrue($duplicateDetector->isDuplicateExcluding($hash));
    }
}
