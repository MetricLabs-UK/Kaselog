<?php

namespace Tests\Feature\Filament;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Audit finding F20 (confirmed decision): matters belonging to a
 * director_only client are confidential wholesale — hidden from
 * non-director staff in list views AND unreachable by direct URL, not just
 * filtered out of navigation. Direct-URL blocking is exercised over real
 * HTTP so the whole middleware + record-resolution chain is what's tested.
 */
class DirectorOnlyClientMattersTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private Matter $normalMatter;

    private Matter $confidentialMatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant();

        $normalClient = $this->makeClient('normal@example.com', directorOnly: false);
        $confidentialClient = $this->makeClient('confidential@example.com', directorOnly: true);

        $this->normalMatter = $this->makeMatter($normalClient);
        $this->confidentialMatter = $this->makeMatter($confidentialClient);
    }

    private function makeClient(string $email, bool $directorOnly): Client
    {
        return Client::create([
            'first_name' => 'Test',
            'last_name' => 'Client',
            'email' => $email,
            'phone' => '1',
            'source' => ClientSource::Phone,
            'director_only' => $directorOnly,
        ]);
    }

    private function makeMatter(Client $client): Matter
    {
        return Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Motoring',
            'status' => MatterStatus::Active,
        ]);
    }

    private function actingAsStaff(string $roleName): User
    {
        $user = $this->actingAsRole($roleName);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        return $user;
    }

    public function test_non_director_does_not_see_a_director_only_clients_matters_in_the_list(): void
    {
        $this->actingAsStaff('admin');

        $ids = Livewire::test(ListMatters::class)->instance()->getTable()->getQuery()->pluck('id');

        $this->assertEqualsCanonicalizing([$this->normalMatter->id], $ids->all());
    }

    public function test_director_sees_a_director_only_clients_matters_in_the_list(): void
    {
        $this->actingAsStaff('director');

        $ids = Livewire::test(ListMatters::class)->instance()->getTable()->getQuery()->pluck('id');

        $this->assertEqualsCanonicalizing([$this->normalMatter->id, $this->confidentialMatter->id], $ids->all());
    }

    public function test_non_director_is_blocked_from_a_director_only_clients_matter_by_direct_url(): void
    {
        $this->actingAsStaff('admin');

        $this->get("/admin/{$this->tenant->slug}/matters/{$this->confidentialMatter->id}")
            ->assertNotFound();
    }

    public function test_non_director_can_still_open_a_normal_matter_by_direct_url(): void
    {
        $this->actingAsStaff('admin');

        $this->get("/admin/{$this->tenant->slug}/matters/{$this->normalMatter->id}")
            ->assertOk();
    }

    public function test_director_can_open_a_director_only_clients_matter_by_direct_url(): void
    {
        $this->actingAsStaff('director');

        $this->get("/admin/{$this->tenant->slug}/matters/{$this->confidentialMatter->id}")
            ->assertOk();
    }
}
