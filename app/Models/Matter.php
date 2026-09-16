<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Enums\MatterOutcome;
use App\Enums\MatterPlea;
use App\Enums\MatterStatus;
use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\ExcludeConvertedLeadsScope;
use Database\Factories\MatterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\PermissionRegistrar;

#[Fillable([
    'client_id',
    'reference',
    'title',
    'practice_area',
    'urn',
    'court_date',
    'court_name',
    'status',
    'assigned_user_id',
    'supervising_user_id',
    'lead_id',
    'source',
    'hearing_type',
    'offence_date',
    'offence_location',
    'plea',
    'outcome',
    'sentence',
    'instruction_date',
    'limitation_date',
    'closed_date',
    'agreed_fee',
    'client_care_sent',
    'aml_verified',
    'conflict_checked',
    'gdpr_sent',
    'costs_updated',
    'file_review_done',
    'notes',
    'locked',
    'director_only',
])]
class Matter extends Model
{
    /** @use HasFactory<MatterFactory> */
    use Archivable, BelongsToTenant, HasFactory, HasReasonedActivityLog, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('matters')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (Matter $matter): void {
            if (blank($matter->reference) && $matter->tenant_id) {
                $matter->reference = static::generateReference($matter->tenant_id);
            }
        });
    }

    /**
     * A fresh matter with no reference yet generates one in the creating
     * hook — wrap that save in a transaction so the tenant-row lock taken
     * by generateReference() stays held until this row is committed.
     * Without it, two staff creating a matter for the same brand at the
     * same moment could read the same max sequence and collide on the
     * unique reference index.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists || filled($this->reference)) {
            return parent::save($options);
        }

        return DB::transaction(fn (): bool => parent::save($options));
    }

    /**
     * Case references are scoped per tenant — each brand has its own prefix
     * and its own sequence, not a shared global counter.
     */
    public static function generateReference(int $tenantId): string
    {
        $year = now()->year;

        // lockForUpdate() on the tenant row serialises generation per
        // tenant: save() above wraps a fresh matter's insert in a
        // transaction, so the lock is held from this read until the new
        // reference is committed — a concurrent create for the same brand
        // blocks here rather than reading the same max sequence.
        $prefix = Tenant::query()->whereKey($tenantId)->lockForUpdate()->first()?->reference_prefix ?? 'CS';

        $lastSequence = static::allTenants()
            ->where('tenant_id', $tenantId)
            ->where('reference', 'like', "{$prefix}-{$year}-%")
            ->pluck('reference')
            ->map(fn (string $reference) => (int) Str::afterLast($reference, '-'))
            ->max();

        $nextSequence = ((int) $lastSequence) + 1;

        return sprintf('%s-%d-%03d', $prefix, $year, $nextSequence);
    }

    protected function casts(): array
    {
        return [
            'court_date' => 'date',
            'status' => MatterStatus::class,
            'offence_date' => 'date',
            'plea' => MatterPlea::class,
            'outcome' => MatterOutcome::class,
            'instruction_date' => 'date',
            'limitation_date' => 'date',
            'closed_date' => 'date',
            'agreed_fee' => 'decimal:2',
            'client_care_sent' => 'boolean',
            'aml_verified' => 'boolean',
            'conflict_checked' => 'boolean',
            'gdpr_sent' => 'boolean',
            'costs_updated' => 'date',
            'file_review_done' => 'boolean',
            'locked' => 'boolean',
            'director_only' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function supervisingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervising_user_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withoutGlobalScope(ExcludeConvertedLeadsScope::class);
    }

    public function paymentPlan(): HasOne
    {
        return $this->hasOne(PaymentPlan::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MatterDocument::class);
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    public function matterMessages(): HasMany
    {
        return $this->hasMany(MatterMessage::class);
    }

    public function callNotes(): HasMany
    {
        return $this->hasMany(CallNote::class);
    }

    /**
     * Single conversation per matter — Quill doesn't expose multi-thread
     * chat in this pass. latest() picks the most recent if more than one
     * somehow exists (e.g. a future multi-thread UI).
     */
    public function quillConversation(): HasOne
    {
        return $this->hasOne(QuillConversation::class)->latestOfMany();
    }

    /**
     * Section 3 — who to notify about something a client did on this matter
     * (currently: uploading a document). Prefers the assigned/supervising
     * user; falls back to every director for this matter's tenant if
     * neither is set, so the notification never silently goes nowhere.
     *
     * The explicit team-id swap is required here specifically because the
     * caller is very likely the client Portal, which — unlike the Admin
     * panel's SyncTenantPermissionsTeam middleware — has nothing that keeps
     * spatie's ambient permissions team id in sync with the current tenant;
     * User::role('director') would otherwise silently evaluate against
     * whatever team id happened to be left over, not this matter's tenant.
     *
     * @return Collection<int, User>
     */
    public function notifiableStaffUsers(): Collection
    {
        $direct = collect([$this->assignedUser, $this->supervisingUser])
            ->filter()
            ->unique('id');

        if ($direct->isNotEmpty()) {
            return $direct->values();
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($this->tenant_id);

        try {
            return User::role('director')->get();
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
