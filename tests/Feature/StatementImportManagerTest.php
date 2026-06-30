<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ParseBankStatementJob;
use App\Livewire\Statements\StatementImportManager;
use App\Models\BankProfile;
use App\Models\BankStatementImport;
use App\Models\ImportedTransaction;
use App\Models\User;
use App\Support\BankStatementConfig;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Livewire\WithFileUploads;
use ReflectionAttribute;
use ReflectionClass;
use Tests\TestCase;

final class StatementImportManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_successfully(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->assertStatus(200);
    }

    public function test_displays_bank_profiles_for_user(): void
    {
        $user = User::factory()->create();

        BankProfile::factory()->for($user)->create(['name' => 'My Bank']);
        BankProfile::factory()->for($user)->creditCard()->create(['name' => 'My Credit Card']);

        // Create profile for different user (should not be visible)
        $otherUser = User::factory()->create();
        BankProfile::factory()->for($otherUser)->create(['name' => 'Other User Bank']);

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->assertSee('My Bank')
            ->assertSee('My Credit Card')
            ->assertSee('Manage profiles')
            ->assertSee('Profiles define how CSV/TXT files are read.')
            ->assertSee('Statement file')
            ->assertSee('CSV or TXT - max 2 MB')
            ->assertDontSee('manage bank profiles')
            ->assertDontSee('CSV format is configured per bank profile')
            ->assertDontSee('Other User Bank');
    }

    public function test_no_profile_empty_state_links_directly_to_create_profile(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->assertSee('No bank profiles set up')
            ->assertSee('read your statement file')
            ->assertSee(route('statements.bank-profiles', ['create' => 1]), false)
            ->assertDontSee('read your CSV format');
    }

    public function test_mount_loads_existing_pending_import(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        // Create pending import
        $pendingImport = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->parsing()
            ->create();

        // Create older completed import (should not be loaded)
        BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->parsed()
            ->create(['created_at' => now()->subHour()]);

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->assertSet('currentImport.id', $pendingImport->id)
            ->assertSet('polling', true);
    }

    public function test_mount_with_no_pending_imports(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->assertSet('currentImport', null)
            ->assertSet('polling', false);
    }

    public function test_mount_enables_polling_for_uploaded_status(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $uploadedImport = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->assertSet('currentImport.id', $uploadedImport->id)
            ->assertSet('polling', true);
    }

    public function test_mount_disables_polling_for_parsed_status(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $parsedImport = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->parsed()
            ->create();

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->assertSet('currentImport.id', $parsedImport->id)
            ->assertSet('polling', false);
    }

    public function test_check_import_status_refreshes_current_import(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $import = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->parsing()
            ->create();

        $testable = Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport', $import);

        // Change status in database
        $import->update(['status' => BankStatementConfig::STATUS_PARSED]);

        $testable->call('checkImportStatus')
            ->assertSet('polling', false);

        // Verify the component's currentImport reflects the updated status
        $this->assertEquals(BankStatementConfig::STATUS_PARSED, $testable->get('currentImport')->fresh()->status);
    }

    public function test_check_import_status_stops_polling_when_parsed(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $import = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->parsing()
            ->create();

        $testable = Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport', $import)
            ->set('polling', true);

        // Update import status to parsed
        $import->update(['status' => BankStatementConfig::STATUS_PARSED]);

        $testable->call('checkImportStatus')
            ->assertSet('polling', false);
    }

    public function test_check_import_status_stops_polling_when_failed(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $import = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->parsing()
            ->create();

        $testable = Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport', $import)
            ->set('polling', true);

        // Update import status to failed
        $import->update(['status' => BankStatementConfig::STATUS_FAILED]);

        $testable->call('checkImportStatus')
            ->assertSet('polling', false);
    }

    public function test_check_import_status_with_no_current_import(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport')
            ->call('checkImportStatus')
            ->assertSet('polling', false);
    }

    public function test_upload_statement_validates_required_fields(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->call('uploadStatement')
            ->assertHasErrors(['csvFile', 'bankProfileId']);
    }

    public function test_upload_statement_validates_file_type(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        Storage::fake('local');
        $invalidFile = UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf');

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('csvFile', $invalidFile)
            ->set('bankProfileId', $bankProfile->id)
            ->call('uploadStatement')
            ->assertHasErrors('csvFile');
    }

    public function test_upload_statement_validates_file_size(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        Storage::fake('local');
        $largeFile = UploadedFile::fake()->create('statement.csv', 3000); // 3MB

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('csvFile', $largeFile)
            ->set('bankProfileId', $bankProfile->id)
            ->call('uploadStatement')
            ->assertHasErrors('csvFile');
    }

    public function test_upload_statement_validates_bank_profile_exists(): void
    {
        $user = User::factory()->create();

        Storage::fake('local');
        $csvFile = UploadedFile::fake()->create('statement.csv', 100, 'text/csv');

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('csvFile', $csvFile)
            ->set('bankProfileId', 99999)
            ->call('uploadStatement')
            ->assertHasErrors('bankProfileId');
    }

    public function test_upload_statement_validates_bank_profile_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherUserProfile = BankProfile::factory()->for($otherUser)->create();

        Storage::fake('local');
        $csvFile = UploadedFile::fake()->create('statement.csv', 100, 'text/csv');

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('csvFile', $csvFile)
            ->set('bankProfileId', $otherUserProfile->id)
            ->call('uploadStatement')
            ->assertHasErrors('bankProfileId');
    }

    public function test_upload_statement_creates_import_and_dispatches_job(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create([
            'statement_type' => 'bank',
            'name' => 'Test Bank',
        ]);

        $csvFile = UploadedFile::fake()->create('bank_statement.csv', 100, 'text/csv');

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('csvFile', $csvFile)
            ->set('bankProfileId', $bankProfile->id)
            ->call('uploadStatement')
            ->assertHasNoErrors()
            ->assertSet('polling', true)
            ->assertSet('csvFile', null)
            ->assertSet('bankProfileId', null);

        // Check database
        $import = BankStatementImport::where('user_id', $user->id)->first();
        $this->assertNotNull($import);
        $this->assertEquals(BankStatementConfig::STATUS_UPLOADED, $import->status);
        $this->assertEquals('bank_statement.csv', $import->original_filename);
        $this->assertEquals($bankProfile->id, $import->bank_profile_id);
        $this->assertEquals('bank', $import->statement_type);

        // Check file was stored
        Storage::disk('local')->assertExists(sprintf('statements/%s.csv', $import->id));

        // Check job was dispatched
        Queue::assertPushed(ParseBankStatementJob::class, fn ($job): bool => $job->importId === $import->id);
    }

    public function test_upload_statement_sets_credit_card_statement_type(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $creditCardProfile = BankProfile::factory()
            ->for($user)
            ->creditCard()
            ->create(['statement_type' => 'credit_card']);

        $csvFile = UploadedFile::fake()->create('credit_statement.csv', 100, 'text/csv');

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('csvFile', $csvFile)
            ->set('bankProfileId', $creditCardProfile->id)
            ->call('uploadStatement')
            ->assertHasNoErrors();

        $import = BankStatementImport::where('user_id', $user->id)->first();
        $this->assertNotNull($import);
        $this->assertEquals('credit_card', $import->statement_type);
    }

    public function test_upload_statement_completes_successfully(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();
        $csvFile = UploadedFile::fake()->create('statement.csv', 100, 'text/csv');

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('csvFile', $csvFile)
            ->set('bankProfileId', $bankProfile->id)
            ->call('uploadStatement')
            ->assertHasNoErrors()
            ->assertSet('polling', true)
            ->assertSet('csvFile', null)
            ->assertSet('bankProfileId', null);

        // Verify import was created
        $this->assertDatabaseHas('bank_statement_imports', [
            'user_id' => $user->id,
            'bank_profile_id' => $bankProfile->id,
            'status' => BankStatementConfig::STATUS_UPLOADED,
        ]);
    }

    public function test_upload_statement_handles_exception_in_try_block(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();
        $csvFile = UploadedFile::fake()->create('statement.csv', 100, 'text/csv');

        // Force an exception by making the ParseBankStatementJob::dispatch fail
        Queue::shouldReceive('dispatch')
            ->andThrow(new Exception('Job dispatch failed'));

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('csvFile', $csvFile)
            ->set('bankProfileId', $bankProfile->id)
            ->call('uploadStatement')
            ->assertHasErrors(['csvFile' => 'Failed to upload file. Please try again.']);
    }

    public function test_cancel_import_with_no_current_import(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport')
            ->call('cancelImport')
            ->assertSet('currentImport', null)
            ->assertSet('polling', false);
    }

    public function test_cancel_import_with_committed_import_returns_early(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $committedImport = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->committed()
            ->create();

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport', $committedImport)
            ->call('cancelImport');

        // Import should still exist
        $this->assertDatabaseHas('bank_statement_imports', ['id' => $committedImport->id]);
    }

    public function test_cancel_import_deletes_uploaded_import(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $import = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        // Create a file for the import
        Storage::disk('local')->put(sprintf('statements/%d.csv', $import->id), 'test,data');

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport', $import)
            ->call('cancelImport')
            ->assertSet('currentImport', null)
            ->assertSet('polling', false);

        // Check import was deleted
        $this->assertDatabaseMissing('bank_statement_imports', ['id' => $import->id]);

        // Check file was deleted
        Storage::disk('local')->assertMissing(sprintf('statements/%d.csv', $import->id));
    }

    public function test_cancel_import_deletes_parsed_import_and_transactions(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $import = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->parsed()
            ->create();

        // Create imported transactions
        $transaction1 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();
        $transaction2 = ImportedTransaction::factory()->for($import, 'bankStatementImport')->create();

        Storage::disk('local')->put(sprintf('statements/%d.csv', $import->id), 'test,data');

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport', $import)
            ->call('cancelImport')
            ->assertSet('currentImport', null)
            ->assertSet('polling', false);

        // Check import and transactions were deleted
        $this->assertDatabaseMissing('bank_statement_imports', ['id' => $import->id]);
        $this->assertDatabaseMissing('imported_transactions', ['id' => $transaction1->id]);
        $this->assertDatabaseMissing('imported_transactions', ['id' => $transaction2->id]);

        // Check file was deleted
        Storage::disk('local')->assertMissing(sprintf('statements/%d.csv', $import->id));
    }

    public function test_cancel_import_completes_successfully(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $import = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport', $import)
            ->call('cancelImport')
            ->assertSet('currentImport', null)
            ->assertSet('polling', false);

        // Verify import was deleted
        $this->assertDatabaseMissing('bank_statement_imports', ['id' => $import->id]);
    }

    public function test_proceed_to_review_with_no_current_import(): void
    {
        $user = User::factory()->create();

        $testable = Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport')
            ->call('proceedToReview');

        $this->assertNull($testable->effects['redirect'] ?? null);
    }

    public function test_proceed_to_review_with_unparsed_import(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $import = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        $testable = Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport', $import)
            ->call('proceedToReview');

        $this->assertNull($testable->effects['redirect'] ?? null);
    }

    public function test_proceed_to_review_with_parsed_import_redirects(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $import = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->parsed()
            ->create();

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport', $import)
            ->call('proceedToReview')
            ->assertRedirect(route('statements.review', $import->id));
    }

    public function test_render_fetches_bank_profiles_for_user(): void
    {
        $user = User::factory()->create();

        BankProfile::factory()->for($user)->create(['name' => 'User Bank 1']);
        BankProfile::factory()->for($user)->create(['name' => 'User Bank 2']);

        $otherUser = User::factory()->create();
        BankProfile::factory()->for($otherUser)->create(['name' => 'Other User Bank']);

        $testable = Livewire::actingAs($user)
            ->test(StatementImportManager::class);

        $bankProfiles = $testable->viewData('bankProfiles');

        $this->assertCount(2, $bankProfiles);
        $this->assertTrue($bankProfiles->contains('name', 'User Bank 1'));
        $this->assertTrue($bankProfiles->contains('name', 'User Bank 2'));
        $this->assertFalse($bankProfiles->contains('name', 'Other User Bank'));
    }

    public function test_render_orders_bank_profiles_by_name(): void
    {
        $user = User::factory()->create();

        BankProfile::factory()->for($user)->create(['name' => 'Z Bank']);
        BankProfile::factory()->for($user)->create(['name' => 'A Bank']);
        BankProfile::factory()->for($user)->create(['name' => 'M Bank']);

        $testable = Livewire::actingAs($user)
            ->test(StatementImportManager::class);

        $bankProfiles = $testable->viewData('bankProfiles');

        $this->assertEquals('A Bank', $bankProfiles->first()->name);
        $this->assertEquals('Z Bank', $bankProfiles->last()->name);
    }

    public function test_component_uses_correct_layout_and_title(): void
    {
        $statementImportManager = new StatementImportManager();

        $reflectionClass = new ReflectionClass($statementImportManager);
        $attributes = $reflectionClass->getAttributes();

        $layoutAttribute = null;
        $titleAttribute = null;

        foreach ($attributes as $attribute) {
            if ($attribute->getName() === Layout::class) {
                $layoutAttribute = $attribute;
            }

            if ($attribute->getName() === Title::class) {
                $titleAttribute = $attribute;
            }
        }

        $this->assertInstanceOf(ReflectionAttribute::class, $layoutAttribute);
        $this->assertInstanceOf(ReflectionAttribute::class, $titleAttribute);
        $this->assertSame(['components.layouts.app'], $layoutAttribute->getArguments());
        $this->assertSame(['Import Statement'], $titleAttribute->getArguments());
    }

    public function test_component_has_with_file_uploads_trait(): void
    {
        $statementImportManager = new StatementImportManager();

        $this->assertContains(WithFileUploads::class, class_uses($statementImportManager));
    }

    public function test_rules_method_validates_with_authenticated_user(): void
    {
        $user = User::factory()->create();

        Auth::login($user);

        $statementImportManager = new StatementImportManager();

        // Use reflection to access protected method
        $reflectionClass = new ReflectionClass($statementImportManager);
        $reflectionMethod = $reflectionClass->getMethod('rules');
        $rules = $reflectionMethod->invoke($statementImportManager);

        $this->assertArrayHasKey('csvFile', $rules);
        $this->assertArrayHasKey('bankProfileId', $rules);

        $bankProfileRule = $rules['bankProfileId'];
        $this->assertContains('required', $bankProfileRule);
        $this->assertContains('exists:bank_profiles,id,user_id,' . $user->id, $bankProfileRule);
    }

    public function test_public_properties_have_correct_default_values(): void
    {
        $statementImportManager = new StatementImportManager();

        $this->assertNotInstanceOf(TemporaryUploadedFile::class, $statementImportManager->csvFile);
        $this->assertNull($statementImportManager->bankProfileId);
        $this->assertNotInstanceOf(BankStatementImport::class, $statementImportManager->currentImport);
        $this->assertFalse($statementImportManager->polling);
    }

    public function test_validation_error_messages_for_file_upload(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        Storage::fake('local');

        // Test missing file
        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('bankProfileId', $bankProfile->id)
            ->call('uploadStatement')
            ->assertHasErrors('csvFile');

        // Test wrong file type
        $wrongTypeFile = UploadedFile::fake()->create('document.doc', 100, 'application/msword');

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('csvFile', $wrongTypeFile)
            ->set('bankProfileId', $bankProfile->id)
            ->call('uploadStatement')
            ->assertHasErrors('csvFile');

        // Test file too large
        $largeFile = UploadedFile::fake()->create('large.csv', 3000);

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('csvFile', $largeFile)
            ->set('bankProfileId', $bankProfile->id)
            ->call('uploadStatement')
            ->assertHasErrors('csvFile');
    }

    public function test_accepts_valid_csv_file(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $csvFile = UploadedFile::fake()->create('statement.csv', 100, 'text/csv');

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('csvFile', $csvFile)
            ->set('bankProfileId', $bankProfile->id)
            ->call('uploadStatement')
            ->assertHasNoErrors();
    }

    public function test_accepts_valid_txt_file(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $txtFile = UploadedFile::fake()->create('statement.txt', 100, 'text/plain');

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('csvFile', $txtFile)
            ->set('bankProfileId', $bankProfile->id)
            ->call('uploadStatement')
            ->assertHasNoErrors();
    }

    public function test_multiple_imports_for_same_user_finds_latest(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        // Create older parsing import
        BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->parsing()
            ->create(['created_at' => now()->subHours(2)]);

        // Create newer uploaded import
        $newerImport = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->create(['status' => BankStatementConfig::STATUS_UPLOADED, 'created_at' => now()->subHour()]);

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->assertSet('currentImport.id', $newerImport->id);
    }

    public function test_ignores_committed_imports_on_mount(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        // Create committed import (should be ignored)
        BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->committed()
            ->create();

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->assertSet('currentImport', null)
            ->assertSet('polling', false);
    }

    public function test_ignores_failed_imports_on_mount(): void
    {
        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        // Create failed import (should be ignored)
        BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->failed()
            ->create();

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->assertSet('currentImport', null)
            ->assertSet('polling', false);
    }

    public function test_user_isolation_in_bank_profile_selection(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user1Profile = BankProfile::factory()->for($user1)->create();
        $user2Profile = BankProfile::factory()->for($user2)->create();

        Storage::fake('local');
        $csvFile = UploadedFile::fake()->create('statement.csv', 100, 'text/csv');

        // User1 cannot use User2's profile
        Livewire::actingAs($user1)
            ->test(StatementImportManager::class)
            ->set('csvFile', $csvFile)
            ->set('bankProfileId', $user2Profile->id)
            ->call('uploadStatement')
            ->assertHasErrors('bankProfileId');

        // User1 can use their own profile
        Queue::fake();
        Livewire::actingAs($user1)
            ->test(StatementImportManager::class)
            ->set('csvFile', $csvFile)
            ->set('bankProfileId', $user1Profile->id)
            ->call('uploadStatement')
            ->assertHasNoErrors();
    }

    public function test_review_button_works_after_status_change(): void
    {
        $user = User::factory()->create();
        $profile = BankProfile::factory()->for($user)->create();
        $import = BankStatementImport::factory()
            ->for($user)
            ->for($profile, 'bankProfile')
            ->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        // Start with uploaded status - should show delete button but no review button
        $testable = Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->assertSee('Delete import')
            ->assertDontSee('Review transactions');

        // Simulate status change to parsed (like polling would do)
        $import->update(['status' => BankStatementConfig::STATUS_PARSED]);

        // Call checkImportStatus to simulate the polling update
        $testable->call('checkImportStatus')
            ->assertSee('Review transactions')
            ->assertSee('Delete import');

        // Verify the review button works correctly (should redirect, not trigger delete)
        $testable->call('proceedToReview')
            ->assertRedirect(route('statements.review', $import->id));
    }

    public function test_cancel_import_handles_exception_gracefully(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $bankProfile = BankProfile::factory()->for($user)->create();

        $import = BankStatementImport::factory()
            ->for($user)
            ->for($bankProfile, 'bankProfile')
            ->create(['status' => BankStatementConfig::STATUS_UPLOADED]);

        // Make Storage::disk() return the facade mock itself, then stub delete to
        // throw, triggering the cancelImport catch block.
        Storage::shouldReceive('disk')->andReturnSelf();
        Storage::shouldReceive('delete')->andThrow(new Exception('Disk error'));

        Livewire::actingAs($user)
            ->test(StatementImportManager::class)
            ->set('currentImport', $import)
            ->call('cancelImport');

        // Import should still exist since the exception was caught.
        $this->assertDatabaseHas('bank_statement_imports', ['id' => $import->id]);
    }
}
