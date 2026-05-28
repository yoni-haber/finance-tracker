<?php

declare(strict_types=1);

use App\Livewire\Budgets\BudgetManager;
use App\Livewire\Categories\CategoryManager;
use App\Livewire\Dashboard;
use App\Livewire\NetWorth\NetWorthTracker;
use App\Livewire\Reports\ReportsHub;
use App\Livewire\Statements\BankProfileManager;
use App\Livewire\Statements\StatementImportManager;
use App\Livewire\Statements\StatementImportReview;
use App\Livewire\Transactions\TransactionManager;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Livewire\Volt\Volt;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', Dashboard::class)->name('dashboard');
    Route::get('transactions', TransactionManager::class)->name('transactions');
    Route::get('categories', CategoryManager::class)->name('categories');
    Route::get('budgets', BudgetManager::class)->name('budgets');
    Route::get('net-worth', NetWorthTracker::class)->name('net-worth');
    Route::get('reports', ReportsHub::class)->name('reports');

    Route::get('statements/import', StatementImportManager::class)->name('statements.import');
    Route::get('statements/review/{importId}', StatementImportReview::class)->name('statements.review');
    Route::get('statements/bank-profiles', BankProfileManager::class)->name('statements.bank-profiles');

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('profile.edit');
    Volt::route('settings/password', 'settings.password')->name('password.edit');
    Volt::route('settings/appearance', 'settings.appearance')->name('appearance.edit');

    Volt::route('settings/two-factor', 'settings.two-factor')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');
});

require __DIR__.'/auth.php';
