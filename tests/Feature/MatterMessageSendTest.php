<?php

namespace Tests\Feature;

use App\Enums\ClientSource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\MatterMessagesRelationManager;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 8 phase 2 — the first real "send a message" path for
 * MatterMessage. visible_to_client mirrors MatterDocument's own
 * column/toggle (same default, same staff-controlled show/hide) — not the
 * client portal side, which doesn't render either yet (item 3's scope).
 */
class MatterMessageSendTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private function matter(): Matter
    {
        $client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '0700', 'source' => ClientSource::Phone,
        ]);

        return Matter::create(['client_id' => $client->id, 'practice_area' => 'Family', 'status' => 'active']);
    }

    private function mountRelationManager(Matter $matter)
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        // RelationManager::getDefaultProperties() defaults to ['lazy' =>
        // true] (real pages hydrate a lazy relation manager with an
        // automatic follow-up request as soon as it scrolls into view), but
        // Livewire::test() never fires that follow-up — a lazily-mounted
        // component only ever renders its placeholder, so $table never
        // gets built and any table action throws "must not be accessed
        // before initialization". Mounting non-lazy here gives a fully
        // hydrated component to act on, same as what a real click-through
        // ends up interacting with once the browser's follow-up request
        // lands.
        return Livewire::test(MatterMessagesRelationManager::class, [
            'ownerRecord' => $matter,
            'pageClass' => ViewMatter::class,
            'lazy' => false,
        ]);
    }

    public function test_sending_a_message_creates_a_staff_authored_matter_message(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');
        $matter = $this->matter();

        $this->mountRelationManager($matter)
            ->callTableAction('sendMessage', data: [
                'body' => 'Please send over the signed form when you get a chance.',
                'visible_to_client' => true,
            ]);

        $message = MatterMessage::where('matter_id', $matter->id)->sole();
        $this->assertSame('user', $message->from_type);
        $this->assertSame($director->id, $message->from_id);
        $this->assertSame('Please send over the signed form when you get a chance.', $message->body);
        $this->assertTrue($message->visible_to_client);
    }

    public function test_a_sent_message_defaults_to_not_visible_to_client(): void
    {
        $this->setUpTenant();
        $this->actingAsRole('director');
        $matter = $this->matter();

        $this->mountRelationManager($matter)
            ->callTableAction('sendMessage', data: ['body' => 'Internal note only.']);

        $message = MatterMessage::where('matter_id', $matter->id)->sole();
        $this->assertFalse($message->visible_to_client);
    }

    public function test_from_label_resolves_the_sending_users_name(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');
        $matter = $this->matter();

        $this->mountRelationManager($matter)
            ->callTableAction('sendMessage', data: ['body' => 'Hello.']);

        $message = MatterMessage::where('matter_id', $matter->id)->sole();
        $this->assertSame($director->name, $message->from_label);
    }

    public function test_toggling_visibility_requires_edit_matters_and_flips_the_flag(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');
        $matter = $this->matter();

        $message = MatterMessage::create([
            'matter_id' => $matter->id,
            'from_type' => 'user',
            'from_id' => $director->id,
            'body' => 'Hello.',
            'visible_to_client' => false,
        ]);

        $this->assertFalse($message->visible_to_client);

        $this->mountRelationManager($matter)
            ->callTableAction('toggleVisibleToClient', $message);

        $this->assertTrue($message->fresh()->visible_to_client);
    }

    public function test_toggling_visibility_is_hidden_without_edit_matters(): void
    {
        $this->setUpTenant();
        $this->actingAsRole('solicitor');
        $matter = $this->matter();

        $message = MatterMessage::create([
            'matter_id' => $matter->id,
            'from_type' => 'user',
            'from_id' => auth()->id(),
            'body' => 'Hello.',
        ]);

        $this->mountRelationManager($matter)
            ->assertTableActionHidden('toggleVisibleToClient', $message);
    }
}
