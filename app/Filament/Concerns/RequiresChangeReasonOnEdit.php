<?php

namespace App\Filament\Concerns;

use App\Filament\Support\ChangeReasonField;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;

/**
 * Use on a Resource's EditRecord page to require a "reason for change" before
 * an edit can save. Add ::changeReasonField() to the resource's form schema
 * (it only appears/requires on the edit operation) and this trait strips it
 * out of $data and passes it through to the model's HasReasonedActivityLog
 * trait so it lands on the activity log entry instead of the record itself.
 */
trait RequiresChangeReasonOnEdit
{
    protected ?string $formChangeReason = null;

    public static function changeReasonField(): Textarea
    {
        return ChangeReasonField::make();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->formChangeReason = $data['change_reason'] ?? null;
        unset($data['change_reason']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->updateWithReason($data, $this->formChangeReason);

        return $record;
    }
}
