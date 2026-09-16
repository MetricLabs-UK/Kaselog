<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ExcludeArchivedScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A closed Matter (or a lost Lead, etc.) is a status value — archiving is a
 * separate, additional action on top of whatever status a record already
 * has, not a replacement for one. archive()/restore() log their own
 * distinct 'archived'/'restored' activity event (via saveQuietly() +
 * a manual activity() call) rather than letting the model's normal
 * LogsActivity save-hook log it as a generic 'updated' diff — "who archived
 * this and when" needs to read as itself in the audit trail, not need
 * inferring from an attributes-changed row.
 */
trait Archivable
{
    protected static function bootArchivable(): void
    {
        static::addGlobalScope(new ExcludeArchivedScope);
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(User $user): void
    {
        $this->forceFill([
            'archived_at' => now(),
            'archived_by' => $user->id,
        ])->saveQuietly();

        $this->logArchiveActivity('archived');
    }

    public function restore(): void
    {
        $this->forceFill([
            'archived_at' => null,
            'archived_by' => null,
        ])->saveQuietly();

        $this->logArchiveActivity('restored');
    }

    /**
     * Bypasses the default exclude-archived scope entirely (both archived
     * and non-archived rows) — for the "Archived" resource views (a
     * dedicated ->onlyArchived() would only be needed if something wants
     * *just* archived rows without also being able to see live ones on the
     * same query builder instance).
     */
    public static function withArchived(): Builder
    {
        return static::withoutGlobalScope(ExcludeArchivedScope::class);
    }

    private function logArchiveActivity(string $event): void
    {
        $logName = method_exists($this, 'getActivitylogOptions')
            ? $this->getActivitylogOptions()->logName
            : null;

        activity($logName)
            ->performedOn($this)
            ->causedBy(auth()->user())
            ->tap(function ($activity): void {
                if (isset($this->tenant_id)) {
                    $activity->tenant_id = $this->tenant_id;
                }
            })
            ->event($event)
            ->log($event === 'archived' ? 'Record archived' : 'Record restored');
    }
}
