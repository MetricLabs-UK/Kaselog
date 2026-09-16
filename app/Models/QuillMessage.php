<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Enums\QuillMessageRole;
use App\Enums\QuillMessageStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\QuillMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'quill_conversation_id',
    'role',
    'content',
    'status',
])]
class QuillMessage extends Model
{
    /** @use HasFactory<QuillMessageFactory> */
    use HasFactory, HasReasonedActivityLog, LogsActivity, BelongsToTenant;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('quill_messages')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'role' => QuillMessageRole::class,
            'status' => QuillMessageStatus::class,
        ];
    }

    protected function inheritsTenantIdFrom(): array
    {
        return ['quillConversation'];
    }

    public function quillConversation(): BelongsTo
    {
        return $this->belongsTo(QuillConversation::class);
    }
}
