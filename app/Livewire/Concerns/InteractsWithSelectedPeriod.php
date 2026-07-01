<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Support\SelectedPeriod;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

/**
 * Shares the globally selected period (month + year) with a Livewire component.
 *
 * The period is initialised from the authenticated user on mount and kept in
 * sync in-place when the global picker dispatches a `period-changed` event.
 */
trait InteractsWithSelectedPeriod
{
    public int $periodMonth;

    public int $periodYear;

    public function mountInteractsWithSelectedPeriod(): void
    {
        $period = $this->currentSelectedPeriod();
        $this->periodMonth = $period->month;
        $this->periodYear = $period->year;
    }

    #[On('period-changed')]
    public function updateSelectedPeriod(int $month, int $year): void
    {
        $selectedPeriod = SelectedPeriod::clamp($month, $year);
        $this->periodMonth = $selectedPeriod->month;
        $this->periodYear = $selectedPeriod->year;
    }

    /** The selected period as a value object. */
    protected function selectedPeriod(): SelectedPeriod
    {
        if (!isset($this->periodMonth, $this->periodYear)) {
            return $this->currentSelectedPeriod();
        }

        return SelectedPeriod::clamp($this->periodMonth, $this->periodYear);
    }

    /** Resolve the persisted period (or current-month fallback) from the user. */
    private function currentSelectedPeriod(): SelectedPeriod
    {
        return Auth::user()?->selectedPeriod() ?? SelectedPeriod::current();
    }
}
