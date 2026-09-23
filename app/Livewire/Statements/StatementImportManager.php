<?php

declare(strict_types=1);

namespace App\Livewire\Statements;

use App\Jobs\ParseBankStatementJob;
use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Support\BankStatementConfig;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
#[Title('Import Statement')]
class StatementImportManager extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $csvFile = null;

    public ?int $bankProfileId = null;

    public ?BankStatementImport $currentImport = null;

    public bool $polling = false;

    public function mount(): void
    {
        // Check for any pending imports for this user
        $this->currentImport = BankStatementImport::forUser((int) Auth::id())
            ->whereIn('status', [
                BankStatementConfig::STATUS_UPLOADED,
                BankStatementConfig::STATUS_PARSING,
                BankStatementConfig::STATUS_PARSED,
            ])
            ->latest()
            ->first();

        // Enable polling if there's an active import that's not yet parsed
        $this->polling = $this->currentImport instanceof BankStatementImport &&
                        ($this->currentImport->isUploaded() || $this->currentImport->isParsing());
    }

    public function render(): View
    {
        $bankProfiles = BankProfile::where('user_id', Auth::id())->orderBy('name')->get();

        return view('livewire.statements.import-manager', [
            'bankProfiles' => $bankProfiles,
        ]);
    }

    /**
     * @return array<string, string[]>
     */
    protected function rules(): array
    {
        return [
            'csvFile' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:' . BankStatementConfig::MAX_FILE_SIZE_KB,
            ],
            'bankProfileId' => [
                'required',
                'exists:bank_profiles,id,user_id,' . Auth::id(),
            ],
        ];
    }

    public function checkImportStatus(): void
    {
        if ($this->currentImport instanceof BankStatementImport) {
            $this->currentImport->refresh();

            // Stop polling once parsing is complete or failed
            if ($this->currentImport->isParsed() || $this->currentImport->isFailed()) {
                $this->polling = false;
            }
        }
    }

    public function uploadStatement(): void
    {
        $this->validate();

        if (!$this->csvFile instanceof TemporaryUploadedFile) {
            // this is defensive as the validation rules ensure that it is either a txt or csv file, so covering this in tests is tricky
            return;
        }

        try {
            // Get the selected bank profile to determine statement type (ensure it belongs to user)
            $bankProfile = BankProfile::where('user_id', Auth::id())->findOrFail($this->bankProfileId);

            // Create the import record
            $import = BankStatementImport::create([
                'user_id' => Auth::id(),
                'original_filename' => $this->csvFile->getClientOriginalName(),
                'status' => BankStatementConfig::STATUS_UPLOADED,
                'bank_profile_id' => $this->bankProfileId,
                'statement_type' => $bankProfile->statement_type,
            ]);

            // Store the file with a predictable name for the parser
            $this->csvFile->storeAs('statements', $import->id . '.csv', BankStatementConfig::statementsDisk());

            // Dispatch the parsing job
            ParseBankStatementJob::dispatch($import->id);

            $this->currentImport = $import;
            $this->polling = true; // Start polling for status updates
            $this->reset(['csvFile', 'bankProfileId']);

            session()->flash('status', 'Bank statement uploaded successfully. Processing will begin shortly.');
        } catch (Exception $exception) {
            logger()->error('Failed to upload bank statement', [
                'user_id' => Auth::id(),
                'bank_profile_id' => $this->bankProfileId,
                'filename' => $this->csvFile->getClientOriginalName(),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            $this->addError('csvFile', 'Failed to upload file. Please try again.');
        }
    }

    public function cancelImport(): void
    {
        if (!$this->currentImport instanceof BankStatementImport || $this->currentImport->isCommitted()) {
            return;
        }

        try {
            // Clean up the stored file if it exists
            Storage::disk(BankStatementConfig::statementsDisk())
                ->delete(BankStatementConfig::statementPath((int) $this->currentImport->id));

            // Delete any imported transactions (staged data)
            $this->currentImport->importedTransactions()->delete();

            // Delete the import record
            $this->currentImport->delete();

            $this->currentImport = null;
            $this->polling = false;

            session()->flash('status', 'Import deleted successfully.');

        } catch (Exception $exception) {
            logger()->error('Failed to delete import', [
                'import_id' => $this->currentImport->id,
                'user_id' => Auth::id(),
                'import_status' => $this->currentImport->status,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            session()->flash('error', 'Failed to delete import. Please try again.');
        }
    }

    public function proceedToReview(): void
    {
        if ($this->currentImport instanceof BankStatementImport && $this->currentImport->isParsed()) {
            $this->redirect(route('statements.review', $this->currentImport->id));
        }
    }
}
