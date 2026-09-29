<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\Category;
use App\Models\ImportedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BankStatement\DuplicateDetector;
use App\Support\BankStatementConfig;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SampleStatementImportSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'alex@example.com')->firstOrFail();
        $profile = BankProfile::where('user_id', $user->id)->where('name', 'UK Bank - Standard Format')->firstOrFail();
        $detector = new DuplicateDetector($user->id);
        $today = Carbon::now();
        $committedDate = $today->copy()->subMonth()->startOfMonth()->addDays(11)->toDateString();

        $committedImport = BankStatementImport::updateOrCreate(
            ['user_id' => $user->id, 'original_filename' => 'demo-committed-statement.csv'],
            ['status' => BankStatementConfig::STATUS_COMMITTED, 'bank_profile_id' => $profile->id, 'statement_type' => BankStatementConfig::STATEMENT_TYPE_BANK],
        );
        $committedDescription = 'LOCAL BOOKSHOP';
        $committedAmount = -28.75;
        $committedHash = $detector->generateTransactionHash($user->id, $committedDate, $committedAmount, $committedDescription);
        $lifestyleCategory = Category::where('user_id', $user->id)->where('name', 'Entertainment')->firstOrFail();

        $this->storeRow($committedImport, 'committed-1', $committedDate, $committedDescription, $committedAmount, $lifestyleCategory->id, $committedHash, false, true);
        Transaction::updateOrCreate(
            ['user_id' => $user->id, 'source_import_id' => $committedImport->id, 'description' => $committedDescription],
            [
                'date' => $committedDate,
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => abs($committedAmount),
                'category_id' => $lifestyleCategory->id,
                'hash' => $committedHash,
                'is_recurring' => false,
                'frequency' => null,
                'recurring_until' => null,
            ],
        );

        $reviewImport = BankStatementImport::firstOrCreate(
            ['user_id' => $user->id, 'original_filename' => 'demo-review-statement.csv'],
            ['status' => BankStatementConfig::STATUS_PARSED, 'bank_profile_id' => $profile->id, 'statement_type' => BankStatementConfig::STATEMENT_TYPE_BANK],
        );
        if ($reviewImport->isCommitted()) {
            return;
        }

        $reviewImport->update(['status' => BankStatementConfig::STATUS_PARSED, 'bank_profile_id' => $profile->id]);
        $reviewDate = $today->copy()->startOfMonth()->addDays(min(12, $today->day - 1))->toDateString();
        $groceriesCategory = Category::where('user_id', $user->id)->where('name', 'Groceries')->firstOrFail();
        $freelanceCategory = Category::where('user_id', $user->id)->where('name', 'Freelance')->firstOrFail();

        $rows = [
            ['duplicate-1', $committedDate, $committedDescription, $committedAmount, $lifestyleCategory->id, true],
            ['ready-1', $reviewDate, 'NEIGHBOURHOOD MARKET', -46.80, $groceriesCategory->id, false],
            ['attention-1', $reviewDate, 'UNKNOWN CARD PAYMENT', -23.40, null, false],
            ['possible-1', $reviewDate, 'COFFEE KIOSK', -8.50, null, false],
            ['possible-2', $reviewDate, 'COFFEE KIOSK', -8.50, null, false],
            ['income-1', $reviewDate, 'CLIENT INVOICE', 325.00, $freelanceCategory->id, false],
        ];

        foreach ($rows as [$externalId, $date, $description, $amount, $categoryId, $duplicate]) {
            $hash = $detector->generateTransactionHash($user->id, $date, $amount, $description);
            $this->storeRow($reviewImport, $externalId, $date, $description, $amount, $categoryId, $hash, $duplicate, false);
        }
    }

    private function storeRow(BankStatementImport $import, string $externalId, string $date, string $description, float $amount, ?int $categoryId, string $hash, bool $duplicate, bool $committed): void
    {
        ImportedTransaction::updateOrCreate(
            ['import_id' => $import->id, 'external_id' => $externalId],
            [
                'date' => $date,
                'description' => $description,
                'amount' => $amount,
                'category_id' => $categoryId,
                'hash' => $hash,
                'original_hash' => $hash,
                'is_duplicate' => $duplicate,
                'is_committed' => $committed,
            ],
        );
    }
}
