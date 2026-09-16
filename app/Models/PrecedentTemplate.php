<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Enums\PrecedentTemplateType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\TenantScope;
use Database\Factories\PrecedentTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

// backed_up_at (Section 12) is deliberately not listed — only
// App\Console\Commands\SyncDocumentsToSharePoint sets it, via forceFill().
// is_master/adopted_from_id are fillable so the Hub library form and
// PrecedentTemplateAdoptionService can set them directly — no tenant-side
// form field ever exposes either, so mass assignment from ordinary Filament
// forms can't touch them.
#[Fillable([
    'name',
    'description',
    'template_key',
    'type',
    'file_path',
    'content',
    'is_master',
    'adopted_from_id',
    'folder_id',
    'available_fields',
    'active',
    'created_by_user_id',
])]
class PrecedentTemplate extends Model
{
    /** @use HasFactory<PrecedentTemplateFactory> */
    use BelongsToTenant, HasFactory, HasReasonedActivityLog, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('precedent_templates')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (PrecedentTemplate $template): void {
            if (blank($template->created_by_user_id) && auth()->check()) {
                $template->created_by_user_id = auth()->id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => PrecedentTemplateType::class,
            'available_fields' => 'array',
            'active' => 'boolean',
            'is_master' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(PrecedentTemplateFolder::class, 'folder_id');
    }

    /**
     * The library master this row was adopted from — null for a master
     * itself, or for a template a firm authored from scratch. The master
     * has no tenant_id of its own, so the ordinary TenantScope (filtering to
     * this record's own tenant) would always resolve it to null; dropped
     * deliberately, same reasoning as adoptedCopies().
     */
    public function adoptedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'adopted_from_id')->withoutGlobalScope(TenantScope::class);
    }

    /**
     * A master's per-firm adopted copies, spanning every tenant that has
     * adopted it — the TenantScope global scope is dropped deliberately,
     * mirroring the allTenants() escape hatch, since a single master's
     * copies never share one tenant_id.
     */
    public function adoptedCopies(): HasMany
    {
        return $this->hasMany(self::class, 'adopted_from_id')->withoutGlobalScope(TenantScope::class);
    }
}
