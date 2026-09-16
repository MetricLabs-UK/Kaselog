<?php

namespace Tests\Feature\Filament;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * A composition check that the two new AI features (document summary column
 * + "Ask Quill" tab) don't break the Matter view page when rendered
 * together with everything else already on it — the unit-level tests for
 * each piece live in tests/Feature/Jobs and tests/Feature/Livewire.
 */
class MatterAiFeaturesTest extends TestCase
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

    private function makeMatter(): Matter
    {
        $client = Client::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '07555123456',
            'source' => ClientSource::Phone,
        ]);

        return Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Family',
            'status' => MatterStatus::Active,
        ]);
    }

    public function test_matter_view_page_renders_with_the_ask_quill_tab(): void
    {
        $this->actingAs($this->director());
        $matter = $this->makeMatter();

        Livewire::test(ViewMatter::class, ['record' => $matter->getKey()])
            ->assertOk()
            ->assertSee('Ask Quill');
    }
}
