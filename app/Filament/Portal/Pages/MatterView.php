<?php

namespace App\Filament\Portal\Pages;

use App\Models\Matter;
use App\Models\MatterDocument;
use App\Models\MatterMessage;
use App\Notifications\ClientDocumentUploadedNotification;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * The client-facing matter page: kaselog.co.uk/{tenant-slug}/{matter-reference}.
 *
 * Section 3 — the previously-minimal reference/status/court-date summary
 * grows a payment plan view, a documents list (+ client upload), and a
 * read-only messages view. Every one of these deliberately reuses $this
 * ->matter (verified once, in mount(), against the authenticated client's
 * own id) rather than re-deriving ownership anywhere else — see mount()'s
 * own comment for why that check exists.
 */
class MatterView extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'matter-view';

    /**
     * Never a navigation item: its route needs {reference}, and Filament's
     * bare-tenant-URL redirect (RedirectToHomeController → the first nav
     * item's URL) would try to generate that URL without one and 500 —
     * audit finding F11. With no nav items, /{tenant-slug} falls back to a
     * plain redirect instead.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.portal.matter-view';

    public Matter $matter;

    public static function getRoutePath(Panel $panel): string
    {
        return '/{reference}';
    }

    public function mount(string $reference): void
    {
        // Tenant isolation is already enforced by Matter's fail-closed
        // TenantScope (BelongsToTenant) — a reference belonging to another
        // tenant simply doesn't exist in this query, so this 404s rather
        // than ever risking a cross-tenant lookup.
        $matter = Matter::where('reference', $reference)->firstOrFail();

        // Tenant scoping alone isn't enough here — within the *same* tenant,
        // one client must not be able to view another client's matter just
        // by guessing/changing the reference in the URL.
        if ($matter->client_id !== auth()->guard('portal')->id()) {
            throw new ModelNotFoundException;
        }

        $this->matter = $matter;

        // A passive read-receipt, not a deliberate action worth its own
        // activity-log entry — a bulk query-builder update (not each
        // message's own save()) deliberately fires no model events, mirroring
        // Section 6's identical reasoning for TimeEntry's bulk invoice-bundle
        // attach.
        $this->matter->matterMessages()
            ->where('visible_to_client', true)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function getTitle(): string
    {
        return $this->matter->reference;
    }

    /**
     * @return Collection<int, MatterMessage>
     */
    public function getVisibleMessages(): Collection
    {
        return $this->matter->matterMessages()
            ->where('visible_to_client', true)
            ->orderBy('created_at')
            ->get();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->documentsQuery())
            ->heading('Documents')
            ->columns([
                TextColumn::make('filename'),
                TextColumn::make('uploaded_by')
                    ->label('Uploaded by')
                    ->state(fn (MatterDocument $record): string => $this->isOwnUpload($record) ? 'You' : $record->uploaded_by_label),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->date(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Action::make('upload')
                    ->label('Upload a document')
                    ->form([
                        FileUpload::make('path')
                            ->label('File')
                            ->required()
                            ->disk('documents')
                            ->directory('matter-docs/'.$this->matter->id)
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'image/jpeg',
                                'image/png',
                            ])
                            ->storeFileNamesIn('filename')
                            ->visibility('private'),
                    ])
                    ->action(function (array $data): void {
                        $this->handleUpload($data);
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->visible(fn (MatterDocument $record): bool => $this->canDownload($record))
                    ->action(function (MatterDocument $record) {
                        // Re-checked here, not just relied on via the table's
                        // own scoped query — see the class docblock and the
                        // upload/canDownload methods' own comments for why
                        // this can never be trusted to the visible list alone.
                        if (! $this->canDownload($record)) {
                            abort(403);
                        }

                        return Storage::disk('documents')->download($record->path, $record->filename);
                    }),
            ])
            ->paginated(false);
    }

    /**
     * A client sees two kinds of document: anything staff has explicitly
     * chosen to share (visible_to_client) — regardless of who originally
     * uploaded it — and anything the client uploaded themselves, which is
     * always visible to them even before/without staff ever setting
     * visible_to_client true. That flag means "staff has shared this with
     * the client"; it was never meant to gate a client's own contribution
     * from the client who made it.
     */
    private function documentsQuery(): Builder
    {
        $clientId = auth()->guard('portal')->id();

        // getQuery() unwraps the relation to a plain Builder — its own
        // matter_id constraint is already baked in (applied when the HasMany
        // instance itself was constructed), and Table::query() requires a
        // real Builder|Closure|null, not a Relation.
        return $this->matter->documents()->getQuery()->where(function (Builder $query) use ($clientId): void {
            $query->where('visible_to_client', true)
                ->orWhere(function (Builder $query) use ($clientId): void {
                    $query->where('uploaded_by_type', 'client')
                        ->where('uploaded_by_id', $clientId);
                });
        });
    }

    private function isOwnUpload(MatterDocument $document): bool
    {
        return $document->uploaded_by_type === 'client'
            && $document->uploaded_by_id === auth()->guard('portal')->id();
    }

    /**
     * The same rule documentsQuery() filters the list to, re-evaluated
     * directly against the record a download/action request names — a
     * table row action resolves its record through the table's own query,
     * which already enforces this, but that's the framework's behaviour,
     * not a security boundary this code should ever take on faith alone.
     */
    private function canDownload(MatterDocument $record): bool
    {
        if ($record->matter_id !== $this->matter->id) {
            return false;
        }

        return $record->visible_to_client || $this->isOwnUpload($record);
    }

    private function handleUpload(array $data): void
    {
        $document = MatterDocument::create([
            'matter_id' => $this->matter->id,
            'uploaded_by_type' => 'client',
            'uploaded_by_id' => auth()->guard('portal')->id(),
            'filename' => $data['filename'],
            'path' => $data['path'],
            'visible_to_client' => false,
        ]);

        Notification::send(
            $this->matter->notifiableStaffUsers(),
            new ClientDocumentUploadedNotification($this->matter, $document),
        );

        FilamentNotification::make()
            ->title('Document uploaded')
            ->success()
            ->send();
    }
}
