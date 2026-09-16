<?php

namespace Tests\Feature\Filament;

use App\Enums\ClientSource;
use App\Filament\Admin\Resources\AuditLog\AuditLogResource;
use App\Filament\Admin\Resources\AuditLog\Pages\ListAuditLog;
use App\Filament\Admin\Resources\PrecedentTemplates\Pages\EditPrecedentTemplate;
use App\Filament\Admin\Resources\TimeEntries\Pages\EditTimeEntry;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Matter;
use App\Models\PrecedentTemplate;
use App\Models\TimeEntry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->setUpTenant(), isQuiet: true);
    }

    private function director(): User
    {
        return $this->actingAsRole('director');
    }

    private function precedentTemplate(): PrecedentTemplate
    {
        return PrecedentTemplate::create([
            'name' => 'Letter of Claim',
            'template_key' => 'letter_of_claim',
            'file_path' => 'precedent-templates/letter.docx',
            'available_fields' => [],
            'active' => true,
        ]);
    }

    private function timeEntry(): TimeEntry
    {
        $client = Client::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '07555123456',
            'source' => ClientSource::Phone,
        ]);

        $matter = Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Family',
            'status' => 'active',
        ]);

        return TimeEntry::create([
            'matter_id' => $matter->id,
            'duration_seconds' => 1800,
            'description' => 'Initial call with client',
            'billable' => false,
        ]);
    }

    public function test_creating_a_time_entry_logs_activity_without_requiring_a_reason(): void
    {
        $user = $this->director();
        $this->actingAs($user);

        $timeEntry = $this->timeEntry();

        $activity = Activity::query()->forSubject($timeEntry)->forEvent('created')->sole();

        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame('time_entries', $activity->log_name);
        $this->assertNull($activity->getExtraProperty('reason'));
    }

    public function test_editing_a_precedent_template_without_a_reason_fails_validation_and_does_not_save(): void
    {
        $this->actingAs($this->director());

        $template = $this->precedentTemplate();

        Livewire::test(EditPrecedentTemplate::class, ['record' => $template->getKey()])
            ->fillForm([
                'name' => 'Letter of Claim (updated)',
                'change_reason' => '',
            ])
            ->call('save')
            ->assertHasFormErrors(['change_reason' => 'required']);

        $this->assertSame('Letter of Claim', $template->fresh()->name);
    }

    public function test_editing_a_precedent_template_with_a_reason_saves_and_logs_who_when_what_and_why(): void
    {
        $user = $this->director();
        $this->actingAs($user);

        $template = $this->precedentTemplate();

        Livewire::test(EditPrecedentTemplate::class, ['record' => $template->getKey()])
            ->fillForm([
                'name' => 'Letter of Claim (updated)',
                'file_path' => UploadedFile::fake()->create('letter.docx', 10),
                'change_reason' => 'Corrected a typo in the firm address.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $template->refresh();
        $this->assertSame('Letter of Claim (updated)', $template->name);

        $activity = Activity::query()->forSubject($template)->forEvent('updated')->sole();

        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame('Corrected a typo in the firm address.', $activity->getExtraProperty('reason'));
        $this->assertSame('Letter of Claim', $activity->changes()->get('old')['name']);
        $this->assertSame('Letter of Claim (updated)', $activity->changes()->get('attributes')['name']);
    }

    public function test_editing_a_time_entry_without_a_reason_fails_validation(): void
    {
        $this->actingAs($this->director());

        $timeEntry = $this->timeEntry();

        Livewire::test(EditTimeEntry::class, ['record' => $timeEntry->getKey()])
            ->fillForm([
                'description' => 'Updated description',
                'change_reason' => '',
            ])
            ->call('save')
            ->assertHasFormErrors(['change_reason' => 'required']);
    }

    public function test_editing_a_time_entry_with_a_reason_saves_and_logs_it(): void
    {
        $user = $this->director();
        $this->actingAs($user);

        $timeEntry = $this->timeEntry();

        Livewire::test(EditTimeEntry::class, ['record' => $timeEntry->getKey()])
            ->fillForm([
                'description' => 'Updated description',
                'change_reason' => 'Client corrected the details afterwards.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = Activity::query()->forSubject($timeEntry->fresh())->forEvent('updated')->sole();

        $this->assertSame('Client corrected the details afterwards.', $activity->getExtraProperty('reason'));
    }

    public function test_director_can_access_audit_log_but_other_roles_cannot(): void
    {
        $this->director();
        $this->assertTrue(AuditLogResource::canAccess());

        foreach (['admin', 'solicitor', 'accounts'] as $role) {
            $this->actingAsRole($role);
            $this->assertFalse(AuditLogResource::canAccess());
        }
    }

    public function test_audit_log_is_read_only(): void
    {
        $this->actingAs($this->director());

        $template = $this->precedentTemplate();

        $this->assertFalse(AuditLogResource::canCreate());
        $this->assertFalse(AuditLogResource::canEdit($template));
        $this->assertFalse(AuditLogResource::canDelete($template));
    }

    /**
     * Regression test for a real bug found via a genuine browser page
     * load, not by any prior automated test — every other test in this
     * file asserts against Activity records directly and never renders
     * this table, so a real "select * from users" query running against
     * the audit database (Activity's own connection, wrongly inherited by
     * the causer/subject relation — see App\Models\Activity's docblock)
     * was never triggered. Rendering the actual page with a causer'd,
     * subject'd row is what catches it.
     */
    public function test_the_audit_log_table_renders_a_real_row_with_a_causer_and_subject(): void
    {
        $user = $this->director();

        $template = $this->precedentTemplate();
        $template->updateWithReason(['name' => 'Letter of Claim (updated)'], 'Testing the audit log renders.');

        // The real regression: this used to throw a QueryException (Activity's
        // own 'audit' connection leaking onto the causer/subject relations'
        // queries) the moment the table actually rendered a row with both set.
        Livewire::test(ListAuditLog::class)->assertSuccessful();

        $activity = Activity::query()->forSubject($template->fresh())->forEvent('updated')->sole();

        $this->assertSame($user->id, $activity->resolvedCauser()?->id);
        $this->assertInstanceOf(PrecedentTemplate::class, $activity->resolvedSubject());
    }
}
