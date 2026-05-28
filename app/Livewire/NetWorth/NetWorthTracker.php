<?php

declare(strict_types=1);

namespace App\Livewire\NetWorth;

use App\Models\NetWorthEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read float $calculatedNetWorthValue
 * @property-read string $calculatedNetWorth
 * @property-read string $calculatedNetWorthStyle
 */
#[Layout('components.layouts.app')]
#[Title('Net Worth')]
class NetWorthTracker extends Component
{
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

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function render(): View
    {
        $entries = NetWorthEntry::where('user_id', Auth::id())
            ->with('lineItems')
            ->orderByDesc('date')
            ->get();

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

        $assetTotal = $this->sumLines($validated['assetLines']);
        $liabilityTotal = $this->sumLines($validated['liabilityLines']);

        $data = [
            'user_id' => Auth::id(),
            'date' => $validated['date'],
            'assets' => $assetTotal,
            'liabilities' => $liabilityTotal,
            'net_worth' => $assetTotal - $liabilityTotal,
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
        session()->flash('status', 'Net worth entry saved.');
        $this->dispatch('close-networth-modal');
    }

    public function openModal(): void
    {
        $this->resetForm();
        $this->dispatch('open-networth-modal');
    }

    public function edit(int $entryId): void
    {
        $entry = NetWorthEntry::where('user_id', Auth::id())->findOrFail($entryId);

        $this->entryId = $entry->id;
        $this->date = $entry->date->toDateString();
        $assetLines = $entry->lineItems
            ->where('type', 'asset')
            ->map(fn ($item): array => [
                'category' => $item->category,
                'amount' => number_format((float) $item->amount, 2, '.', ''),
            ])->values()->all();

        $liabilityLines = $entry->lineItems
            ->where('type', 'liability')
            ->map(fn ($item): array => [
                'category' => $item->category,
                'amount' => number_format((float) $item->amount, 2, '.', ''),
            ])->values()->all();

        $this->assetLines = $assetLines ?: [[
            'category' => 'Assets',
            'amount' => number_format((float) $entry->assets, 2, '.', ''),
        ]];

        $this->liabilityLines = $liabilityLines ?: [[
            'category' => 'Liabilities',
            'amount' => number_format((float) $entry->liabilities, 2, '.', ''),
        ]];

        $this->dispatch('open-networth-modal');
    }

    public function delete(int $entryId): void
    {
        NetWorthEntry::where('user_id', Auth::id())->where('id', $entryId)->delete();
        session()->flash('status', 'Net worth entry removed.');
    }

    public function getCalculatedNetWorthProperty(): string
    {
        return number_format($this->assetTotal() - $this->liabilityTotal(), 2);
    }

    public function getCalculatedNetWorthValueProperty(): float
    {
        return $this->assetTotal() - $this->liabilityTotal();
    }

    public function getCalculatedNetWorthStyleProperty(): string
    {
        return $this->calculatedNetWorthValue >= 0
            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
            : 'bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-300';
    }

    public function resetForm(): void
    {
        $this->entryId = null;
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
    protected function lineItemRules(string $property): array
    {
        return [
            $property => 'array',
            $property.'.*.category' => 'required|string|max:255',
            $property.'.*.amount' => 'required|numeric|min:0',
        ];
    }

    /**
     * @param array<mixed, array<string, string>> $lines
     */
    protected function sumLines(array $lines): float
    {
        return collect($lines)
            ->sum(fn ($line): float => (float) $line['amount']);
    }

    /**
     * @param array<mixed, array<string, string>> $lines
     */
    protected function syncLineItems(NetWorthEntry $netWorthEntry, array $lines, string $type): void
    {
        $netWorthEntry->lineItems()->where('type', $type)->delete();

        $payload = collect($lines)
            ->filter(fn ($line): bool => trim($line['category']) !== '')
            ->map(fn ($line): array => [
                'user_id' => Auth::id(),
                'type' => $type,
                'category' => trim($line['category']),
                'amount' => (float) $line['amount'],
            ])->all();

        if ($payload) {
            $netWorthEntry->lineItems()->createMany($payload);
        }
    }

    protected function assetTotal(): float
    {
        return $this->sumLines($this->assetLines);
    }

    protected function liabilityTotal(): float
    {
        return $this->sumLines($this->liabilityLines);
    }

    public function addAssetLine(): void
    {
        $this->resetErrorBag(['newAssetCategory', 'newAssetAmount']);

        if (trim($this->newAssetCategory) === '') {
            $this->addError('newAssetCategory', 'Asset category is required.');

            return;
        }

        $this->assetLines[] = [
            'category' => trim($this->newAssetCategory),
            'amount' => number_format((float) $this->newAssetAmount, 2, '.', ''),
        ];

        $this->newAssetCategory = '';
        $this->newAssetAmount = '0.00';
    }

    public function addLiabilityLine(): void
    {
        $this->resetErrorBag(['newLiabilityCategory', 'newLiabilityAmount']);

        if (trim($this->newLiabilityCategory) === '') {
            $this->addError('newLiabilityCategory', 'Liability category is required.');

            return;
        }

        $this->liabilityLines[] = [
            'category' => trim($this->newLiabilityCategory),
            'amount' => number_format((float) $this->newLiabilityAmount, 2, '.', ''),
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

    protected function formatLineAmount(string $property, int $index): void
    {
        if (!isset($this->{$property}[$index])) {
            return;
        }

        $this->{$property}[$index]['amount'] = number_format(
            (float) $this->{$property}[$index]['amount'],
            2,
            '.',
            '',
        );
    }
}
