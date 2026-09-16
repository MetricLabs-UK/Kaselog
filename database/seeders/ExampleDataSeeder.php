<?php

namespace Database\Seeders;

use App\Enums\ClientSource;
use App\Enums\InstalmentStatus;
use App\Enums\LeadStatus;
use App\Enums\MatterStatus;
use App\Models\Client;
use App\Models\Instalment;
use App\Models\Lead;
use App\Models\Matter;
use App\Models\PaymentPlan;
use App\Models\Tenant;
use App\Models\User;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Seeder;

/**
 * Local/dev-only example business data — Clients, Matters, Leads, and
 * Payment Plans/Instalments spanning every payment-status scenario (fully
 * pending, one overdue, badly overdue + suspended, fully paid) — so
 * migrate:fresh --seed leaves something to actually click through, not just
 * the bare director account.
 *
 * Every tenant-scoped value (tenant_id, Matter.reference) is set explicitly
 * rather than left to the model's `creating` hooks: DatabaseSeeder uses
 * WithoutModelEvents, which suppresses those hooks for anything it calls via
 * $this->call(), so relying on them here would silently insert NULLs. That
 * makes this seeder correct whether it's nested under DatabaseSeeder or run
 * standalone via `db:seed --class=ExampleDataSeeder`.
 */
class ExampleDataSeeder extends Seeder
{
    private const TENANTS = [
        'lostock-legal' => [
            'practice_areas' => ['Assault', 'Theft', 'Fraud', 'Drug Possession', 'Public Order Offence', 'Domestic Abuse Defence'],
            'criminal' => true,
            'fee_range' => [750, 2500],
        ],
        'the-motoring-lawyers' => [
            'practice_areas' => ['Drink Driving', 'Speeding', 'Careless Driving', 'No Insurance', 'Totting Up Disqualification', 'Failing to Stop'],
            'criminal' => true,
            'fee_range' => [500, 1800],
        ],
        'the-immigration-lawyers-uk' => [
            'practice_areas' => ['Spouse Visa', 'Asylum Claim', 'Deportation Appeal', 'Indefinite Leave to Remain', 'Student Visa', 'Work Visa Sponsorship'],
            'criminal' => false,
            'fee_range' => [1200, 4000],
        ],
    ];

    private const PORTAL_PASSWORD = 'password';

    private Generator $faker;

    /** @var array<int, int> tenant_id => next matter reference sequence */
    private array $referenceSequence = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('ExampleDataSeeder skipped — only runs in local/testing environments.');

            return;
        }

        if (Client::allTenants()->exists()) {
            $this->command?->warn('ExampleDataSeeder skipped — client records already exist.');

            return;
        }

        $this->faker = FakerFactory::create('en_GB');
        $director = User::query()->first();

        $portalLogins = [];

        foreach (self::TENANTS as $slug => $profile) {
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if (! $tenant) {
                continue;
            }

            $portalLogins = [...$portalLogins, ...$this->seedTenant($tenant, $profile, $director)];
        }

        $this->command?->info('Seeded example Clients, Matters, Leads and Payment Plans across all tenants.');

        if ($portalLogins !== []) {
            $this->command?->warn('Portal test logins (password: '.self::PORTAL_PASSWORD.'):');

            foreach ($portalLogins as $login) {
                $this->command?->warn("  {$login}");
            }
        }
    }

    /**
     * @param  array{practice_areas: array<int, string>, criminal: bool, fee_range: array{0: int, 1: int}}  $profile
     * @return array<int, string> portal-enabled client emails seeded for this tenant
     */
    private function seedTenant(Tenant $tenant, array $profile, ?User $director): array
    {
        $portalLogins = [];

        // Six matters, one each, deliberately covering every payment-chase
        // and matter-lifecycle state so the admin panel has something
        // interesting to click through rather than six identical rows.
        $scenarios = ['pending_start', 'plan_on_track', 'plan_overdue', 'plan_badly_overdue_suspended', 'closed_won_paid', 'lost'];

        foreach ($scenarios as $index => $scenario) {
            $portalEnabled = $index < 2;

            $client = $this->makeClient($tenant, $profile, $portalEnabled);

            if ($portalEnabled) {
                $portalLogins[] = "{$tenant->name}: {$client->email}";
            }

            $matter = $this->makeMatter($tenant, $client, $profile, $director, $scenario);

            $this->applyPaymentScenario($tenant, $matter, $scenario);
        }

        // A handful of pure leads still in the pipeline...
        foreach (['new', 'new', 'contacted', 'lost'] as $status) {
            $this->makeLead($tenant, $profile, $director, LeadStatus::from($status));
        }

        // ...plus one that's just converted, to show the Lead -> Client ->
        // Matter pipeline actually working end to end, not just leads that
        // sit in "new" forever.
        $convertedLead = $this->makeLead($tenant, $profile, $director, LeadStatus::New);
        $convertedClient = $convertedLead->convertToClient();
        $this->makeMatter($tenant, $convertedClient, $profile, $director, 'pending_start', $convertedLead->id);

        return $portalLogins;
    }

    private function makeClient(Tenant $tenant, array $profile, bool $portalEnabled): Client
    {
        $client = new Client([
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->mobileNumber(),
            'address' => "{$this->faker->streetAddress()}, {$this->faker->city()}, {$this->faker->postcode()}",
            'source' => $this->faker->randomElement(ClientSource::cases()),
            'notes' => 'Example seed data for local testing.',
            'portal_enabled' => $portalEnabled,
        ]);
        $client->tenant_id = $tenant->id;

        if ($portalEnabled) {
            $client->password = self::PORTAL_PASSWORD;
        }

        $client->save();

        return $client;
    }

    private function makeLead(Tenant $tenant, array $profile, ?User $director, LeadStatus $status): Lead
    {
        $practiceArea = $this->faker->randomElement($profile['practice_areas']);

        $lead = new Lead([
            'prefix' => $this->faker->randomElement(['Mr', 'Mrs', 'Ms', 'Dr']),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'telephone' => $this->faker->phoneNumber(),
            'mobile' => $this->faker->mobileNumber(),
            'source' => $this->faker->randomElement(ClientSource::cases()),
            'campaign_source' => $this->faker->optional(0.5)->randomElement(['google_ads', 'facebook_ads', 'organic_search']),
            'practice_area' => $practiceArea,
            'message' => "Enquiry regarding a {$practiceArea} matter — found us online and would like a callback to discuss next steps.",
            'status' => $status,
            'assigned_user_id' => $director?->id,
            'last_contacted_at' => $status === LeadStatus::Contacted ? now()->subDays(2) : null,
        ]);
        $lead->tenant_id = $tenant->id;
        $lead->save();

        return $lead;
    }

    private function makeMatter(Tenant $tenant, Client $client, array $profile, ?User $director, string $scenario, ?int $leadId = null): Matter
    {
        $practiceArea = $this->faker->randomElement($profile['practice_areas']);
        $status = match ($scenario) {
            'plan_badly_overdue_suspended' => MatterStatus::Suspended,
            'closed_won_paid' => MatterStatus::Won,
            'lost' => MatterStatus::Lost,
            default => MatterStatus::Active,
        };

        $matter = new Matter([
            'client_id' => $client->id,
            'title' => "{$practiceArea} — {$client->last_name}",
            'practice_area' => $practiceArea,
            'status' => $status,
            'assigned_user_id' => $director?->id,
            'lead_id' => $leadId,
            'notes' => 'Example seed data for local testing.',
            'instruction_date' => now()->subDays($this->faker->numberBetween(10, 120)),
            'agreed_fee' => $this->faker->numberBetween(...$profile['fee_range']),
            'client_care_sent' => true,
            'aml_verified' => true,
            'conflict_checked' => true,
        ]);

        if ($profile['criminal']) {
            $matter->offence_date = now()->subDays($this->faker->numberBetween(30, 150));
            $matter->hearing_type = $this->faker->randomElement(['First hearing', 'Trial', 'Sentencing']);

            if ($status === MatterStatus::Active || $status === MatterStatus::Suspended) {
                $matter->court_date = now()->addDays($this->faker->numberBetween(14, 90));
                $matter->court_name = $tenant->name === 'The Motoring Lawyers'
                    ? $this->faker->city().' Magistrates\' Court'
                    : $this->faker->city().' Crown Court';
            }

            if (in_array($status, [MatterStatus::Won, MatterStatus::Lost], true)) {
                $matter->closed_date = now()->subDays($this->faker->numberBetween(1, 20));
                $matter->outcome = $status === MatterStatus::Won ? 'acquitted' : 'convicted';
            }
        } elseif (in_array($status, [MatterStatus::Won, MatterStatus::Lost], true)) {
            $matter->closed_date = now()->subDays($this->faker->numberBetween(1, 20));
        }

        $matter->tenant_id = $tenant->id;
        $matter->reference = $this->nextReference($tenant);
        $matter->save();

        return $matter;
    }

    private function nextReference(Tenant $tenant): string
    {
        $this->referenceSequence[$tenant->id] ??= 1;

        $reference = sprintf('%s-%d-%03d', $tenant->reference_prefix, now()->year, $this->referenceSequence[$tenant->id]);
        $this->referenceSequence[$tenant->id]++;

        return $reference;
    }

    private function applyPaymentScenario(Tenant $tenant, Matter $matter, string $scenario): void
    {
        if ($scenario === 'lost' || $matter->agreed_fee === null) {
            return;
        }

        $total = (float) $matter->agreed_fee;
        $deposit = round($total * 0.25, 2);
        $remaining = round($total - $deposit, 2);
        $instalmentAmount = round($remaining / 3, 2);

        $plan = new PaymentPlan([
            'matter_id' => $matter->id,
            'total_amount' => $total,
            'deposit_amount' => $deposit,
            'deposit_paid_at' => $scenario === 'pending_start' ? null : now()->subDays(30),
        ]);
        $plan->tenant_id = $tenant->id;
        $plan->save();

        $dueDates = match ($scenario) {
            'pending_start' => [now()->addDays(14), now()->addDays(44), now()->addDays(74)],
            'plan_on_track' => [now()->subDays(20), now()->addDays(10), now()->addDays(40)],
            'plan_overdue' => [now()->subDays(20), now()->subDays(12), now()->addDays(18)],
            'plan_badly_overdue_suspended' => [now()->subDays(50), now()->subDays(25), now()->addDays(5)],
            'closed_won_paid' => [now()->subDays(60), now()->subDays(30), now()->subDays(5)],
            default => [now()->addDays(14), now()->addDays(44), now()->addDays(74)],
        };

        $statuses = match ($scenario) {
            'pending_start' => [InstalmentStatus::Pending, InstalmentStatus::Pending, InstalmentStatus::Pending],
            'plan_on_track' => [InstalmentStatus::Paid, InstalmentStatus::Pending, InstalmentStatus::Pending],
            'plan_overdue', 'plan_badly_overdue_suspended' => [InstalmentStatus::Paid, InstalmentStatus::Pending, InstalmentStatus::Pending],
            'closed_won_paid' => [InstalmentStatus::Paid, InstalmentStatus::Paid, InstalmentStatus::Paid],
            default => [InstalmentStatus::Pending, InstalmentStatus::Pending, InstalmentStatus::Pending],
        };

        foreach ($dueDates as $i => $dueDate) {
            $amount = $i === 2 ? round($remaining - ($instalmentAmount * 2), 2) : $instalmentAmount;
            $status = $statuses[$i];

            $instalment = new Instalment([
                'payment_plan_id' => $plan->id,
                'amount' => $amount,
                'due_date' => $dueDate,
                'paid_at' => $status === InstalmentStatus::Paid ? $dueDate : null,
                'status' => $status,
            ]);
            $instalment->tenant_id = $tenant->id;
            $instalment->save();
        }
    }
}
