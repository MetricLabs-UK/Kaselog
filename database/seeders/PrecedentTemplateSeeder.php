<?php

namespace Database\Seeders;

use App\Models\PrecedentTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class PrecedentTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // tenant_id is set explicitly, matching the tenancy backfill that
        // assigned all pre-existing templates to Lostock Legal. It can't be
        // left to the BelongsToTenant creating hook: DatabaseSeeder runs
        // WithoutModelEvents (so the hook never fires) and a fresh deploy
        // has no ambient tenant anyway — on a fresh database this seeder
        // crashed with "Field 'tenant_id' doesn't have a default value"
        // until it became explicit.
        $tenant = Tenant::where('slug', 'lostock-legal')->first();

        if (! $tenant) {
            throw new RuntimeException('PrecedentTemplateSeeder: lostock-legal tenant not found — run migrations (which seed tenants) before db:seed.');
        }

        $tenantId = $tenant->id;

        // The 'director' role is a tenant-scoped spatie role — CurrentTenant
        // must be set before querying it, same reasoning as tenant_id above.
        CurrentTenant::set($tenant);
        $createdByUserId = User::role('director')->value('id');
        CurrentTenant::clear();

        $templates = [
            [
                'name' => 'Client Care Letter',
                'template_key' => 'client_care',
                'available_fields' => [
                    'client_name',
                    'client_address',
                    'matter_reference',
                    'solicitor_name',
                    'firm_name',
                    'firm_address',
                    'firm_phone',
                    'firm_email',
                    'sra_number',
                    'date',
                ],
            ],
            [
                'name' => 'Without Prejudice Offer',
                'template_key' => 'without_prejudice',
                'available_fields' => [
                    'client_name',
                    'matter_reference',
                    'solicitor_name',
                    'opponent_name',
                    'offer_amount',
                    'date',
                ],
            ],
            [
                'name' => 'Witness Statement (CPR Part 22)',
                'template_key' => 'witness_statement',
                'available_fields' => [
                    'client_name',
                    'client_address',
                    'matter_reference',
                    'statement_date',
                    'solicitor_name',
                ],
            ],
            [
                'name' => 'Court Application Letter',
                'template_key' => 'court_letter',
                'available_fields' => [
                    'client_name',
                    'matter_reference',
                    'court_name',
                    'hearing_date',
                    'solicitor_name',
                    'firm_name',
                    'firm_address',
                    'date',
                ],
            ],
            [
                'name' => 'Costs Update Letter',
                'template_key' => 'costs_update',
                'available_fields' => [
                    'client_name',
                    'matter_reference',
                    'agreed_fee',
                    'costs_to_date',
                    'solicitor_name',
                    'date',
                ],
            ],
            [
                'name' => 'Expert Instruction Letter (CPR Part 35)',
                'template_key' => 'expert_instruction',
                'available_fields' => [
                    'client_name',
                    'matter_reference',
                    'expert_name',
                    'expert_address',
                    'hearing_date',
                    'solicitor_name',
                    'date',
                ],
            ],
        ];

        foreach ($templates as $template) {
            // allTenants() so the lookup doesn't depend on an ambient
            // tenant either — with none set, the fail-closed TenantScope
            // would never find an existing row and re-seeding would
            // duplicate instead of update.
            PrecedentTemplate::allTenants()->updateOrCreate(
                ['template_key' => $template['template_key'], 'tenant_id' => $tenantId],
                [
                    'name' => $template['name'],
                    'file_path' => "precedent-templates/{$template['template_key']}.docx",
                    'available_fields' => $template['available_fields'],
                    'active' => true,
                    'created_by_user_id' => $createdByUserId,
                ],
            );
        }
    }
}
