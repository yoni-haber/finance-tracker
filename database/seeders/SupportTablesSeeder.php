<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupportTablesSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->seedSessions();
            $this->seedCacheTables();
            $this->seedJobTables();
        });
    }

    private function seedSessions(): void
    {
        DB::table('sessions')->updateOrInsert(
            ['id' => 'demo-session-1'],
            [
                'user_id' => DB::table('users')->where('email', 'alex@example.com')->value('id'),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Seeder/CLI',
                'payload' => base64_encode('demo-session-payload'),
                'last_activity' => now()->timestamp,
            ],
        );
    }

    private function seedCacheTables(): void
    {
        DB::table('cache')->updateOrInsert(
            ['key' => 'dashboard:alex'],
            [
                'value' => json_encode(['refreshed_at' => now()->toDateTimeString()]),
                'expiration' => now()->addHour()->timestamp,
            ],
        );

        DB::table('cache_locks')->updateOrInsert(
            ['key' => 'reports:lock'],
            [
                'owner' => 'seeder',
                'expiration' => now()->addMinutes(5)->timestamp,
            ],
        );
    }

    private function seedJobTables(): void
    {
        DB::table('job_batches')->updateOrInsert(
            ['id' => 'demo-batch-1'],
            [
                'name' => 'Net worth snapshot batch',
                'total_jobs' => 2,
                'pending_jobs' => 1,
                'failed_jobs' => 0,
                'failed_job_ids' => json_encode([]),
                'options' => json_encode(['notify' => true]),
                'cancelled_at' => null,
                'created_at' => now()->subMinutes(30)->timestamp,
                'finished_at' => null,
            ],
        );
    }
}
