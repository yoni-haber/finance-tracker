<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BankProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class BankProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the first user to assign these sample profiles to
        $firstUser = User::first();

        if ($firstUser === null) {
            return;
        }

        $profiles = [
            [
                'name' => 'UK Bank - Standard Format',
                'statement_type' => 'bank',
                'config' => [
                    'columns' => [
                        'date' => 0,
                        'description' => 1,
                        'amount' => 2,
                    ],
                    'date_format' => 'd/m/Y',
                ],
            ],
            [
                'name' => 'UK Bank - Debit/Credit Format',
                'statement_type' => 'bank',
                'config' => [
                    'columns' => [
                        'date' => 0,
                        'description' => 1,
                        'debit' => 2,
                        'credit' => 3,
                    ],
                    'date_format' => 'd/m/Y',
                ],
            ],
            [
                'name' => 'American Express',
                'statement_type' => 'credit_card',
                'config' => [
                    'columns' => [
                        'date' => 0,
                        'description' => 1,
                        'amount' => 4,
                    ],
                    'date_format' => 'd/m/Y',
                ],
            ],
        ];

        foreach ($profiles as $profile) {
            BankProfile::firstOrCreate(
                ['name' => $profile['name'], 'user_id' => $firstUser->id],
                [
                    'statement_type' => $profile['statement_type'],
                    'config' => $profile['config'],
                ],
            );
        }
    }
}
