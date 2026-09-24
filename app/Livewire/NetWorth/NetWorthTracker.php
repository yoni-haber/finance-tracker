<?php

declare(strict_types=1);

namespace App\Livewire\NetWorth;

use App\Models\NetWorthEntry;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read int $calculatedNetWorthValue
 * @property-read string $calculatedNetWorth
 * @property-read string $calculatedNetWorthStyle
 * @property-read string $assetTotalFormatted
 * @property-read string $liabilityTotalFormatted
 */
#[Layout('components.layouts.app')]
#[Title('Net Worth')]
class NetWorthTracker extends Component
{
    use WithPagination;

    public string $date;

    /**
     * @var array<mixed, array<string, string>>
     */
    public array $assetLines = [];

    /**
     * @var array<mixed, array<string, string>>
     */
    public array $liabilityLines = [];

    public string $newAssetCategory = '';

    public string $newAssetAmount = '0.00';

    public string $newLiabilityCategory = '';

    public string $newLiabilityAmount = '0.00';

    public ?int $editingAssetIndex = null;

    public ?int $editingLiabilityIndex = null;

    public ?int $entryId = null;

    public ?string $copiedFromDate = null;

    public ?int $deletingEntryId = null;

    public string $deletingEntryDate = '';

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function render(): View
    {
        $entries = NetWorthEntry::where('user_id', Auth::id())
            ->with('lineItems')
            ->orderByDesc('date')
            ->paginate(25);

        return view('livewire.net-worth.tracker', [
            'entries' => $entries,
        ]);
    }

    public function save(): void
    {
        $validated = $this->validate(
            array_merge(
                $this->rules(),
                $this->lineItemRules('assetLines'),
                $this->lineItemRules('liabilityLines'),
            ),
            messages: [
                'assetLines.*.category.required' => 'Asset category is required.',
                'liabilityLines.*.category.required' => 'Liability category is required.',
            ],
        );

        if ($this->copiedFromDate !== null && !$this->entryId) {
            if ($validated['date'] <= $this->copiedFromDate) {
                $this->addError('save', 'Choose a date after the snapshot you copied.');

                return;
            }

            if (NetWorthEntry::where('user_id', Auth::id())->where('date', $validated['date'])->exists()) {
                $this->addError('save', 'A snapshot already exists on this date. Edit that snapshot or choose another date.');

                return;
            }
        }

        $assetTotalPennies = $this->sumLines($validated['assetLines']);
        $liabilityTotalPennies = $this->sumLines($validated['liabilityLines']);

        $data = [
            'user_id' => Auth::id(),
            'date' => $validated['date'],
            'assets' => Money::fromPennies($assetTotalPennies),
            'liabilities' => Money::fromPennies($liabilityTotalPennies),
            'net_worth' => Money::fromPennies($assetTotalPennies - $liabilityTotalPennies),
        ];

        if (!$this->entryId) {
            $existingEntry = NetWorthEntry::where('user_id', Auth::id())
                ->whereDate('date', $data['date'])
                ->first();

            if ($existingEntry) {
                $this->entryId = $existingEntry->id;
            }
        }

        $entry = null;

        if ($this->entryId) {
            $entry = NetWorthEntry::where('user_id', $data['user_id'])->find($this->entryId);

            if (!$entry) {
                $this->addError('save', 'Net worth entry not found.');

                return;
            }
        }

        DB::transaction(function () use (&$entry, $data, $validated): void {
            if ($entry) {
                $entry->update($data);
            } else {
                $entry = NetWorthEntry::create($data);
                $this->entryId = $entry->id;
            }

            $this->syncLineItems($entry, $validated['assetLines'], 'asset');
            $this->syncLineItems($entry, $validated['liabilityLines'], 'liability');
        });

        $this->resetForm();
        $this->resetPage();
        session()->flash('status', 'Net worth entry saved.');
        $this->dispatch('close-networth-modal');
    }

    public function openModal(): void
    {
        $this->resetForm();
        $this->dispatch('open-networth-modal');
    }

    public function copyPreviousSnapshot(): void
    {
        if ($this->entryId !== null) {
            return;
        }

        $validated = $this->validate(['date' => $this->rules()['date']]);
        $this->resetErrorBag(['copy', 'save']);

        $source = NetWorthEntry::where('user_id', Auth::id())
            ->with('lineItems')
            ->where('date', '<', $validated['date'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if (!$source) {
            $this->addError('copy', 'No earlier snapshot is available for this date.');

            return;
        }

        $this->fillLinesFromEntry($source);
        $this->copiedFromDate = $source->date->toDateString();
        $this->editingAssetIndex = null;
        $this->editingLiabilityIndex = null;
    }

    public function edit(int $entryId): void
    {
        $entry = NetWorthEntry::where('user_id', Auth::id())->with('lineItems')->findOrFail($entryId);

        $this->copiedFromDate = null;
        $this->entryId = $entry->id;
        $this->date = $entry->date->toDateString();
        $this->fillLinesFromEntry($entry);

        $this->dispatch('open-networth-modal');
    }

    private function fillLinesFromEntry(NetWorthEntry $netWorthEntry): void
    {
        $assetLines = $netWorthEntry->lineItems
            ->where('type', 'asset')
            ->map(fn ($item): array => [
                'category' => $item->category,
                'amount' => $item->amount,
            ])->values()->all();

        $liabilityLines = $netWorthEntry->lineItems
            ->where('type', 'liability')
            ->map(fn ($item): array => [
                'category' => $item->category,
                'amount' => $item->amount,
            ])->values()->all();

        $this->assetLines = $assetLines ?: [[
            'category' => 'Assets',
            'amount' => $netWorthEntry->assets,
        ]];

        $this->liabilityLines = $liabilityLines ?: [[
            'category' => 'Liabilities',
            'amount' => $netWorthEntry->liabilities,
        ]];
    }

    public function confirmDelete(int $entryId): void
    {
        $entry = NetWorthEntry::where('user_id', Auth::id())->findOrFail($entryId);

        $this->deletingEntryId = $entry->id;
        $this->deletingEntryDate = $entry->date->format('j M Y');
        $this->dispatch('open-delete-networth-modal');
    }

    public function delete(): void
    {
        if (!$this->deletingEntryId) {
            return;
        }

        NetWorthEntry::where('user_id', Auth::id())
            ->where('id', $this->deletingEntryId)
            ->delete();

        $this->deletingEntryId = null;
        $this->deletingEntryDate = '';
        $this->resetPage();
        session()->flash('status', 'Net worth entry removed.');
        $this->dispatch('close-delete-networth-modal');
    }

    #[Computed]
    public function calculatedNetWorth(): string
    {
        return Money::formatPennies($this->calculatedNetWorthValue);
    }

    #[Computed]
    public function calculatedNetWorthValue(): int
    {
        return $this->assetTotal() - $this->liabilityTotal();
    }

    #[Computed]
    public function assetTotalFormatted(): string
    {
        return Money::formatPennies($this->assetTotal());
    }

    #[Computed]
    public function liabilityTotalFormatted(): string
    {
        return Money::formatPennies($this->liabilityTotal());
    }

    #[Computed]
    public function calculatedNetWorthStyle(): string
    {
        return $this->calculatedNetWorthValue >= 0
            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
            : 'bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-300';
    }

    public function resetForm(): void
    {
        $this->entryId = null;
        $this->copiedFromDate = null;
        $this->assetLines = [];
        $this->liabilityLines = [];
        $this->newAssetCategory = '';
        $this->newAssetAmount = '0.00';
        $this->newLiabilityCategory = '';
        $this->newLiabilityAmount = '0.00';
        $this->editingAssetIndex = null;
        $this->editingLiabilityIndex = null;
        $this->date = now()->toDateString();

        $this->resetValidation();
        $this->resetErrorBag();
    }

    /**
     * @return array<string, string>
     */
    protected function rules(): array
    {
        return [
            'date' => 'required|date',
        ];
    }

    /** @return string[] */
    private function lineItemRules(string $property): array
    {
        return [
            $property => 'array',
            $property . '.*.category' => 'required|string|max:255',
            $property . '.*.amount' => 'required|numeric|min:0|decimal:0,2',
        ];
    }

    /**
     * @param array<mixed, array<string, string>> $lines
     */
    private function sumLines(array $lines): int
    {
        return collect($lines)
            ->sum(fn ($line): int => Money::normalize($line['amount']));
    }

    /**
     * @param array<mixed, array<string, string>> $lines
     */
    private function syncLineItems(NetWorthEntry $netWorthEntry, array $lines, string $type): void
    {
        $netWorthEntry->lineItems()->where('type', $type)->delete();

        $payload = collect($lines)
            ->filter(fn ($line): bool => trim($line['category']) !== '')
            ->map(fn ($line): array => [
                'user_id' => Auth::id(),
                'type' => $type,
                'category' => trim($line['category']),
                'amount' => Money::fromPennies(Money::normalize($line['amount'])),
            ])->all();

        if ($payload) {
            $netWorthEntry->lineItems()->createMany($payload);
        }
    }

    private function assetTotal(): int
    {
        return $this->sumLines($this->assetLines);
    }

    private function liabilityTotal(): int
    {
        return $this->sumLines($this->liabilityLines);
    }

    public function addAssetLine(): void
    {
        $this->resetErrorBag(['newAssetCategory', 'newAssetAmount']);

        $validated = $this->validate([
            'newAssetCategory' => 'required|string|max:255',
            'newAssetAmount' => 'required|numeric|min:0|decimal:0,2',
        ]);

        $this->assetLines[] = [
            'category' => trim((string) $validated['newAssetCategory']),
            'amount' => Money::fromPennies(Money::normalize((string) $validated['newAssetAmount'])),
        ];

        $this->newAssetCategory = '';
        $this->newAssetAmount = '0.00';
    }

    public function addLiabilityLine(): void
    {
        $this->resetErrorBag(['newLiabilityCategory', 'newLiabilityAmount']);

        $validated = $this->validate([
            'newLiabilityCategory' => 'required|string|max:255',
            'newLiabilityAmount' => 'required|numeric|min:0|decimal:0,2',
        ]);

        $this->liabilityLines[] = [
            'category' => trim((string) $validated['newLiabilityCategory']),
            'amount' => Money::fromPennies(Money::normalize((string) $validated['newLiabilityAmount'])),
        ];

        $this->newLiabilityCategory = '';
        $this->newLiabilityAmount = '0.00';
    }

    public function removeAssetLine(int $index): void
    {
        unset($this->assetLines[$index]);
        $this->assetLines = array_values($this->assetLines);
        $this->editingAssetIndex = null;
    }

    public function removeLiabilityLine(int $index): void
    {
        unset($this->liabilityLines[$index]);
        $this->liabilityLines = array_values($this->liabilityLines);
        $this->editingLiabilityIndex = null;
    }

    public function editAssetLine(int $index): void
    {
        $this->editingAssetIndex = $index;
    }

    public function saveAssetLine(int $index): void
    {
        $this->formatLineAmount('assetLines', $index);
        $this->editingAssetIndex = null;
    }

    public function editLiabilityLine(int $index): void
    {
        $this->editingLiabilityIndex = $index;
    }

    public function saveLiabilityLine(int $index): void
    {
        $this->formatLineAmount('liabilityLines', $index);
        $this->editingLiabilityIndex = null;
    }

    private function formatLineAmount(string $property, int $index): void
    {
        if (!isset($this->{$property}[$index])) {
            return;
        }

        $this->{$property}[$index]['amount'] = Money::fromPennies(
            Money::normalize($this->{$property}[$index]['amount']),
        );
    }
}
