<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\QuillConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'matter_id',
    'created_by_user_id',
])]
class QuillConversation extends Model
{
    /** @use HasFactory<QuillConversationFactory> */
    use HasFactory, HasReasonedActivityLog, LogsActivity, BelongsToTenant;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('quill_conversations')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function inheritsTenantIdFrom(): array
    {
        return ['matter'];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(QuillMessage::class);
    }
}
