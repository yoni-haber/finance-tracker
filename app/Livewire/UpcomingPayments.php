<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\PlannedPayment;
use App\Support\PlannedPayments;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Upcoming Payments')]
class UpcomingPayments extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public ?string $note = null;

    public string $amount = '';

    public string $due_on = '';

    public string $frequency = 'once';

    public ?int $deletingId = null;

    public string $deletingName = '';

    public string $status = '';

    public ?int $statusUndoId = null;

    public function openCreate(): void
    {
        $this->resetForm();
        $this->status = '';
        $this->statusUndoId = null;
        $this->due_on = today()->toDateString();
        $this->dispatch('open-planned-payment-modal');
    }

    public function edit(int $id): void
    {
        $plannedPayment = $this->ownedPayment($id);
        $this->resetForm();
        $this->status = '';
        $this->statusUndoId = null;
        $this->editingId = $id;
        $this->name = $plannedPayment->name;
        $this->note = $plannedPayment->note;
        $this->amount = (string) $plannedPayment->amount;
        $this->due_on = $plannedPayment->nextDueDate()?->toDateString() ?? $plannedPayment->first_due_on->toDateString();
        $this->frequency = $plannedPayment->frequency;
        $this->dispatch('open-planned-payment-modal');
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'due_on' => ['required', 'date_format:Y-m-d'],
            'frequency' => ['required', Rule::in(['once', 'quarterly', 'yearly'])],
        ]);

        $data['name'] = trim($data['name']);
        if ($data['name'] === '') {
            $this->addError('name', 'Enter a payment name.');

            return;
        }

        $note = trim($data['note'] ?? '');
        $data['note'] = $note === '' ? null : $note;

        if ($this->editingId === null) {
            PlannedPayment::create([
                'user_id' => Auth::id(),
                'name' => $data['name'],
                'note' => $data['note'],
                'amount' => $data['amount'],
                'first_due_on' => $data['due_on'],
                'frequency' => $data['frequency'],
            ]);
            $this->status = 'Payment plan added.';
        } else {
            $payment = $this->ownedPayment($this->editingId);
            $scheduleChanged = $data['frequency'] !== $payment->frequency
                || $data['due_on'] !== ($payment->nextDueDate()?->toDateString() ?? $payment->first_due_on->toDateString());
            $payment->fill(['name' => $data['name'], 'note' => $data['note'], 'amount' => $data['amount']]);
            if ($scheduleChanged) {
                $payment->fill([
                    'first_due_on' => $data['due_on'],
                    'frequency' => $data['frequency'],
                    'completed_occurrences' => 0,
                ]);
            }

            $payment->save();
            $this->status = 'Payment plan updated.';
        }

        $this->statusUndoId = null;
        $this->resetForm();
        $this->dispatch('close-planned-payment-modal');
    }

    public function markDone(int $id, string $expectedDue): void
    {
        $completed = DB::transaction(function () use ($id, $expectedDue): bool {
            $payment = PlannedPayment::where('user_id', Auth::id())->lockForUpdate()->findOrFail($id);
            if ($payment->nextDueDate()?->toDateString() !== $expectedDue) {
                return false;
            }

            $payment->increment('completed_occurrences');

            return true;
        });
        $this->status = $completed ? 'Payment marked done.' : 'This payment has already moved to another date.';
        $this->statusUndoId = $completed ? $id : null;
    }

    public function undo(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $payment = PlannedPayment::where('user_id', Auth::id())->lockForUpdate()->findOrFail($id);
            if ($payment->completed_occurrences > 0) {
                $payment->decrement('completed_occurrences');
            }
        });
        $this->status = 'Payment plan restored.';
        $this->statusUndoId = null;
    }

    public function confirmDelete(int $id): void
    {
        $plannedPayment = $this->ownedPayment($id);
        $this->deletingId = $id;
        $this->deletingName = $plannedPayment->name;
        $this->dispatch('open-delete-planned-payment-modal');
    }

    public function delete(): void
    {
        if ($this->deletingId === null) {
            return;
        }

        $this->ownedPayment($this->deletingId)->delete();
        $this->resetDelete();
        $this->status = 'Payment plan deleted.';
        $this->statusUndoId = null;
        $this->dispatch('close-delete-planned-payment-modal');
    }

    public function resetForm(): void
    {
        $this->reset('editingId', 'name', 'note', 'amount', 'due_on', 'frequency');
        $this->resetValidation();
    }

    public function resetDelete(): void
    {
        $this->deletingId = null;
        $this->deletingName = '';
    }

    public function render(): View
    {
        $userId = (int) Auth::id();

        return view('livewire.upcoming-payments', [
            'payments' => PlannedPayments::nextTwelveMonths($userId),
        ]);
    }

    private function ownedPayment(int $id): PlannedPayment
    {
        return PlannedPayment::where('user_id', Auth::id())->findOrFail($id);
    }
}
