<?php

declare(strict_types=1);

namespace App\Livewire\Bills;

use App\Models\Category;
use App\Models\PlannedBill;
use App\Models\PlannedBillPayment;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Bills')]
class BillManager extends Component
{
    public ?int $billId = null;

    public string $name = '';

    public string $note = '';

    public ?int $categoryId = null;

    public string $estimatedAmount = '';

    public string $nextDueDate = '';

    public string $frequency = 'yearly';

    public string $sourceSearch = '';

    public ?int $sourceTransactionId = null;

    public ?int $paymentBillId = null;

    public ?int $paymentTransactionId = null;

    public string $paymentSearch = '';

    public ?int $deletingBillId = null;

    public ?int $unlinkingPaymentId = null;

    public string $unlinkingDescription = '';

    public ?int $historyBillId = null;

    public function render(): View
    {
        $userId = (int) Auth::id();
        $bills = PlannedBill::forUser($userId)
            ->with(['category', 'payments.transaction'])
            ->orderBy('next_due_date')
            ->orderBy('name')
            ->get();

        $categories = Category::forUser($userId)
            ->expense()
            ->parents()
            ->with('children')
            ->orderBy('name')
            ->get();

        $sourceTransactions = $this->expenseChoices($this->sourceSearch);
        $paymentTransactions = $this->expenseChoices($this->paymentSearch);
        $selectedPaymentTransaction = $this->paymentTransactionId === null ? null
            : Transaction::forUser($userId)->expense()->where('is_recurring', false)->with('category')->find($this->paymentTransactionId);
        $historyBill = $bills->firstWhere('id', $this->historyBillId);

        return view('livewire.bills.manager', ['bills' => $bills, 'categories' => $categories, 'sourceTransactions' => $sourceTransactions, 'paymentTransactions' => $paymentTransactions, 'selectedPaymentTransaction' => $selectedPaymentTransaction, 'historyBill' => $historyBill]);
    }

    public function openModal(): void
    {
        $this->resetBillForm();
        $this->dispatch('open-bill-modal');
    }

    public function edit(int $billId): void
    {
        $plannedBill = PlannedBill::forUser((int) Auth::id())->findOrFail($billId);
        $this->resetBillForm();
        $this->billId = $plannedBill->id;
        $this->name = $plannedBill->name;
        $this->note = $plannedBill->note ?? '';
        $this->categoryId = $plannedBill->category_id;
        $this->estimatedAmount = $plannedBill->estimated_amount;
        $this->nextDueDate = $plannedBill->next_due_date->toDateString();
        $this->frequency = $plannedBill->frequency;
        $this->dispatch('open-bill-modal');
    }

    public function useTransaction(int $transactionId): void
    {
        if ($this->billId !== null) {
            return;
        }

        $transaction = $this->recordedExpense($transactionId);
        if (PlannedBillPayment::where('transaction_id', $transactionId)->exists()) {
            $this->addError('sourceTransactionId', 'This expense is already linked to a bill.');

            return;
        }

        $this->sourceTransactionId = $transaction->id;
        $this->name = $transaction->description ?: ($transaction->category->name ?? 'Bill');
        $this->categoryId = $transaction->category_id;
        $this->estimatedAmount = (string) $transaction->amount;
        $this->nextDueDate = $this->dateAfter($transaction->date->toImmutable(), $transaction->date->day)->toDateString();
        $this->resetErrorBag('sourceTransactionId');
    }

    public function updatedFrequency(): void
    {
        if ($this->sourceTransactionId !== null && $this->billId === null && in_array($this->frequency, ['quarterly', 'yearly'], true)) {
            $transaction = $this->recordedExpense($this->sourceTransactionId);
            $this->nextDueDate = $this->dateAfter($transaction->date->toImmutable(), $transaction->date->day)->toDateString();
        }
    }

    public function save(): void
    {
        $this->name = trim($this->name);
        $this->note = trim($this->note);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:2000'],
            'categoryId' => ['nullable', Rule::exists('categories', 'id')->where('user_id', Auth::id())->where('type', Category::TYPE_EXPENSE)],
            'estimatedAmount' => ['required', 'numeric', 'min:0.01'],
            'nextDueDate' => ['required', 'date'],
            'frequency' => ['required', 'in:quarterly,yearly'],
        ]);

        $userId = (int) Auth::id();
        $source = $this->sourceTransactionId !== null && $this->billId === null
            ? $this->recordedExpense($this->sourceTransactionId)
            : null;
        if ($source instanceof Transaction && PlannedBillPayment::where('transaction_id', $source->id)->exists()) {
            $this->addError('sourceTransactionId', 'This expense is already linked to a bill.');

            return;
        }

        $dueDate = CarbonImmutable::parse($data['nextDueDate']);
        if ($source instanceof Transaction && $dueDate->lessThanOrEqualTo($source->date)) {
            $this->addError('nextDueDate', 'The next due date must be after the previous payment.');

            return;
        }

        $bill = $this->billId !== null ? PlannedBill::forUser($userId)->findOrFail($this->billId) : new PlannedBill();
        $latestPayment = $bill->exists ? $bill->payments()->orderByDesc('expected_date')->first() : null;
        if ($latestPayment && $dueDate->lessThanOrEqualTo($latestPayment->expected_date)) {
            $this->addError('nextDueDate', 'The next due date must be after the last linked payment.');

            return;
        }

        $sourceAnchorDay = $source instanceof Transaction
            && $dueDate->toDateString() === $this->dateAfter($source->date->toImmutable(), $source->date->day)->toDateString()
                ? $source->date->day
                : $dueDate->day;

        DB::transaction(function () use ($bill, $data, $userId, $dueDate, $source, $sourceAnchorDay): void {
            $bill->fill([
                'user_id' => $userId,
                'name' => trim($data['name']),
                'note' => $data['note'] !== '' ? $data['note'] : null,
                'category_id' => $data['categoryId'],
                'estimated_amount' => $data['estimatedAmount'],
                'next_due_date' => $dueDate->toDateString(),
                'anchor_day' => $source instanceof Transaction
                    ? $sourceAnchorDay
                    : (!$bill->exists || $bill->next_due_date->toDateString() !== $dueDate->toDateString()
                        ? $dueDate->day
                        : $bill->anchor_day),
                'frequency' => $data['frequency'],
            ])->save();

            if ($source instanceof Transaction) {
                $bill->payments()->create([
                    'user_id' => $userId,
                    'expected_date' => $source->date->toDateString(),
                    'transaction_id' => $source->id,
                ]);
            }
        });

        $this->resetBillForm();
        session()->flash('status', 'Bill saved.');
        $this->dispatch('close-bill-modal');
    }

    public function openPaymentModal(int $billId): void
    {
        PlannedBill::forUser((int) Auth::id())->findOrFail($billId);
        $this->paymentBillId = $billId;
        $this->paymentTransactionId = null;
        $this->paymentSearch = '';
        $this->resetErrorBag();
        $this->dispatch('open-bill-payment-modal');
    }

    public function linkPayment(): void
    {
        $this->validate(['paymentTransactionId' => ['required', 'integer']]);
        $transaction = $this->recordedExpense((int) $this->paymentTransactionId);
        if (PlannedBillPayment::where('transaction_id', $transaction->id)->exists()) {
            $this->addError('paymentTransactionId', 'This expense is already linked to a bill.');

            return;
        }

        DB::transaction(function () use ($transaction): void {
            $bill = PlannedBill::forUser((int) Auth::id())->lockForUpdate()->findOrFail($this->paymentBillId);
            $expected = $bill->next_due_date->toImmutable();
            $bill->payments()->create([
                'user_id' => $bill->user_id,
                'expected_date' => $expected->toDateString(),
                'transaction_id' => $transaction->id,
                'previous_estimated_amount' => $bill->estimated_amount,
            ]);
            $bill->update([
                'next_due_date' => $bill->dateAfter($expected)->toDateString(),
                'estimated_amount' => $transaction->amount,
            ]);
        });

        $this->paymentBillId = null;
        $this->paymentTransactionId = null;
        session()->flash('status', 'Payment linked. The next estimate now uses the actual amount.');
        $this->dispatch('close-bill-payment-modal');
    }

    public function selectPayment(int $transactionId): void
    {
        $transaction = $this->recordedExpense($transactionId);
        if (PlannedBillPayment::where('transaction_id', $transaction->id)->exists()) {
            $this->addError('paymentTransactionId', 'This expense is already linked to a bill.');

            return;
        }

        $this->paymentTransactionId = $transaction->id;
        $this->resetErrorBag('paymentTransactionId');
    }

    public function confirmUnlinkPayment(int $paymentId): void
    {
        $payment = PlannedBillPayment::where('user_id', Auth::id())->with('transaction')->findOrFail($paymentId);
        $this->unlinkingPaymentId = $payment->id;
        $this->unlinkingDescription = $payment->transaction->description ?: 'Recorded expense';
        $this->dispatch('close-bill-history-modal');
        $this->dispatch('open-unlink-bill-payment-modal');
    }

    public function openHistory(int $billId): void
    {
        PlannedBill::forUser((int) Auth::id())->findOrFail($billId);
        $this->historyBillId = $billId;
        $this->dispatch('open-bill-history-modal');
    }

    public function unlinkPayment(): void
    {
        if ($this->unlinkingPaymentId === null) {
            return;
        }

        $payment = PlannedBillPayment::where('user_id', Auth::id())->with('bill')->findOrFail($this->unlinkingPaymentId);
        $bill = $payment->bill;
        if ($bill->payments()->orderByDesc('expected_date')->value('id') !== $payment->id) {
            session()->flash('status', 'Only the latest payment can be unlinked.');

            return;
        }

        DB::transaction(function () use ($payment, $bill): void {
            $bill->update([
                'next_due_date' => $payment->expected_date->toDateString(),
                'estimated_amount' => $payment->previous_estimated_amount ?? $bill->estimated_amount,
            ]);
            $payment->delete();
        });

        $this->unlinkingPaymentId = null;
        $this->unlinkingDescription = '';
        session()->flash('status', 'Payment unlinked.');
        $this->dispatch('close-unlink-bill-payment-modal');
    }

    public function confirmDelete(int $billId): void
    {
        PlannedBill::forUser((int) Auth::id())->findOrFail($billId);
        $this->deletingBillId = $billId;
        $this->dispatch('open-delete-bill-modal');
    }

    public function delete(): void
    {
        if ($this->deletingBillId === null) {
            return;
        }

        PlannedBill::forUser((int) Auth::id())->findOrFail($this->deletingBillId)->delete();
        $this->deletingBillId = null;
        session()->flash('status', 'Bill removed. Recorded transactions were kept.');
        $this->dispatch('close-delete-bill-modal');
    }

    private function resetBillForm(): void
    {
        $this->billId = null;
        $this->name = '';
        $this->note = '';
        $this->categoryId = null;
        $this->estimatedAmount = '';
        $this->nextDueDate = now()->toDateString();
        $this->frequency = 'yearly';
        $this->sourceSearch = '';
        $this->sourceTransactionId = null;
        $this->resetValidation();
    }

    private function recordedExpense(int $transactionId): Transaction
    {
        return Transaction::forUser((int) Auth::id())
            ->expense()
            ->where('is_recurring', false)
            ->with('category')
            ->findOrFail($transactionId);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Transaction> */
    private function expenseChoices(string $search): \Illuminate\Database\Eloquent\Collection
    {
        $search = trim($search);

        return Transaction::forUser((int) Auth::id())
            ->expense()
            ->where('is_recurring', false)
            ->whereNotIn('id', PlannedBillPayment::where('user_id', Auth::id())->select('transaction_id'))
            ->when($search !== '', fn (Builder $builder): Builder => $builder->where(function (Builder $query) use ($search): void {
                $builder->where('description', 'like', '%' . $search . '%')
                    ->orWhereHas('category', fn (Builder $builder): Builder => $builder->where('name', 'like', '%' . $search . '%'));
            }))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    private function dateAfter(CarbonImmutable $date, int $anchorDay): CarbonImmutable
    {
        $target = $date->startOfMonth()->addMonthsNoOverflow($this->frequency === 'quarterly' ? 3 : 12);

        return $target->day(min($anchorDay, $target->daysInMonth));
    }
}
