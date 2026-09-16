<?php

namespace App\Concerns;

use Lab404\Impersonate\Services\ImpersonateManager;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Pairs with Spatie's LogsActivity trait to attach a mandatory "reason for
 * change" to the activity log entry for an update, without adding a real
 * database column for it. Set $changeReason on the model before saving (see
 * App\Filament\Concerns\RequiresChangeReasonOnEdit) and it will be picked up
 * by tapActivity() the same way for every model that uses this trait.
 *
 * This trait is also the ONLY place activity_log.tenant_id gets populated —
 * Spatie's own writer knows nothing about tenancy. Any model that uses
 * LogsActivity without this trait writes NULL-tenant rows, and because the
 * Audit Log UI is tenant-scoped and fail-closed (AuditLogResource), those
 * rows silently never appear anywhere. So: every model that adds
 * LogsActivity MUST also use HasReasonedActivityLog — enforced by
 * ActivityLogTenantStampingTest, which fails the build if a model has one
 * trait without the other.
 *
 * Also the single place activity_log.properties.impersonator_id gets
 * populated (Section 19). During an active impersonation session,
 * auth()->user() genuinely IS the target — that's the point, so they see
 * their own permissions and data — which means causer_id on every activity
 * logged during the session is, correctly, the target's id. Without this,
 * the audit trail would silently attribute a support director's actions to
 * the customer. This stamps the real actor alongside causer_id rather than
 * replacing it: "what happened to this record" still reads as the target's
 * own history, and "who was actually driving" is recoverable from
 * properties.impersonator_id. See ImpersonationSession's own activity
 * events for the impersonation session's lifecycle itself (requested,
 * accepted, ended, ...), which aren't affected by this — those already
 * causedBy() the correct real actor directly.
 */
trait HasReasonedActivityLog
{
    public ?string $changeReason = null;

    public function tapActivity(Activity $activity, string $eventName): void
    {
        if (filled($this->changeReason)) {
            $activity->properties = $activity->properties->put('reason', $this->changeReason);
        }

        if (isset($this->tenant_id)) {
            $activity->tenant_id = $this->tenant_id;
        }

        $impersonateManager = app(ImpersonateManager::class);

        if ($impersonateManager->isImpersonating()) {
            $activity->properties = $activity->properties->put('impersonator_id', $impersonateManager->getImpersonatorId());
        }
    }

    public function updateWithReason(array $data, ?string $reason): bool
    {
        $this->changeReason = $reason;

        return $this->update($data);
    }
}
