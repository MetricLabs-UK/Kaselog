<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MatterDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

// backed_up_at (Section 12) is deliberately not listed — only
// App\Console\Commands\SyncDocumentsToSharePoint sets it, via forceFill().
#[Fillable([
    'matter_id',
    'uploaded_by_type',
    'uploaded_by_id',
    'filename',
    'path',
    'visible_to_client',
    'locked',
])]
class MatterDocument extends Model
{
    /** @use HasFactory<MatterDocumentFactory> */
    use HasFactory, HasReasonedActivityLog, LogsActivity, BelongsToTenant;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('matter_documents')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'visible_to_client' => 'boolean',
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

    public function aiSummary(): HasOne
    {
        return $this->hasOne(DocumentAiSummary::class);
    }

    public function isPdf(): bool
    {
        return str_ends_with(strtolower($this->filename), '.pdf');
    }

    public function getUploadedByLabelAttribute(): string
    {
        return match ($this->uploaded_by_type) {
            'user' => User::find($this->uploaded_by_id)?->name ?? 'Unknown user',
            'client' => Client::find($this->uploaded_by_id)?->full_name ?? 'Unknown client',
            default => 'Unknown',
        };
    }
}
