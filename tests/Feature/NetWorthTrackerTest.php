<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\NetWorth\NetWorthTracker;
use App\Models\NetWorthEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class NetWorthTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_saves_entry_and_line_items_transactionally(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('date', '2024-05-10')
            ->set('assetLines', [
                ['category' => 'Cash', 'amount' => '150.00'],
            ])
            ->set('liabilityLines', [
                ['category' => 'Credit Card', 'amount' => '40.00'],
            ])
            ->call('save')
            ->assertHasNoErrors();

        /** @var null|NetWorthEntry $entry */
        $entry = NetWorthEntry::first();

        $this->assertInstanceOf(NetWorthEntry::class, $entry);
        $this->assertEquals('2024-05-10', $entry->date->toDateString());
        $this->assertEqualsWithDelta(150.0, (float) $entry->assets, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(40.0, (float) $entry->liabilities, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(110.0, (float) $entry->net_worth, PHP_FLOAT_EPSILON);

        $this->assertDatabaseHas('net_worth_line_items', [
            'net_worth_entry_id' => $entry->id,
            'type' => 'asset',
            'category' => 'Cash',
            'amount' => 150,
        ]);

        $this->assertDatabaseHas('net_worth_line_items', [
            'net_worth_entry_id' => $entry->id,
            'type' => 'liability',
            'category' => 'Credit Card',
            'amount' => 40,
        ]);
    }

    public function test_save_fails_validation_when_date_is_missing(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('date', '')
            ->set('assetLines', [['category' => 'Cash', 'amount' => '100.00']])
            ->set('liabilityLines', [])
            ->call('save')
            ->assertHasErrors(['date']);
    }

    public function test_save_upserts_when_same_date_already_exists(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('date', '2024-06-15')
            ->set('assetLines', [['category' => 'Savings', 'amount' => '1000.00']])
            ->set('liabilityLines', [['category' => 'Old Loan', 'amount' => '100.00']])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('net_worth_entries', 1);
        $firstEntryId = NetWorthEntry::first()?->id;

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('date', '2024-06-15')
            ->set('assetLines', [['category' => 'Savings', 'amount' => '2000.00']])
            ->set('liabilityLines', [['category' => 'Loan', 'amount' => '500.00']])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('net_worth_entries', 1);
        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::first();
        $this->assertSame($firstEntryId, $entry->id);
        $this->assertEqualsWithDelta(2000.0, (float) $entry->assets, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(500.0, (float) $entry->liabilities, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1500.0, (float) $entry->net_worth, PHP_FLOAT_EPSILON);
    }

    public function test_save_adds_error_when_entry_id_not_found(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('entryId', 9999)
            ->set('date', '2024-06-20')
            ->set('assetLines', [['category' => 'Cash', 'amount' => '500.00']])
            ->set('liabilityLines', [['category' => 'None', 'amount' => '0.00']])
            ->call('save')
            ->assertHasErrors(['save']);

        $this->assertDatabaseCount('net_worth_entries', 0);
    }

    public function test_save_updates_existing_entry_when_entry_id_is_set(): void
    {
        $user = User::factory()->create();

        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::factory()->for($user)->create([
            'date' => '2024-07-01',
            'assets' => '1000.00',
            'liabilities' => '200.00',
            'net_worth' => '800.00',
        ]);

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('entryId', $entry->id)
            ->set('date', '2024-07-01')
            ->set('assetLines', [['category' => 'Portfolio', 'amount' => '3000.00']])
            ->set('liabilityLines', [['category' => 'Car Loan', 'amount' => '1000.00']])
            ->call('save')
            ->assertHasNoErrors();

        /** @var NetWorthEntry $updated */
        $updated = $entry->fresh();
        $this->assertEqualsWithDelta(3000.0, (float) $updated->assets, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1000.0, (float) $updated->liabilities, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(2000.0, (float) $updated->net_worth, PHP_FLOAT_EPSILON);
    }

    public function test_edit_loads_entry_with_line_items_into_component_properties(): void
    {
        $user = User::factory()->create();

        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::factory()->for($user)->create([
            'date' => '2024-08-10',
            'assets' => '5000.00',
            'liabilities' => '2000.00',
            'net_worth' => '3000.00',
        ]);

        $entry->lineItems()->createMany([
            ['user_id' => $user->id, 'type' => 'asset', 'category' => 'Stocks', 'amount' => '5000.00'],
            ['user_id' => $user->id, 'type' => 'liability', 'category' => 'Mortgage', 'amount' => '2000.00'],
        ]);

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->call('edit', $entry->id)
            ->assertSet('entryId', $entry->id)
            ->assertSet('date', '2024-08-10')
            ->assertSet('assetLines', [['category' => 'Stocks', 'amount' => '5000.00']])
            ->assertSet('liabilityLines', [['category' => 'Mortgage', 'amount' => '2000.00']]);
    }

    public function test_edit_falls_back_to_assets_liabilities_headers_when_no_line_items(): void
    {
        $user = User::factory()->create();

        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::factory()->for($user)->create([
            'assets' => '2500.00',
            'liabilities' => '750.00',
            'net_worth' => '1750.00',
        ]);

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->call('edit', $entry->id)
            ->assertSet('assetLines', [['category' => 'Assets', 'amount' => '2500.00']])
            ->assertSet('liabilityLines', [['category' => 'Liabilities', 'amount' => '750.00']]);
    }

    public function test_edit_throws_404_for_another_users_entry(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::factory()->for($otherUser)->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->call('edit', $entry->id);
    }

    public function test_delete_removes_own_entry_and_flashes_message(): void
    {
        $user = User::factory()->create();

        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->call('delete', $entry->id)
            ->assertSee('Net worth entry removed.');

        $this->assertDatabaseMissing('net_worth_entries', ['id' => $entry->id]);
    }

    public function test_delete_silently_ignores_another_users_entry(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::factory()->for($otherUser)->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->call('delete', $entry->id);

        $this->assertDatabaseHas('net_worth_entries', ['id' => $entry->id]);
    }

    public function test_add_asset_line_empty_category_adds_error_and_does_not_append_line(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('newAssetCategory', '')
            ->set('newAssetAmount', '100.00')
            ->call('addAssetLine')
            ->assertHasErrors(['newAssetCategory'])
            ->assertSet('assetLines', []);
    }

    public function test_add_asset_line_valid_input_appends_line_and_resets_inputs(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('newAssetCategory', 'Savings Account')
            ->set('newAssetAmount', '1234.56')
            ->call('addAssetLine')
            ->assertHasNoErrors()
            ->assertSet('assetLines', [['category' => 'Savings Account', 'amount' => '1234.56']])
            ->assertSet('newAssetCategory', '')
            ->assertSet('newAssetAmount', '0.00');
    }

    public function test_add_liability_line_empty_category_adds_error(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('newLiabilityCategory', '')
            ->set('newLiabilityAmount', '500.00')
            ->call('addLiabilityLine')
            ->assertHasErrors(['newLiabilityCategory'])
            ->assertSet('liabilityLines', []);
    }

    public function test_add_liability_line_valid_input_appends_line_and_resets_inputs(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('newLiabilityCategory', 'Student Loan')
            ->set('newLiabilityAmount', '25000.00')
            ->call('addLiabilityLine')
            ->assertHasNoErrors()
            ->assertSet('liabilityLines', [['category' => 'Student Loan', 'amount' => '25000.00']])
            ->assertSet('newLiabilityCategory', '')
            ->assertSet('newLiabilityAmount', '0.00');
    }

    public function test_remove_asset_line_removes_correct_index_and_reindexes(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('assetLines', [
                ['category' => 'Checking', 'amount' => '500.00'],
                ['category' => 'Savings', 'amount' => '1000.00'],
                ['category' => 'Stocks', 'amount' => '3000.00'],
            ])
            ->call('removeAssetLine', 1)
            ->assertSet('assetLines', [
                ['category' => 'Checking', 'amount' => '500.00'],
                ['category' => 'Stocks', 'amount' => '3000.00'],
            ])
            ->assertSet('editingAssetIndex', null);
    }

    public function test_remove_liability_line_removes_correct_index_and_reindexes(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('liabilityLines', [
                ['category' => 'Credit Card', 'amount' => '1000.00'],
                ['category' => 'Car Loan', 'amount' => '5000.00'],
            ])
            ->call('removeLiabilityLine', 0)
            ->assertSet('liabilityLines', [
                ['category' => 'Car Loan', 'amount' => '5000.00'],
            ])
            ->assertSet('editingLiabilityIndex', null);
    }

    public function test_edit_asset_line_sets_editing_asset_index(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('assetLines', [
                ['category' => 'Cash', 'amount' => '100.00'],
                ['category' => 'Bonds', 'amount' => '200.00'],
            ])
            ->call('editAssetLine', 1)
            ->assertSet('editingAssetIndex', 1);
    }

    public function test_save_asset_line_formats_amount_to_two_decimal_places_and_clears_editing_index(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('assetLines', [['category' => 'Property', 'amount' => '250000']])
            ->call('editAssetLine', 0)
            ->call('saveAssetLine', 0)
            ->assertSet('editingAssetIndex', null);

        $this->assertSame('250000.00', $component->get('assetLines')[0]['amount']);
    }

    public function test_edit_liability_line_sets_index_and_save_formats_amount(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('liabilityLines', [['category' => 'Mortgage', 'amount' => '150000']])
            ->call('editLiabilityLine', 0)
            ->assertSet('editingLiabilityIndex', 0)
            ->call('saveLiabilityLine', 0)
            ->assertSet('editingLiabilityIndex', null);

        $this->assertSame('150000.00', $component->get('liabilityLines')[0]['amount']);
    }

    public function test_save_asset_line_does_nothing_when_index_does_not_exist(): void
    {
        $user = User::factory()->create();

        $testable = Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('assetLines', [['category' => 'Cash', 'amount' => '100.00']])
            ->call('saveAssetLine', 99); // index 99 does not exist

        // Array must remain untouched
        $this->assertSame(
            [['category' => 'Cash', 'amount' => '100.00']],
            $testable->get('assetLines'),
        );
        // editingAssetIndex is still cleared by saveAssetLine
        $testable->assertSet('editingAssetIndex', null);
    }

    public function test_save_liability_line_does_nothing_when_index_does_not_exist(): void
    {
        $user = User::factory()->create();

        $testable = Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('liabilityLines', [['category' => 'Loan', 'amount' => '500.00']])
            ->call('saveLiabilityLine', 99); // index 99 does not exist

        $this->assertSame(
            [['category' => 'Loan', 'amount' => '500.00']],
            $testable->get('liabilityLines'),
        );
        $testable->assertSet('editingLiabilityIndex', null);
    }

    public function test_calculated_net_worth_is_positive_when_assets_exceed_liabilities(): void
    {
        $user = User::factory()->create();

        $testable = Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('assetLines', [['category' => 'Cash', 'amount' => '5000.00']])
            ->set('liabilityLines', [['category' => 'Debt', 'amount' => '2000.00']]);

        $this->assertSame('3,000.00', $testable->get('calculatedNetWorth'));
        $this->assertEqualsWithDelta(3000.0, $testable->get('calculatedNetWorthValue'), PHP_FLOAT_EPSILON);
    }

    public function test_calculated_net_worth_style_returns_emerald_when_positive_and_rose_when_negative(): void
    {
        $user = User::factory()->create();

        $testable = Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('assetLines', [['category' => 'Cash', 'amount' => '1000.00']])
            ->set('liabilityLines', [['category' => 'Debt', 'amount' => '500.00']]);

        $this->assertStringContainsString('emerald', (string) $testable->get('calculatedNetWorthStyle'));

        $negative = Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('assetLines', [['category' => 'Cash', 'amount' => '500.00']])
            ->set('liabilityLines', [['category' => 'Debt', 'amount' => '1000.00']]);

        $this->assertStringContainsString('rose', (string) $negative->get('calculatedNetWorthStyle'));
    }

    public function test_reset_form_clears_all_fields_to_defaults(): void
    {
        $user = User::factory()->create();

        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('entryId', $entry->id)
            ->set('assetLines', [['category' => 'X', 'amount' => '1.00']])
            ->set('liabilityLines', [['category' => 'Y', 'amount' => '2.00']])
            ->set('newAssetCategory', 'Test Asset')
            ->set('newAssetAmount', '999.00')
            ->set('newLiabilityCategory', 'Test Liability')
            ->set('newLiabilityAmount', '888.00')
            ->set('editingAssetIndex', 0)
            ->set('editingLiabilityIndex', 0)
            ->call('resetForm')
            ->assertSet('entryId', null)
            ->assertSet('assetLines', [])
            ->assertSet('liabilityLines', [])
            ->assertSet('newAssetCategory', '')
            ->assertSet('newAssetAmount', '0.00')
            ->assertSet('newLiabilityCategory', '')
            ->assertSet('newLiabilityAmount', '0.00')
            ->assertSet('editingAssetIndex', null)
            ->assertSet('editingLiabilityIndex', null);
    }

    public function test_open_modal_dispatches_open_networth_modal_event(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->call('openModal')
            ->assertDispatched('open-networth-modal');
    }

    public function test_open_modal_resets_form_state(): void
    {
        $user = User::factory()->create();

        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::factory()->for($user)->create([
            'date' => '2024-06-01',
            'assets' => '1000.00',
            'liabilities' => '500.00',
            'net_worth' => '500.00',
        ]);

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->call('edit', $entry->id)
            ->assertSet('entryId', $entry->id)
            ->call('openModal')
            ->assertSet('entryId', null)
            ->assertSet('assetLines', [])
            ->assertSet('liabilityLines', []);
    }

    public function test_edit_dispatches_open_networth_modal_event(): void
    {
        $user = User::factory()->create();

        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::factory()->for($user)->create([
            'assets' => '2000.00',
            'liabilities' => '500.00',
            'net_worth' => '1500.00',
        ]);

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->call('edit', $entry->id)
            ->assertDispatched('open-networth-modal');
    }

    public function test_save_dispatches_close_networth_modal_event_on_success(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('date', '2024-05-01')
            ->set('assetLines', [['category' => 'Cash', 'amount' => '1000.00']])
            ->set('liabilityLines', [['category' => 'Loan', 'amount' => '200.00']])
            ->call('save')
            ->assertDispatched('close-networth-modal');
    }

    public function test_save_does_not_dispatch_close_networth_modal_event_when_validation_fails(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('date', '')
            ->set('assetLines', [['category' => 'Cash', 'amount' => '1000.00']])
            ->set('liabilityLines', [])
            ->call('save')
            ->assertNotDispatched('close-networth-modal');
    }

    public function test_sync_line_items_replaces_old_lines_when_saving_same_date(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('date', '2024-09-01')
            ->set('assetLines', [['category' => 'Old Savings', 'amount' => '5000.00']])
            ->set('liabilityLines', [['category' => 'Old Loan', 'amount' => '500.00']])
            ->call('save')
            ->assertHasNoErrors();

        /** @var NetWorthEntry $entry */
        $entry = NetWorthEntry::where('user_id', $user->id)->first();
        $this->assertCount(1, $entry->lineItems()->where('type', 'asset')->get());

        Livewire::actingAs($user)
            ->test(NetWorthTracker::class)
            ->set('date', '2024-09-01')
            ->set('assetLines', [
                ['category' => 'Checking', 'amount' => '2000.00'],
                ['category' => 'Bonds', 'amount' => '3000.00'],
            ])
            ->set('liabilityLines', [['category' => 'Car Loan', 'amount' => '1000.00']])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('net_worth_entries', 1);
        $freshEntry = $entry->fresh();
        $this->assertNotNull($freshEntry);
        $this->assertCount(2, $freshEntry->lineItems()->where('type', 'asset')->get());
        $this->assertCount(1, $freshEntry->lineItems()->where('type', 'liability')->get());
        $this->assertDatabaseMissing('net_worth_line_items', ['category' => 'Old Savings']);
        $this->assertDatabaseHas('net_worth_line_items', ['category' => 'Checking']);
    }
}
