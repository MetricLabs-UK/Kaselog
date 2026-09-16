<?php

namespace Tests\Feature;

use App\Enums\ClientSource;
use App\Enums\InstalmentStatus;
use App\Enums\MatterStatus;
use App\Filament\Portal\Pages\MatterView;
use App\Models\Client;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterDocument;
use App\Models\MatterMessage;
use App\Models\PaymentPlan;
use App\Models\User;
use App\Notifications\ClientDocumentUploadedNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 3 — the Portal's payment plan / documents / messages / upload
 * views. The security boundary that matters most here isn't "does the right
 * content render" (that's the easy half) — it's "can a client reach
 * something that isn't theirs", so every test that can be framed as an
 * access-denial check is, deliberately, rather than only asserting on the
 * happy path.
 */
class PortalMatterViewTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private const PASSWORD = 'secret-password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant();
        Storage::fake('documents');
    }

    private function makeClient(array $attributes = []): Client
    {
        $client = Client::create($attributes + [
            'first_name' => 'Portal', 'last_name' => 'Client', 'email' => 'portal@example.com',
            'phone' => '1', 'source' => ClientSource::Phone, 'portal_enabled' => true,
        ]);
        $client->forceFill(['password' => self::PASSWORD])->save();

        return $client;
    }

    private function makeMatterFor(Client $client): Matter
    {
        return Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Motoring',
            'status' => MatterStatus::Active,
        ]);
    }

    private function actingAsClient(Client $client): void
    {
        $this->actingAs($client, 'portal');
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        Filament::setTenant($this->tenant, isQuiet: true);
    }

    private function mountMatterView(Matter $matter)
    {
        return Livewire::test(MatterView::class, ['reference' => $matter->reference]);
    }

    // --- Payment plan -------------------------------------------------

    public function test_payment_plan_shows_client_safe_fields_only(): void
    {
        $client = $this->makeClient();
        $matter = $this->makeMatterFor($client);
        $plan = PaymentPlan::create([
            'matter_id' => $matter->id,
            'total_amount' => 1000,
            'deposit_amount' => 200,
            'deposit_paid_at' => now(),
            'notes' => 'INTERNAL ONLY: client is difficult to reach',
        ]);
        Instalment::create([
            'payment_plan_id' => $plan->id,
            'amount' => 400,
            'due_date' => today()->addDays(30),
            'status' => InstalmentStatus::Pending,
        ]);

        $this->actingAsClient($client);
        $url = "/{$this->tenant->slug}/{$matter->reference}";

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('1,000.00');
        $response->assertSee('200.00');
        $response->assertSee('400.00');
        $response->assertDontSee('INTERNAL ONLY');
    }

    public function test_no_payment_plan_renders_without_error(): void
    {
        $client = $this->makeClient();
        $matter = $this->makeMatterFor($client);

        $this->actingAsClient($client);

        $this->get("/{$this->tenant->slug}/{$matter->reference}")->assertOk();
    }

    // --- Documents ------------------------------------------------------

    public function test_a_document_shared_by_staff_is_visible(): void
    {
        $client = $this->makeClient();
        $matter = $this->makeMatterFor($client);
        $shared = MatterDocument::create([
            'matter_id' => $matter->id, 'uploaded_by_type' => 'user', 'uploaded_by_id' => 1,
            'filename' => 'shared-with-client.pdf', 'path' => 'x.pdf', 'visible_to_client' => true,
        ]);
        $hidden = MatterDocument::create([
            'matter_id' => $matter->id, 'uploaded_by_type' => 'user', 'uploaded_by_id' => 1,
            'filename' => 'internal-only.pdf', 'path' => 'y.pdf', 'visible_to_client' => false,
        ]);

        $this->actingAsClient($client);

        $this->mountMatterView($matter)
            ->assertCanSeeTableRecords([$shared])
            ->assertCanNotSeeTableRecords([$hidden]);
    }

    public function test_a_document_the_client_uploaded_themselves_is_visible_even_when_not_yet_shared(): void
    {
        $client = $this->makeClient();
        $matter = $this->makeMatterFor($client);
        $ownUpload = MatterDocument::create([
            'matter_id' => $matter->id, 'uploaded_by_type' => 'client', 'uploaded_by_id' => $client->id,
            'filename' => 'my-id.pdf', 'path' => 'z.pdf', 'visible_to_client' => false,
        ]);

        $this->actingAsClient($client);

        $this->mountMatterView($matter)->assertCanSeeTableRecords([$ownUpload]);
    }

    public function test_uploading_a_document_creates_it_tagged_as_client_and_notifies_staff(): void
    {
        Notification::fake();

        $client = $this->makeClient();
        $matter = $this->makeMatterFor($client);
        $solicitor = User::factory()->create();
        $matter->update(['assigned_user_id' => $solicitor->id]);

        $this->actingAsClient($client);

        $this->mountMatterView($matter)
            ->callTableAction('upload', data: [
                'path' => UploadedFile::fake()->create('passport.pdf', 100),
            ])
            ->assertHasNoTableActionErrors();

        $document = MatterDocument::sole();
        $this->assertSame('client', $document->uploaded_by_type);
        $this->assertSame($client->id, $document->uploaded_by_id);
        $this->assertFalse($document->visible_to_client);

        Notification::assertSentTo($solicitor, ClientDocumentUploadedNotification::class);
    }

    public function test_uploaded_document_notifies_directors_when_no_assigned_or_supervising_user(): void
    {
        Notification::fake();

        $client = $this->makeClient();
        $matter = $this->makeMatterFor($client);
        $director = $this->actingAsRole('director');

        $this->actingAsClient($client);

        $this->mountMatterView($matter)
            ->callTableAction('upload', data: [
                'path' => UploadedFile::fake()->create('evidence.pdf', 100),
            ]);

        Notification::assertSentTo($director, ClientDocumentUploadedNotification::class);
    }

    public function test_a_client_cannot_download_a_document_that_is_not_visible_to_them(): void
    {
        $client = $this->makeClient();
        $matter = $this->makeMatterFor($client);
        $hidden = MatterDocument::create([
            'matter_id' => $matter->id, 'uploaded_by_type' => 'user', 'uploaded_by_id' => 1,
            'filename' => 'internal-only.pdf', 'path' => 'internal.pdf', 'visible_to_client' => false,
        ]);

        $this->actingAsClient($client);

        // A staff-hidden document isn't just "download disabled" — it's
        // never in the table's query at all (documentsQuery() excludes it
        // entirely), so calling the action directly (as a hand-crafted
        // request could) hits Filament's own record resolution first: it
        // resolves a table action's record through the table's own scoped
        // query, so this never even reaches canDownload()'s own abort(403)
        // guard — "no longer exists" is Filament's message, not a 403, and
        // is a stronger guarantee than a plain permission check would give.
        $this->mountMatterView($matter)->assertCanNotSeeTableRecords([$hidden]);

        $this->expectExceptionMessage('no longer exists');
        $this->mountMatterView($matter)->callTableAction('download', $hidden);
    }

    public function test_a_client_cannot_download_a_document_belonging_to_another_clients_matter(): void
    {
        $clientA = $this->makeClient(['email' => 'a@example.com']);
        $matterA = $this->makeMatterFor($clientA);

        $clientB = $this->makeClient(['email' => 'b@example.com']);
        $matterB = $this->makeMatterFor($clientB);
        $othersDocument = MatterDocument::create([
            'matter_id' => $matterB->id, 'uploaded_by_type' => 'user', 'uploaded_by_id' => 1,
            'filename' => 'client-b-only.pdf', 'path' => 'b.pdf', 'visible_to_client' => true,
        ]);

        $this->actingAsClient($clientA);

        // Client A's own MatterView is scoped to matter A's documents —
        // client B's document was never in that table's query at all, so it
        // isn't just hidden, it's genuinely unresolvable through this page.
        $this->mountMatterView($matterA)->assertCanNotSeeTableRecords([$othersDocument]);

        $this->expectExceptionMessage('no longer exists');
        $this->mountMatterView($matterA)->callTableAction('download', $othersDocument);
    }

    public function test_a_client_cannot_open_another_clients_matter_at_all(): void
    {
        $clientA = $this->makeClient(['email' => 'a2@example.com']);
        $this->makeMatterFor($clientA);

        $clientB = $this->makeClient(['email' => 'b2@example.com']);
        $matterB = $this->makeMatterFor($clientB);

        $this->actingAs($clientA, 'portal');

        $this->get("/{$this->tenant->slug}/{$matterB->reference}")->assertNotFound();
    }

    // --- Messages ---------------------------------------------------------

    public function test_only_visible_to_client_messages_are_shown(): void
    {
        $client = $this->makeClient();
        $matter = $this->makeMatterFor($client);
        MatterMessage::create([
            'matter_id' => $matter->id, 'from_type' => 'user', 'from_id' => 1,
            'body' => 'Your hearing has been moved to next Tuesday.', 'visible_to_client' => true,
        ]);
        MatterMessage::create([
            'matter_id' => $matter->id, 'from_type' => 'user', 'from_id' => 1,
            'body' => 'INTERNAL: client seems unreliable', 'visible_to_client' => false,
        ]);

        $this->actingAsClient($client);

        $response = $this->get("/{$this->tenant->slug}/{$matter->reference}");

        $response->assertSee('Your hearing has been moved to next Tuesday.');
        $response->assertDontSee('INTERNAL: client seems unreliable');
    }

    public function test_viewing_the_page_marks_visible_messages_as_read(): void
    {
        $client = $this->makeClient();
        $matter = $this->makeMatterFor($client);
        $message = MatterMessage::create([
            'matter_id' => $matter->id, 'from_type' => 'user', 'from_id' => 1,
            'body' => 'Hello', 'visible_to_client' => true,
        ]);

        $this->actingAsClient($client);
        $this->mountMatterView($matter);

        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_there_is_no_way_to_send_a_message_as_the_client(): void
    {
        // Read-only per the confirmed scope — no reply action exists on the
        // page at all.
        $client = $this->makeClient();
        $matter = $this->makeMatterFor($client);
        $this->actingAsClient($client);

        $component = $this->mountMatterView($matter);

        $this->assertFalse(method_exists($component->instance(), 'sendMessage'));
    }
}
