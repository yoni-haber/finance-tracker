<?php

namespace App\Livewire\Statements;

use App\Models\BankProfile;
use App\Support\BankStatementConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Bank Profile Manager')]
class BankProfileManager extends Component
{
    public array $form = [];

    public bool $showCreateForm = false;

    public ?BankProfile $editingProfile = null;

    public bool $hasSeparateColumns = false;

    public bool $openCreateModalOnFirstRender = false;

    public function mount(): void
    {
        $this->resetForm();

        if (request()->boolean('create')) {
            $this->editingProfile = null;
            $this->hasSeparateColumns = false;
            $this->showCreateForm = true;
            $this->openCreateModalOnFirstRender = true;
        }
    }

    public function render(): View
    {
        $profiles = BankProfile::where('user_id', Auth::id())->orderBy('name')->get();

        return view('livewire.statements.bank-profile-manager', [
            'profiles' => $profiles,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function rules(): array
    {
        return [
            'form.name' => 'required|string|min:3|max:100',
            'form.statement_type' => 'required|string|in:'.implode(',', BankStatementConfig::VALID_STATEMENT_TYPES),
            'form.date_column' => 'required|integer|min:1',
            'form.description_column' => 'required|integer|min:1',
            'form.amount_column' => $this->hasSeparateColumns ? 'nullable' : 'required|integer|min:1',
            'form.debit_column' => $this->hasSeparateColumns ? 'required|integer|min:1' : 'nullable',
            'form.credit_column' => $this->hasSeparateColumns ? 'required|integer|min:1' : 'nullable',
            'form.date_format' => 'required|string|in:'.implode(',', BankStatementConfig::SUPPORTED_DATE_FORMATS),
            'form.has_header' => 'boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'form.name' => 'profile name',
            'form.statement_type' => 'statement type',
            'form.date_column' => 'date column',
            'form.description_column' => 'description column',
            'form.amount_column' => 'amount column',
            'form.debit_column' => 'debit column',
            'form.credit_column' => 'credit column',
            'form.date_format' => 'date format',
            'form.has_header' => 'has header row',
        ];
    }

    public function showCreate(): void
    {
        $this->resetForm();
        $this->editingProfile = null;
        $this->hasSeparateColumns = false;
        $this->showCreateForm = true;
        $this->openCreateModalOnFirstRender = false;
        $this->dispatch('open-bank-profile-modal');
    }

    public function edit(int $profileId): void
    {
        $profile = BankProfile::where('user_id', Auth::id())->findOrFail($profileId);
        $this->editingProfile = $profile;

        $config = $profile->config;
        $this->hasSeparateColumns = isset($config['columns']['debit']) && isset($config['columns']['credit']);
        $this->form = [
            'name' => $profile->name,
            'statement_type' => $profile->statement_type ?? 'bank',
            'date_column' => ($config['columns']['date'] ?? 0) + 1,
            'description_column' => ($config['columns']['description'] ?? 1) + 1,
            'amount_column' => isset($config['columns']['amount']) ? $config['columns']['amount'] + 1 : null,
            'debit_column' => isset($config['columns']['debit']) ? $config['columns']['debit'] + 1 : null,
            'credit_column' => isset($config['columns']['credit']) ? $config['columns']['credit'] + 1 : null,
            'date_format' => $config['date_format'] ?? 'd/m/Y',
            'has_header' => $config['has_header'] ?? BankStatementConfig::CSV_HAS_HEADER_DEFAULT,
        ];

        $this->showCreateForm = true;
        $this->openCreateModalOnFirstRender = false;
        $this->dispatch('open-bank-profile-modal');
    }

    public function save(): void
    {
        $this->validate();

        // Validate column uniqueness - convert to 1-based for comparison
        $columns = array_filter([
            $this->form['date_column'],
            $this->form['description_column'],
            $this->hasSeparateColumns ? null : $this->form['amount_column'],
            $this->hasSeparateColumns ? $this->form['debit_column'] : null,
            $this->hasSeparateColumns ? $this->form['credit_column'] : null,
        ]);

        if (count($columns) !== count(array_unique($columns))) {
            $this->addError('form.date_column', 'Column numbers must be unique.');

            return;
        }

        // Convert 1-based input to 0-based for storage
        $config = [
            'columns' => array_filter([
                'date' => $this->form['date_column'] - 1,
                'description' => $this->form['description_column'] - 1,
                'amount' => !$this->hasSeparateColumns && $this->form['amount_column'] ? $this->form['amount_column'] - 1 : null,
                'debit' => $this->hasSeparateColumns && $this->form['debit_column'] ? $this->form['debit_column'] - 1 : null,
                'credit' => $this->hasSeparateColumns && $this->form['credit_column'] ? $this->form['credit_column'] - 1 : null,
            ], fn (int|float|null $value): bool => $value !== null),
            'date_format' => $this->form['date_format'],
            'has_header' => (bool) $this->form['has_header'],
        ];

        if ($this->editingProfile instanceof BankProfile) {
            $this->editingProfile->update([
                'name' => $this->form['name'],
                'statement_type' => $this->form['statement_type'],
                'config' => $config,
            ]);
            session()->flash('status', 'Bank profile updated successfully.');
        } else {
            BankProfile::create([
                'user_id' => Auth::id(),
                'name' => $this->form['name'],
                'statement_type' => $this->form['statement_type'],
                'config' => $config,
            ]);
            session()->flash('status', 'Bank profile created successfully.');
        }

        $this->cancel();
    }

    public function delete(int $profileId): void
    {
        $profile = BankProfile::where('user_id', Auth::id())->findOrFail($profileId);

        // Check if profile is being used by any imports
        if ($profile->bankStatementImports()->exists()) {
            $this->addError('delete', 'Cannot delete profile that has been used for imports.');

            return;
        }

        $profile->delete();
        session()->flash('status', 'Bank profile deleted successfully.');
    }

    public function cancel(): void
    {
        $this->showCreateForm = false;
        $this->editingProfile = null;
        $this->hasSeparateColumns = false;
        $this->resetForm();
        $this->resetValidation();
        $this->resetErrorBag();
        $this->openCreateModalOnFirstRender = false;
        $this->dispatch('close-bank-profile-modal');
    }

    private function resetForm(): void
    {
        $this->form = [
            'name' => '',
            'statement_type' => 'bank',
            'date_column' => 1,
            'description_column' => 2,
            'amount_column' => 3,
            'debit_column' => null,
            'credit_column' => null,
            'date_format' => 'd/m/Y',
            'has_header' => BankStatementConfig::CSV_HAS_HEADER_DEFAULT,
        ];
    }
}
