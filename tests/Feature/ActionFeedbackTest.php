<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Budgets\BudgetManager;
use App\Livewire\Categories\CategoryManager;
use App\Livewire\NetWorth\NetWorthTracker;
use App\Livewire\Statements\BankProfileManager;
use App\Livewire\Statements\StatementImportManager;
use App\Livewire\Transactions\TransactionManager;
use App\Models\BankProfile;
use App\Models\Budget;
use App\Models\Category;
use App\Models\NetWorthEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Iterator;
use Livewire\Component;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ActionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    /** @return Iterator<string, array{string}> */
    public static function successPages(): Iterator
    {
        yield 'categories' => ['categories'];

        yield 'transactions' => ['transactions'];

        yield 'upcoming payments' => ['upcoming-payments'];

        yield 'budgets' => ['budgets'];

        yield 'net worth' => ['net-worth'];

        yield 'bank profiles' => ['statements.bank-profiles'];

        yield 'statement imports' => ['statements.import'];
    }

    #[DataProvider('successPages')]
    public function test_page_success_messages_are_announced_and_can_be_brought_into_view(string $routeName): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['status' => 'Action completed successfully.'])
            ->get(route($routeName))
            ->assertOk()->assertSee('Action completed successfully.')->assertSeeHtml('data-action-feedback role="status"');
    }

    /** @return Iterator<string, array{string}> */
    public static function errorPages(): Iterator
    {
        yield 'categories' => ['categories'];

        yield 'statement imports' => ['statements.import'];
    }

    #[DataProvider('errorPages')]
    public function test_page_errors_use_accessible_error_feedback(string $routeName): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['error' => 'This action could not be completed.'])
            ->get(route($routeName))
            ->assertOk()->assertSee('This action could not be completed.')->assertSeeHtml('data-action-error role="alert"');
    }

    public function test_budget_copy_results_are_brought_into_view_even_when_nothing_was_copied(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(BudgetManager::class)
            ->call('copyFromPreviousMonth')
            ->assertSee('No budgets found')
            ->assertSeeHtml('data-action-feedback role="status"');
    }

    /** @return Iterator<string, array{class-string<Component>, string, array<string, string>}> */
    public static function validationForms(): Iterator
    {
        yield 'category' => [CategoryManager::class, 'save', []];

        yield 'transaction' => [TransactionManager::class, 'save', []];

        yield 'budget' => [BudgetManager::class, 'save', []];

        yield 'net worth' => [NetWorthTracker::class, 'save', ['date' => '']];

        yield 'bank profile' => [BankProfileManager::class, 'save', []];

        yield 'statement upload' => [StatementImportManager::class, 'uploadStatement', []];
    }

    /**
     * @param class-string<Component> $componentClass
     * @param array<string, string> $formState
     */
    #[DataProvider('validationForms')]
    public function test_form_validation_errors_can_be_focused_and_revealed(string $componentClass, string $method, array $formState): void
    {
        $user = User::factory()->create();
        BankProfile::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test($componentClass)
            ->set($formState)
            ->call($method)
            ->assertHasErrors()
            ->assertSeeHtml('data-action-error role="alert"');
    }

    /** @return Iterator<string, array{class-string<Component>, string, array<string, string>}> */
    public static function editForms(): Iterator
    {
        foreach (self::validationForms() as $name => $form) {
            if ($form[0] !== StatementImportManager::class) {
                yield $name => $form;
            }
        }
    }

    /**
     * @param class-string<Component> $componentClass
     * @param array<string, string> $formState
     */
    #[DataProvider('editForms')]
    public function test_opening_an_edit_form_clears_errors_from_the_previous_form(string $componentClass, string $method, array $formState): void
    {
        $user = User::factory()->create();
        $record = match ($componentClass) {
            CategoryManager::class => Category::factory()->for($user)->create(),
            TransactionManager::class => Transaction::factory()->for($user)->create(),
            BudgetManager::class => Budget::factory()->for($user)->create(),
            NetWorthTracker::class => NetWorthEntry::factory()->for($user)->create(),
            BankProfileManager::class => BankProfile::factory()->for($user)->create(),
            default => throw new LogicException('Unexpected edit form component.'),
        };

        Livewire::actingAs($user)
            ->test($componentClass)
            ->set($formState)
            ->call($method)
            ->assertHasErrors()
            ->call('edit', $record->getKey())
            ->assertHasNoErrors()
            ->assertDontSeeHtml('data-action-error');
    }
}
