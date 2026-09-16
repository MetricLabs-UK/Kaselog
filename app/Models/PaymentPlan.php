<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Enums\InstalmentStatus;
use App\Enums\ReconciliationIssueReason;
use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PaymentPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'matter_id',
    'total_amount',
    'deposit_amount',
    'deposit_paid_at',
    'notes',
    'locked',
])]
class PaymentPlan extends Model
{
    /** @use HasFactory<PaymentPlanFactory> */
    use Archivable, BelongsToTenant, HasFactory, HasReasonedActivityLog, LogsActivity;

    /**
     * Section 20's drift-detection, cascaded from the plan level: editing
     * the terms of a plan that already has instalments sent to the
     * accounting provider means those invoices may no longer match what
     * Kase shows, exactly as much as editing an instalment directly does —
     * see Instalment::flagOutOfSync() for the shared logic this delegates
     * to, and its own updating/updated hooks for the instalment-level case.
     */
    protected static function booted(): void
    {
        static::updated(function (PaymentPlan $plan): void {
            if (! $plan->wasChanged(['total_amount', 'deposit_amount', 'deposit_paid_at'])) {
                return;
            }

            $plan->instalments()
                ->whereNotNull('invoice_id')
                ->with('invoice')
                ->get()
                ->each(fn (Instalment $instalment) => $instalment->invoice?->flagOutOfSync(ReconciliationIssueReason::PaymentPlanEditedAfterSend, $plan->getChanges()));
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payment_plans')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'deposit_paid_at' => 'datetime',
            'locked' => 'boolean',
        ];
    }

    protected function inheritsTenantIdFrom(): array
    {
        return ['matter'];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(Instalment::class);
    }

    public function chaseLogs(): HasManyThrough
    {
        return $this->hasManyThrough(ChaseLog::class, Instalment::class);
    }

    public function getAmountOutstandingAttribute(): string
    {
        $paid = $this->instalments()
            ->where('status', 'paid')
            ->sum('amount');

        return bcsub((string) $this->total_amount, (string) $paid, 2);
    }

    public function hasOverdueInstalments(): bool
    {
        return $this->instalments()
            ->where('status', InstalmentStatus::Pending)
            ->whereNull('paid_at')
            ->where('due_date', '<', now())
            ->exists();
    }
}
