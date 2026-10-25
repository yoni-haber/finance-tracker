<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\DashboardNotes;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class DashboardNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_note_is_on_the_dashboard_before_payments_and_ignores_selected_period(): void
    {
        $user = User::factory()->create();
        $user->note()->create(['body' => 'Remember to compare renewal quotes']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSeeHtml('wire:name="dashboard-notes"')
            ->assertSeeTextInOrder(['Notes', 'Remember to compare renewal quotes', 'Next payments from today']);
        $testable = Livewire::actingAs($user)->test(Dashboard::class);
        $testable->dispatch('period-changed', month: 1, year: 2024)
            ->assertSee('January 2024 overview');
        $user->setSelectedPeriod(1, 2024);
        $this->get(route('dashboard'))->assertSee('Remember to compare renewal quotes');
    }

    public function test_a_single_note_can_be_saved_edited_and_loaded_on_another_visit(): void
    {
        $user = User::factory()->create();
        $testable = Livewire::actingAs($user)->test(DashboardNotes::class)
            ->assertSet('body', '')
            ->set('body', "  Compare quotes\nCall the bank  ")->call('save')
            ->assertHasNoErrors()->assertSet('body', "Compare quotes\nCall the bank")
            ->assertSee('Note saved.')->assertNotDispatched('dashboard-trend-updated');

        $note = Note::sole();
        $this->assertSame($user->id, $note->user_id);
        $this->assertSame("Compare quotes\nCall the bank", $note->body);
        $this->assertTrue($note->user->is($user));
        $this->assertTrue($user->note()->sole()->is($note));

        $testable->set('body', '  Call tomorrow  ')->call('save')->assertHasNoErrors();
        $this->assertSame('Call tomorrow', $note->refresh()->body);
        $this->assertDatabaseCount('notes', 1);
        Livewire::actingAs($user)->test(DashboardNotes::class)->assertSet('body', 'Call tomorrow');
    }

    public function test_unsaved_changes_do_not_modify_the_stored_note(): void
    {
        $user = User::factory()->create();
        $note = $user->note()->create(['body' => 'Saved reminder']);
        Livewire::actingAs($user)->test(DashboardNotes::class)->set('body', 'Unsaved reminder');
        $this->assertSame('Saved reminder', $note->refresh()->body);
        Livewire::actingAs($user)->test(DashboardNotes::class)->assertSet('body', 'Saved reminder');
    }

    public function test_saving_blank_text_clears_the_note(): void
    {
        $user = User::factory()->create();
        $note = $user->note()->create(['body' => 'Clear this reminder']);
        Livewire::actingAs($user)->test(DashboardNotes::class)
            ->set('body', "  \n\t ")->call('save')->assertHasNoErrors()
            ->assertSet('body', '')->assertSee('Note cleared.');
        $this->assertModelMissing($note);
        Livewire::actingAs($user)->test(DashboardNotes::class)->assertSet('body', '')
            ->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('notes', 0);
    }

    public function test_note_validation_rejects_long_text_and_accepts_the_limit(): void
    {
        $user = User::factory()->create();
        $note = $user->note()->create(['body' => 'Keep this until a valid save']);
        $testable = Livewire::actingAs($user)
            ->test(DashboardNotes::class)
            ->set('body', str_repeat('a', 2001))
            ->call('save')
            ->assertHasErrors(['body' => 'max'])
            ->assertSee('Keep your note to 2,000 characters or fewer.');
        $this->assertSame('Keep this until a valid save', $note->refresh()->body);
        $testable->set('body', str_repeat('a', 2000))->call('save')->assertHasNoErrors();
        $this->assertSame(str_repeat('a', 2000), $note->refresh()->body);
        $this->assertDatabaseCount('notes', 1);
    }

    public function test_notes_are_private_and_html_is_escaped(): void
    {
        $user = User::factory()->create();
        User::factory()->create()->note()->create(['body' => 'Another user private reminder']);
        $user->note()->create(['body' => '</textarea><script>alert("note")</script>']);

        Livewire::actingAs($user)->test(DashboardNotes::class)
            ->assertSet('body', '</textarea><script>alert("note")</script>')
            ->assertDontSee('Another user private reminder')
            ->assertSeeHtml('&lt;/textarea&gt;&lt;script&gt;alert(&quot;note&quot;)&lt;/script&gt;')
            ->assertDontSeeHtml('</textarea><script>alert("note")</script>');
    }

    public function test_saving_or_clearing_a_note_cannot_modify_another_users_note(): void
    {
        $user = User::factory()->create();
        $note = User::factory()->create()->note()->create(['body' => 'Private reminder']);
        Livewire::actingAs($user)->test(DashboardNotes::class)
            ->assertSet('body', '')
            ->set('body', 'My reminder')->call('save')->assertHasNoErrors()
            ->set('body', '')->call('save')->assertHasNoErrors();
        $this->assertSame('Private reminder', $note->refresh()->body);
        $this->assertDatabaseCount('notes', 1);
    }

    public function test_the_database_only_allows_one_note_per_user(): void
    {
        $user = User::factory()->create();
        $user->note()->create(['body' => 'One notepad']);
        $this->expectException(QueryException::class);
        $user->note()->create(['body' => 'Another notepad']);
    }

    public function test_guests_cannot_access_the_notes_component(): void
    {
        Livewire::test(DashboardNotes::class)->assertForbidden();
    }

    public function test_deleting_a_user_cascades_to_their_note_only(): void
    {
        $user = User::factory()->create();
        $note = $user->note()->create(['body' => 'Remove with account']);
        $otherNote = User::factory()->create()->note()->create(['body' => 'Keep']);
        $user->delete();
        $this->assertModelMissing($note);
        $this->assertModelExists($otherNote);
    }

    public function test_note_validation_rejects_non_string_values(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(DashboardNotes::class)
            ->set('body', 2345)
            ->call('save')
            ->assertHasErrors(['body' => 'string']);
    }
}
