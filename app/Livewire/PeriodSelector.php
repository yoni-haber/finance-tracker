<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Support\SelectedPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class PeriodSelector extends Component
{
    public int $month;

    public int $year;

    public function mount(): void
    {
        $period = Auth::user()?->selectedPeriod() ?? SelectedPeriod::current();
        $this->month = $period->month;
        $this->year = $period->year;
    }

    public function previousMonth(): void
    {
        $this->applyPeriod($this->currentPeriod()->previous());
    }

    public function nextMonth(): void
    {
        $this->applyPeriod($this->currentPeriod()->next());
    }

    public function updatedMonth(): void
    {
        $this->applyPeriod($this->currentPeriod());
    }

    public function updatedYear(): void
    {
        $this->applyPeriod($this->currentPeriod());
    }

    /** Keep this instance in sync when the period is changed elsewhere. */
    #[On('period-changed')]
    public function syncPeriod(int $month, int $year): void
    {
        $selectedPeriod = SelectedPeriod::clamp($month, $year);
        $this->month = $selectedPeriod->month;
        $this->year = $selectedPeriod->year;
    }

    public function render(): View
    {
        return view('livewire.period-selector', [
            'years' => $this->yearOptions(),
        ]);
    }

    /**
     * A descending list of selectable years, always including the current
     * selection even if it falls outside the default window.
     *
     * @return list<int>
     */
    private function yearOptions(): array
    {
        $currentYear = now()->year;

        $years = range($currentYear + 1, $currentYear - 10);
        if (!in_array($this->year, $years, true)) {
            $years[] = $this->year;
        }

        rsort($years);

        return $years;
    }

    private function currentPeriod(): SelectedPeriod
    {
        return SelectedPeriod::clamp($this->month, $this->year);
    }

    private function applyPeriod(SelectedPeriod $selectedPeriod): void
    {
        $this->month = $selectedPeriod->month;
        $this->year = $selectedPeriod->year;

        Auth::user()?->setSelectedPeriod($selectedPeriod->month, $selectedPeriod->year);

        $this->dispatch('period-changed', month: $selectedPeriod->month, year: $selectedPeriod->year);
    }
}
