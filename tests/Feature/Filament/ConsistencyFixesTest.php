<?php

namespace Tests\Feature\Filament;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Filament\Admin\Resources\AuditLog\AuditLogResource;
use App\Filament\Admin\Widgets\StatsOverview;
use App\Models\Client;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\PaymentPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DocumentGenerationService;
use App\Support\Tenancy\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Session-3 consistency fixes from the 2026-08-06 audit:
 * - F3: AuditLogResource resolves its tenant through CurrentTenant (one
 *   resolution order app-wide), not Filament::getTenant() directly.
 * - F18: StatsOverview's overdue-instalments count applies the same
 *   director_only filter as the OverdueInstalmentsWidget table beside it.
 * - F19: generated-document firm contact details come from the tenant's
 *   legal entity, not global config — each brand gets its own.
 */
class ConsistencyFixesTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private function makeOverdueInstalment(bool $directorOnlyMatter): Instalment
    {
        $client = Client::create([
            'first_name' => 'Stats',
            'last_name' => 'Client',
            'email' => uniqid().'@example.com',
            'phone' => '1',
            'source' => ClientSource::Phone,
        ]);

        $matter = Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Motoring',
            'status' => MatterStatus::Active,
            'director_only' => $directorOnlyMatter,
        ]);

        $plan = PaymentPlan::create([
            'matter_id' => $matter->id,
            'total_amount' => 1000,
            'deposit_amount' => 100,
            'notes' => '',
        ]);

        return Instalment::create([
            'payment_plan_id' => $plan->id,
            'amount' => 100,
            'due_date' => today()->subDays(10)->toDateString(),
            'paid_at' => null,
            'status' => 'pending',
        ]);
    }

    private function actingAsStaff(string $roleName): User
    {
        $user = $this->actingAsRole($roleName);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        return $user;
    }

    private function overdueStatValue(): mixed
    {
        $widget = Livewire::test(StatsOverview::class)->instance();

        $getStats = new ReflectionMethod($widget, 'getStats');
        $stats = $getStats->invoke($widget);

        // First stat is "Overdue Instalments" — see StatsOverview::getStats().
        return $stats[0]->getValue();
    }

    public function test_f18_overdue_stat_excludes_director_only_matters_for_non_directors(): void
    {
        $this->setUpTenant();
        $this->makeOverdueInstalment(directorOnlyMatter: false);
        $this->makeOverdueInstalment(directorOnlyMatter: true);

        $this->actingAsStaff('admin');
        $this->assertEquals(1, $this->overdueStatValue(), 'Non-director count must match what the adjacent table shows.');
    }

    public function test_f18_overdue_stat_includes_director_only_matters_for_directors(): void
    {
        $this->setUpTenant();
        $this->makeOverdueInstalment(directorOnlyMatter: false);
        $this->makeOverdueInstalment(directorOnlyMatter: true);

        $this->actingAsStaff('director');
        $this->assertEquals(2, $this->overdueStatValue());
    }

    public function test_f3_audit_log_query_follows_current_tenant_resolution_order(): void
    {
        $this->setUpTenant();
        $this->actingAsStaff('director');

        $other = Tenant::firstOrCreate(
            ['slug' => 'the-motoring-lawyers'],
            ['name' => 'The Motoring Lawyers', 'reference_prefix' => 'TML'],
        );

        // Panel tenant says A; an explicit CurrentTenant::set(B) must win —
        // the same precedence TenantScope applies everywhere else.
        Filament::setTenant($this->tenant);
        CurrentTenant::set($other);

        try {
            $sql = AuditLogResource::getEloquentQuery()->toRawSql();
            // Identifier-quoting style depends on the grammar of whichever
            // connection App\Models\Activity is on (backticks for MySQL,
            // i.e. the real 'audit' connection, vs double quotes for the
            // main test suite's sqlite) — strip every quote style so this
            // keeps checking what it's actually meant to (tenant resolution
            // precedence), not incidentally pinning one grammar's syntax.
            $normalized = str_replace(['`', '"', "'"], '', $sql);
            $this->assertStringContainsString("tenant_id = {$other->id}", $normalized);
        } finally {
            CurrentTenant::set($this->tenant);
        }
    }

    public function test_f19_generated_documents_use_the_tenants_own_firm_contact_details(): void
    {
        $this->setUpTenant();
        $this->actingAsStaff('director');

        // An independent second brand (its own legal entity, no parent).
        $secondBrand = Tenant::create([
            'name' => 'Harbour Law',
            'slug' => 'harbour-law',
            'reference_prefix' => 'HL',
            'legal_entity_name' => 'Harbour Law Ltd',
            'firm_address' => '9 Quay Street, Liverpool, L1 1AA',
            'firm_phone' => '0151 000 1234',
            'firm_email' => 'hello@harbourlaw.example',
        ]);

        CurrentTenant::set($secondBrand);

        try {
            $client = Client::create([
                'first_name' => 'Harbour',
                'last_name' => 'Client',
                'email' => 'harbour@example.com',
                'phone' => '2',
                'source' => ClientSource::Phone,
            ]);
            $matter = Matter::create([
                'client_id' => $client->id,
                'practice_area' => 'Shipping',
                'status' => MatterStatus::Active,
            ]);

            $fields = app(DocumentGenerationService::class)->resolveFields($matter);
        } finally {
            CurrentTenant::set($this->tenant);
        }

        $this->assertSame('Harbour Law Ltd', $fields['firm_name']);
        $this->assertSame('9 Quay Street, Liverpool, L1 1AA', $fields['firm_address']);
        $this->assertSame('0151 000 1234', $fields['firm_phone']);
        $this->assertSame('hello@harbourlaw.example', $fields['firm_email']);
    }

    public function test_f19_a_trading_style_child_resolves_contact_details_from_its_legal_entity(): void
    {
        // The seeded root tenant is backfilled by the migration from the
        // old env defaults; a child brand must resolve to those, not blanks.
        $this->setUpTenant([
            'firm_address' => '1 Lostock Way, Bolton, BL6 4SD',
            'firm_phone' => '01204 000000',
            'firm_email' => 'info@lostocklegal.example',
        ]);
        $this->actingAsStaff('director');

        $child = Tenant::firstOrCreate(
            ['slug' => 'the-motoring-lawyers'],
            ['name' => 'The Motoring Lawyers', 'reference_prefix' => 'TML'],
        );
        $child->update(['parent_tenant_id' => $this->tenant->id]);

        CurrentTenant::set($child);

        try {
            $client = Client::create([
                'first_name' => 'TML',
                'last_name' => 'Client',
                'email' => 'tml-client@example.com',
                'phone' => '3',
                'source' => ClientSource::Phone,
            ]);
            $matter = Matter::create([
                'client_id' => $client->id,
                'practice_area' => 'Motoring',
                'status' => MatterStatus::Active,
            ]);

            $fields = app(DocumentGenerationService::class)->resolveFields($matter);
        } finally {
            CurrentTenant::set($this->tenant);
        }

        $this->assertSame('1 Lostock Way, Bolton, BL6 4SD', $fields['firm_address']);
        $this->assertSame('01204 000000', $fields['firm_phone']);
        $this->assertSame('info@lostocklegal.example', $fields['firm_email']);
    }
}
