<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CategoryIntegrityAuditor;
use Illuminate\Console\Command;

#[\Illuminate\Console\Attributes\Description('Audit category hierarchy and linked-record integrity without changing data')]
#[\Illuminate\Console\Attributes\Signature('categories:audit')]
final class AuditCategoryIntegrityCommand extends Command
{
    public function handle(CategoryIntegrityAuditor $categoryIntegrityAuditor): int
    {
        $issues = $categoryIntegrityAuditor->issues();

        if ($issues === []) {
            $this->components->info('Category integrity audit passed.');

            return self::SUCCESS;
        }

        $this->components->error('Category integrity problems were found. No records were changed.');
        $this->table(
            ['Problem', 'Affected record IDs'],
            collect($issues)
                ->map(static fn (array $ids, string $issue): array => [$issue, implode(', ', $ids)])
                ->values()
                ->all(),
        );

        return self::FAILURE;
    }
}
