<?php

namespace Tests\Feature;

use App\Livewire\Categories\CategoryManager;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_shows_only_authenticated_users_categories(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownCategory = Category::factory()->for($user)->create(['name' => 'Food']);
        Category::factory()->for($otherUser)->create(['name' => 'Other']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->assertViewHas('categories', function ($categories) use ($ownCategory) {
                return $categories->count() === 1
                    && $categories->first()->id === $ownCategory->id;
            });
    }

    public function test_render_returns_categories_ordered_by_name(): void
    {
        $user = User::factory()->create();

        Category::factory()->for($user)->create(['name' => 'Zebra']);
        Category::factory()->for($user)->create(['name' => 'Apple']);
        Category::factory()->for($user)->create(['name' => 'Mango']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->assertViewHas('categories', function ($categories) {
                return $categories->pluck('name')->values()->all() === ['Apple', 'Mango', 'Zebra'];
            });
    }

    public function test_save_creates_category_with_valid_name(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('name', 'Groceries')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'name' => 'Groceries',
        ]);
    }

    public function test_save_flashes_status_after_creating_category(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('name', 'Transport')
            ->call('save')
            ->assertSee('Category saved.');
    }

    public function test_save_resets_form_after_creating_category(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('name', 'Utilities')
            ->call('save')
            ->assertSet('name', '')
            ->assertSet('categoryId', null);
    }

    public function test_save_fails_validation_when_name_is_empty(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_save_fails_validation_when_name_exceeds_255_characters(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('name', str_repeat('a', 256))
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_save_updates_existing_category(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['name' => 'Old Name']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('edit', $category->id)
            ->set('name', 'New Name')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Category saved.');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'New Name',
        ]);
    }

    public function test_save_returns_error_when_category_id_not_found(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('categoryId', 99999)
            ->set('name', 'Whatever')
            ->call('save')
            ->assertHasErrors('save');
    }

    public function test_save_cannot_update_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->create(['name' => 'Original']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('categoryId', $otherCategory->id)
            ->set('name', 'Hijacked')
            ->call('save')
            ->assertHasErrors('save');

        $this->assertDatabaseHas('categories', [
            'id' => $otherCategory->id,
            'name' => 'Original',
        ]);
    }

    public function test_edit_loads_category_fields_into_component_state(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['name' => 'Hobbies']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('edit', $category->id)
            ->assertSet('categoryId', $category->id)
            ->assertSet('name', 'Hobbies');
    }

    public function test_edit_throws_404_for_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('edit', $otherCategory->id);
    }

    public function test_delete_removes_own_category_and_flashes_message(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('delete', $category->id)
            ->assertSee('Category removed.');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_delete_silently_ignores_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCategory = Category::factory()->for($otherUser)->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('delete', $otherCategory->id);

        $this->assertDatabaseHas('categories', ['id' => $otherCategory->id]);
    }

    public function test_reset_form_clears_state_to_defaults(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['name' => 'Travel']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('edit', $category->id)
            ->assertSet('categoryId', $category->id)
            ->assertSet('name', 'Travel')
            ->call('resetForm')
            ->assertSet('categoryId', null)
            ->assertSet('name', '');
    }

    public function test_open_modal_dispatches_open_category_modal_event(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('openModal')
            ->assertDispatched('open-category-modal');
    }

    public function test_open_modal_resets_form_state(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['name' => 'Hobbies']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('edit', $category->id)
            ->assertSet('categoryId', $category->id)
            ->assertSet('name', 'Hobbies')
            ->call('openModal')
            ->assertSet('categoryId', null)
            ->assertSet('name', '');
    }

    public function test_edit_dispatches_open_category_modal_event(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['name' => 'Transport']);

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->call('edit', $category->id)
            ->assertDispatched('open-category-modal');
    }

    public function test_save_dispatches_close_category_modal_event_on_success(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('name', 'Groceries')
            ->call('save')
            ->assertDispatched('close-category-modal');
    }

    public function test_save_does_not_dispatch_close_category_modal_event_when_validation_fails(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CategoryManager::class)
            ->set('name', '')
            ->call('save')
            ->assertNotDispatched('close-category-modal');
    }
}
