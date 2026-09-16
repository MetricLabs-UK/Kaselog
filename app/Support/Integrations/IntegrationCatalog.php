<?php

namespace App\Support\Integrations;

use App\Filament\Admin\Pages\Integrations\AccountingIntegration;
use App\Filament\Admin\Pages\Integrations\BackupIntegration;
use App\Models\AccountingConnection;
use App\Models\BackupExport;
use App\Models\Tenant;
use Closure;

/**
 * The fixed list of integration *types* Settings > Integrations shows —
 * "Accounting" is the only one wired up today (Section 20), but this is
 * deliberately not built narrowly around accounting alone: a future "Files"
 * entry (the Microsoft Graph/SharePoint picker floated in earlier planning)
 * is a second array entry here, not a restructure of this page. Mirrors
 * RolePermissionCatalog's shape — a small code-defined catalog, not a
 * database table, since the set of integration *types* isn't something a
 * firm ever edits.
 *
 * Each entry carries its own `permission` — unlike Accounting (gated on
 * manage_integrations only), Backups is gated on the separate manage_backups
 * permission, so ListIntegrations checks per-entry rather than one blanket
 * page-level permission.
 */
final class IntegrationCatalog
{
    /**
     * @return list<array{key: string, label: string, description: string, page: class-string, permission: string, status: Closure(Tenant): string}>
     */
    public static function entries(): array
    {
        return [
            [
                'key' => 'accounting',
                'label' => 'Accounting',
                'description' => 'Send invoices to your firm\'s accounting software and reconcile payments automatically.',
                'page' => AccountingIntegration::class,
                'permission' => 'manage_integrations',
                'status' => fn (Tenant $tenant): string => self::accountingStatus($tenant),
            ],
            [
                'key' => 'backups',
                'label' => 'Backups',
                'description' => 'Back up your own client data and documents to a zip download or (Phase 3+) your own SharePoint/Google Drive.',
                'page' => BackupIntegration::class,
                'permission' => 'manage_backups',
                'status' => fn (Tenant $tenant): string => self::backupsStatus($tenant),
            ],
        ];
    }

    /**
     * @return list<array{key: string, label: string, description: string, page: class-string, permission: string, status: Closure(Tenant): string}>
     */
    public static function entriesFor(Tenant $tenant): array
    {
        return array_values(array_filter(
            self::entries(),
            fn (array $entry): bool => auth()->user()->can($entry['permission']),
        ));
    }

    private static function accountingStatus(Tenant $tenant): string
    {
        $connection = AccountingConnection::forTenant($tenant);

        if ($connection === null) {
            return 'Not configured';
        }

        if (! $connection->isRealProvider()) {
            return 'Manual (no integration)';
        }

        return 'Connected as '.$connection->provider->getLabel();
    }

    private static function backupsStatus(Tenant $tenant): string
    {
        $count = BackupExport::allTenants()->where('tenant_id', $tenant->id)->count();

        return $count === 0 ? 'No backups requested yet' : "{$count} backup(s) requested";
    }
}
