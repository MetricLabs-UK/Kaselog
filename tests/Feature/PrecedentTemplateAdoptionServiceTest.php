<?php

namespace Tests\Feature;

use App\Enums\PrecedentTemplateType;
use App\Models\PrecedentTemplate;
use App\Models\Tenant;
use App\Services\PrecedentTemplateAdoptionService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PrecedentTemplateAdoptionServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private PrecedentTemplateAdoptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PrecedentTemplateAdoptionService::class);
    }

    private function makeMaster(array $overrides = []): PrecedentTemplate
    {
        // Created with no ambient tenant, matching how the Hub actually
        // creates masters (CurrentTenant is never set there) — the
        // BelongsToTenant creating hook leaves tenant_id null.
        CurrentTenant::clear();

        return PrecedentTemplate::create(array_merge([
            'name' => 'Client Care Letter',
            'template_key' => 'client_care',
            'type' => PrecedentTemplateType::RichText,
            'content' => '<p>Dear {{client_name}},</p>',
            'available_fields' => [],
            'is_master' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_adopting_a_master_creates_an_independent_tenant_scoped_copy(): void
    {
        $tenant = $this->setUpTenant();
        $master = $this->makeMaster();
        CurrentTenant::set($tenant);

        $copy = $this->service->adopt($master);

        $this->assertNotSame($master->id, $copy->id);
        $this->assertSame($tenant->id, $copy->tenant_id);
        $this->assertSame($master->id, $copy->adopted_from_id);
        $this->assertSame('Client Care Letter', $copy->name);
        $this->assertSame('client_care', $copy->template_key);
        $this->assertFalse($copy->is_master);
    }

    public function test_editing_the_adopted_copy_never_touches_the_master(): void
    {
        $tenant = $this->setUpTenant();
        $master = $this->makeMaster();
        CurrentTenant::set($tenant);

        $copy = $this->service->adopt($master);
        $copy->update(['content' => '<p>Completely rewritten.</p>']);

        $this->assertSame('<p>Dear {{client_name}},</p>', $master->fresh()->content);
    }

    public function test_two_tenants_can_independently_adopt_the_same_master(): void
    {
        $tenantA = $this->setUpTenant();
        $master = $this->makeMaster();
        CurrentTenant::set($tenantA);
        $copyA = $this->service->adopt($master);

        $tenantB = Tenant::create(['name' => 'Other Firm', 'slug' => 'other-firm', 'reference_prefix' => 'OF']);
        CurrentTenant::set($tenantB);
        $copyB = $this->service->adopt($master);

        $this->assertNotSame($copyA->id, $copyB->id);
        $this->assertSame($tenantA->id, $copyA->tenant_id);
        $this->assertSame($tenantB->id, $copyB->tenant_id);

        $copyA->update(['content' => '<p>Firm A only.</p>']);
        $this->assertSame('<p>Dear {{client_name}},</p>', $copyB->fresh()->content);
    }

    public function test_adopting_the_same_master_twice_by_the_same_tenant_returns_the_existing_copy(): void
    {
        $tenant = $this->setUpTenant();
        $master = $this->makeMaster();
        CurrentTenant::set($tenant);

        $first = $this->service->adopt($master);
        $second = $this->service->adopt($master);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PrecedentTemplate::where('adopted_from_id', $master->id)->count());
    }

    public function test_adopting_a_non_master_template_is_rejected(): void
    {
        $tenant = $this->setUpTenant();
        CurrentTenant::set($tenant);

        $notAMaster = PrecedentTemplate::create([
            'name' => 'Home-grown template',
            'template_key' => 'home_grown',
            'type' => PrecedentTemplateType::RichText,
            'content' => '<p>Hello</p>',
            'available_fields' => [],
            'is_master' => false,
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->service->adopt($notAMaster);
    }

    public function test_adopting_a_docx_master_copies_the_file_independently(): void
    {
        Storage::fake('documents');
        Storage::disk('documents')->put('precedent-templates/master.docx', 'master contents');

        $tenant = $this->setUpTenant();
        $master = $this->makeMaster([
            'type' => PrecedentTemplateType::DocxUpload,
            'content' => null,
            'file_path' => 'precedent-templates/master.docx',
            'available_fields' => ['client_name'],
        ]);
        CurrentTenant::set($tenant);

        $copy = $this->service->adopt($master);

        $this->assertNotNull($copy->file_path);
        $this->assertNotSame($master->file_path, $copy->file_path);
        Storage::disk('documents')->assertExists($copy->file_path);
        $this->assertSame('master contents', Storage::disk('documents')->get($copy->file_path));
    }
}
