<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Note;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DashboardNotes extends Component
{
    public string $body = '';

    public string $status = '';

    public function boot(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function mount(): void
    {
        $this->body = Note::where('user_id', Auth::id())->first()->body ?? '';
    }

    public function save(): void
    {
        $this->body = trim($this->body);
        $data = $this->validate([
            'body' => ['string', 'max:2000'],
        ], [
            'body.max' => 'Keep your note to 2,000 characters or fewer.',
        ]);

        if ($this->body === '') {
            Note::where('user_id', Auth::id())->delete();
            $this->status = 'Note cleared.';
        } else {
            Note::updateOrCreate(['user_id' => Auth::id()], $data);
            $this->status = 'Note saved.';
        }
    }

    public function render(): View
    {
        return view('livewire.dashboard-notes');
    }
}
