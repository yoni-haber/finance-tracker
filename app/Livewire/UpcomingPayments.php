<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Support\UpcomingPayments as PaymentForecast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Upcoming Payments')]
class UpcomingPayments extends Component
{
    #[Url(as: 'months', except: '6')]
    public string $months = '6';

    #[Url(as: 'regular', except: false)]
    public bool $includeRegular = false;

    #[Url(as: 'minimum', except: '')]
    public string $minimum = '';

    public function resetFilters(): void
    {
        $this->reset('months', 'includeRegular', 'minimum');
    }

    public function render(): View
    {
        $validator = Validator::make([
            'months' => $this->months,
            'minimum' => $this->minimum,
        ], [
            'months' => ['required', 'in:3,6,12'],
            'minimum' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
        ], [
            'months.in' => 'Choose 3, 6 or 12 months.',
            'minimum.regex' => 'Enter a nonnegative amount with up to two decimal places.',
        ]);

        $this->setErrorBag($validator->errors());

        return view('livewire.upcoming-payments', [
            'forecast' => $validator->fails() ? null : PaymentForecast::forecast(
                (int) Auth::id(), (int) $this->months, $this->includeRegular, $this->minimum,
            ),
        ]);
    }
}
