<?php

namespace App\Models;

use App\Enums\BackupExportDestination;
use App\Enums\BackupExportScope;
use App\Enums\BackupExportStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Section 15-adjacent, firm-facing self-service backup (distinct from
 * Section 12's Kase-internal one) — one row per requested export, whether a
 * single client's bundle, a whole-firm one, or (Phase 6) an unattended daily
 * push. Every export goes through the same queued GenerateBackupExport job
 * regardless of size, so there's exactly one code path to reason about.
 */
#[Fillable([
    'requested_by_user_id',
    'scope',
    'client_id',
    'destination',
    'status',
    'file_path',
    'file_size',
    'failed_reason',
    'completed_at',
    'expires_at',
])]
class BackupExport extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'scope' => BackupExportScope::class,
            'destination' => BackupExportDestination::class,
            'status' => BackupExportStatus::class,
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Downloadable exactly when a file actually exists to download and the
     * retention window hasn't lapsed yet — the daily cleanup command deletes
     * both the row and the file together, but this covers the gap between
     * "expired" and "actually swept".
     */
    public function isDownloadable(): bool
    {
        return $this->status === BackupExportStatus::Completed
            && $this->file_path !== null
            && ! $this->isExpired();
    }

    public function markProcessing(): void
    {
        $this->forceFill(['status' => BackupExportStatus::Processing])->saveQuietly();
    }

    /**
     * $filePath is null for a Phase 5 cloud-pushed export — nothing local
     * is kept once it's safely uploaded, so there's nothing to expire
     * either (expires_at only makes sense for a file actually sitting on
     * the 'exports' disk).
     */
    public function markCompleted(?string $filePath, int $fileSize): void
    {
        $this->forceFill([
            'status' => BackupExportStatus::Completed,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'completed_at' => now(),
            'expires_at' => $filePath !== null ? now()->addDays(7) : null,
        ])->saveQuietly();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => BackupExportStatus::Failed,
            'failed_reason' => $reason,
            'completed_at' => now(),
        ])->saveQuietly();
    }
}
