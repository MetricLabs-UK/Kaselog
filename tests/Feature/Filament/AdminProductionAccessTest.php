<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Audit finding F2: without `User implements FilamentUser`, Filament's auth
 * middleware only admits users when `config('app.env') === 'local'` — so
 * every staff member worked locally and would have been 403-locked out the
 * moment APP_ENV=production on the VPS. The whole test suite otherwise runs
 * as `testing`≠`local`... but through Livewire::test and unauthenticated
 * routes that never hit that branch, which is exactly how the bug stayed
 * invisible. This smoke test makes the production condition explicit and
 * exercises a real authenticated staff request end-to-end.
 */
class AdminProductionAccessTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private function actingAsStaffInProduction(string $roleName): User
    {
        config(['app.env' => 'production']);

        $user = $this->actingAsRole($roleName);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        return $user;
    }

    public function test_a_director_reaches_the_admin_dashboard_in_production(): void
    {
        $this->setUpTenant();
        $this->actingAsStaffInProduction('director');

        $this->get("/admin/{$this->tenant->slug}")->assertOk();
    }

    public function test_a_non_director_staff_member_reaches_the_admin_dashboard_in_production(): void
    {
        $this->setUpTenant();
        $this->actingAsStaffInProduction('admin');

        $this->get("/admin/{$this->tenant->slug}")->assertOk();
    }
}
