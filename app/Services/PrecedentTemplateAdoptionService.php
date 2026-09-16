<?php

namespace App\Services;

use App\Enums\PrecedentTemplateType;
use App\Models\PrecedentTemplate;
use App\Models\PrecedentTemplateFolder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * "Adopting" a master template gives the current tenant its own independent
 * copy — own id, own row, own file (for docx_upload) or own content column
 * (for rich_text) — editing it never touches the master or any other
 * tenant's copy. Deliberately keeps the master's name/template_key verbatim
 * (no "(1)"-style suffixing): a firm adopting the library's "Client Care
 * Letter" ends up with a template that's still just called that.
 */
class PrecedentTemplateAdoptionService
{
    /**
     * Idempotent per tenant — adopting a master a second time returns the
     * existing copy rather than creating a duplicate, since the unique
     * [tenant_id, template_key] pair (reusing the master's key verbatim)
     * would only ever allow one anyway.
     */
    public function adopt(PrecedentTemplate $master, ?PrecedentTemplateFolder $folder = null): PrecedentTemplate
    {
        if (! $master->is_master) {
            throw new InvalidArgumentException('Only a library master template can be adopted.');
        }

        $existing = PrecedentTemplate::where('adopted_from_id', $master->id)->first();

        if ($existing) {
            return $existing;
        }

        return PrecedentTemplate::create([
            'name' => $master->name,
            'description' => $master->description,
            'template_key' => $master->template_key,
            'type' => $master->type,
            'file_path' => $master->type === PrecedentTemplateType::DocxUpload
                ? $this->copyMasterFile($master)
                : null,
            'content' => $master->content,
            'available_fields' => $master->available_fields ?? [],
            'active' => true,
            'is_master' => false,
            'adopted_from_id' => $master->id,
            'folder_id' => $folder?->id,
        ]);
    }

    /**
     * A physically independent file from creation, not just a shared path —
     * so nothing the tenant later does to their copy's file (or a future
     * deletion of the master) can ever reach the master's original.
     */
    private function copyMasterFile(PrecedentTemplate $master): string
    {
        $disk = Storage::disk('documents');
        $extension = pathinfo($master->file_path, PATHINFO_EXTENSION) ?: 'docx';
        $newPath = 'precedent-templates/'.Str::uuid().'.'.$extension;

        $disk->copy($master->file_path, $newPath);

        return $newPath;
    }
}
