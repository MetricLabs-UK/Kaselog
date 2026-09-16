<?php

namespace App\Filament\Admin\Pages;

use App\Enums\LeadStatus;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\Scopes\ExcludeConvertedLeadsScope;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Section 4 — drag-and-drop is wired via Filament's own bundled SortableJS
 * (window.Sortable, loaded by every panel page for table ->reorderable()
 * support — see pipeline.blade.php) rather than a new dependency; there was
 * no existing kanban-drag pattern in this codebase to copy, so the
 * client/server split here is new, but the actual stage-change logic below
 * deliberately reuses LeadResource::canEdit() and the exact
 * update()+cancelPendingNurtureSequences() shape LeadsTable's own
 * markAsLost/convertToClient actions already use — a drag is just a faster
 * way to trigger the same permission-checked, audited change those buttons
 * make, not a parallel path.
 */
class Pipeline extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static ?string $navigationLabel = 'Pipeline';

    protected static string|UnitEnum|null $navigationGroup = 'Leads';

    protected static ?string $slug = 'leads/pipeline';

    protected string $view = 'filament.admin.pages.pipeline';

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['director', 'admin', 'solicitor']);
    }

    /**
     * @return Collection<string, Collection<int, Lead>>
     */
    public function getLeadsByStatus(): Collection
    {
        $user = auth()->user();

        $query = Lead::withoutGlobalScope(ExcludeConvertedLeadsScope::class)
            ->with(['convertedClient']);

        if (! $user->can('view_confidential_records')) {
            $query->where('director_only', false);
        }

        return $query->get()->groupBy(fn (Lead $lead): string => $lead->status->value);
    }

    /**
     * @return array<LeadStatus>
     */
    public function getStatuses(): array
    {
        return LeadStatus::cases();
    }

    public function getConvertedClientUrl(Lead $lead): ?string
    {
        if (blank($lead->converted_to_client_id)) {
            return null;
        }

        return ClientResource::getUrl('view', ['record' => $lead->converted_to_client_id]);
    }

    public static function statusLabel(LeadStatus $status): string
    {
        return ucfirst(str_replace('_', ' ', $status->value));
    }

    /**
     * Whether this lead's card should be draggable at all — a Converted
     * card is a completed outcome with a real Client behind it (see
     * convertToClient()), not something a board reorder should be able to
     * unwind, so it's excluded regardless of permissions. Everything else
     * mirrors LeadResource::canEdit() exactly: the same check that already
     * gates the Edit button for this record gates dragging it.
     */
    public function canDragLead(Lead $lead): bool
    {
        if ($lead->status === LeadStatus::Converted) {
            return false;
        }

        return LeadResource::canEdit($lead);
    }

    /**
     * The server-side handler a card drop actually calls — the client-side
     * SortableJS wiring (pipeline.blade.php) is purely a UX convenience;
     * every rule enforced here would still hold if that JS were bypassed
     * entirely (e.g. a hand-crafted Livewire request). A rejected move never
     * writes anything — the page's next render simply reflects the
     * lead's unchanged real status, which is what snaps the card back.
     */
    public function updateLeadStage(int $leadId, string $newStatus): void
    {
        $status = LeadStatus::tryFrom($newStatus);

        if ($status === null) {
            return;
        }

        $lead = Lead::withoutGlobalScope(ExcludeConvertedLeadsScope::class)->find($leadId);

        if ($lead === null) {
            return;
        }

        if ($lead->director_only && ! auth()->user()->can('view_confidential_records')) {
            return;
        }

        if (! $this->canDragLead($lead)) {
            Notification::make()
                ->title("You don't have permission to move this lead.")
                ->danger()
                ->send();

            return;
        }

        if ($lead->status === $status) {
            return;
        }

        if ($status === LeadStatus::Converted) {
            Notification::make()
                ->title('Use "Convert to Client" to convert a lead')
                ->body('Converting creates a real client record, so it needs the dedicated action rather than a drag.')
                ->warning()
                ->send();

            return;
        }

        $lead->update(['status' => $status]);

        // Mirrors LeadsTable's markAsLost action exactly — a manual move
        // into Lost is the same event that action already represents, just
        // triggered by a drag instead of a button.
        if ($status === LeadStatus::Lost) {
            $lead->cancelPendingNurtureSequences();
        }

        Notification::make()
            ->title('Moved to '.static::statusLabel($status))
            ->success()
            ->send();
    }
}
