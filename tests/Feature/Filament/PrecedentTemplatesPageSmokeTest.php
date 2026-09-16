<?php

namespace Tests\Feature\Filament;

use App\Enums\PrecedentTemplateType;
use App\Filament\Admin\Resources\PrecedentTemplates\PrecedentTemplateResource;
use App\Models\PrecedentTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\TenantRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A plain HTTP smoke test for the page carrying the new "Adopt from
 * library" / "Preview" table actions — Livewire::test() proved unreliable
 * for this specific resource in this environment (returns a null component
 * instance for reasons unrelated to the resource's own configuration, which
 * a real request does not reproduce), so this exercises the real route
 * instead. The underlying logic (adoption, rich-text rendering) is already
 * covered directly by PrecedentTemplateAdoptionServiceTest and
 * DocumentGenerationServiceRichTextTest.
 */
class PrecedentTemplatesPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CurrentTenant::clear();

        parent::tearDown();
    }

    public function test_the_precedent_templates_list_page_loads_with_a_rich_text_template_and_an_unadopted_master_present(): void
    {
        $tenant = Tenant::firstOrCreate(['slug' => 'lostock-legal'], ['name' => 'Lostock Legal', 'reference_prefix' => 'LL']);
        TenantRoleSeeder::seed($tenant);

        $solicitor = User::factory()->create();
        CurrentTenant::set($tenant);
        $solicitor->tenants()->attach($tenant);
        $solicitor->assignRole('solicitor');
        PrecedentTemplate::create([
            'name' => 'My Rich Text Letter',
            'template_key' => 'my_rich_text_letter',
            'type' => PrecedentTemplateType::RichText,
            'content' => '<p>Dear {{client_name}}</p>',
            'available_fields' => [],
        ]);

        CurrentTenant::clear();
        PrecedentTemplate::create([
            'name' => 'Library Master',
            'template_key' => 'library_master',
            'type' => PrecedentTemplateType::RichText,
            'content' => '<p>Dear {{client_name}}</p>',
            'available_fields' => [],
            'is_master' => true,
            'active' => true,
        ]);

        $this->actingAs($solicitor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant);

        $this->get(PrecedentTemplateResource::getUrl())
            ->assertOk()
            ->assertSee('My Rich Text Letter')
            ->assertSee('Adopt from library');
    }
}
