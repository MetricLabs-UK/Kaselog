<?php

namespace Tests\Feature;

use App\Models\PrecedentTemplate;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Precedent library scoping — template_key was globally unique despite the
 * model being tenant-scoped, which would make "adopting the same master
 * template" across two firms impossible (both would try to hold a row with
 * the same key). Scoped to [tenant_id, template_key] instead.
 */
class PrecedentTemplateTenantScopingTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    public function test_two_tenants_can_hold_a_template_with_the_same_key(): void
    {
        $tenantA = $this->setUpTenant();
        PrecedentTemplate::create([
            'name' => 'Client Care Letter',
            'template_key' => 'client_care',
            'file_path' => 'precedent-templates/client_care.docx',
            'available_fields' => ['client_name'],
        ]);

        $tenantB = Tenant::create(['name' => 'Other Firm', 'slug' => 'other-firm', 'reference_prefix' => 'OF']);
        CurrentTenant::set($tenantB);

        $second = PrecedentTemplate::create([
            'name' => 'Client Care Letter',
            'template_key' => 'client_care',
            'file_path' => 'precedent-templates/client_care.docx',
            'available_fields' => ['client_name'],
        ]);

        $this->assertSame($tenantB->id, $second->tenant_id);
        $this->assertSame(2, PrecedentTemplate::allTenants()->where('template_key', 'client_care')->count());
    }

    public function test_the_same_tenant_cannot_hold_two_templates_with_the_same_key(): void
    {
        $this->setUpTenant();
        PrecedentTemplate::create([
            'name' => 'Client Care Letter',
            'template_key' => 'client_care',
            'file_path' => 'precedent-templates/client_care.docx',
            'available_fields' => [],
        ]);

        $this->expectException(QueryException::class);

        PrecedentTemplate::create([
            'name' => 'Client Care Letter (duplicate)',
            'template_key' => 'client_care',
            'file_path' => 'precedent-templates/client_care.docx',
            'available_fields' => [],
        ]);
    }
}
