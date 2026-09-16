<?php

namespace App\Filament\Admin\Resources\AuditLog\Pages;

use App\Filament\Admin\Resources\AuditLog\AuditLogResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAuditLog extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    /**
     * One tab per audited category. Add a new entry here (matching the
     * useLogName() set on that model's getActivitylogOptions(), or the
     * activity('log_name') call for a non-model event like auth/permissions)
     * whenever another resource adopts LogsActivity.
     */
    public function getTabs(): array
    {
        return [
            'matters' => Tab::make('Matters')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('matters')),
            'clients' => Tab::make('Clients')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('clients')),
            'leads' => Tab::make('Leads')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('leads')),
            'payment_plans' => Tab::make('Payment Plans')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('payment_plans')),
            'instalments' => Tab::make('Instalments')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('instalments')),
            'chase_logs' => Tab::make('Chase Logs')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('chase_logs')),
            'sms' => Tab::make('SMS')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('sms')),
            'time_entries' => Tab::make('Time Entries')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('time_entries')),
            'matter_documents' => Tab::make('Matter Documents')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('matter_documents')),
            'generated_documents' => Tab::make('Generated Documents')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('generated_documents')),
            'precedent_templates' => Tab::make('Precedent Templates')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('precedent_templates')),
            'matter_messages' => Tab::make('Matter Messages')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('matter_messages')),
            'call_notes' => Tab::make('Call Notes')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('call_notes')),
            'nurture_sequences' => Tab::make('Nurture Sequences')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('nurture_sequences')),
            'retell_call_logs' => Tab::make('Retell Call Logs')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('retell_call_logs')),
            'quill_conversations' => Tab::make('Quill Conversations')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('quill_conversations')),
            'quill_messages' => Tab::make('Quill Messages')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('quill_messages')),
            'document_ai_summaries' => Tab::make('Document AI Summaries')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('document_ai_summaries')),
            'users' => Tab::make('Users')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('users')),
            'auth' => Tab::make('Logins')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('auth')),
            'permissions' => Tab::make('Roles & Permissions')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('permissions')),
            'access_denied' => Tab::make('Access Denials')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('access_denied')),
        ];
    }
}
