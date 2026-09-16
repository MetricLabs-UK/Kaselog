<?php

namespace App\Filament\Hub\Widgets;

use App\Models\Activity;
use App\Support\Hub\HubAccess;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Section 18 item 4 — failed-login monitoring. Cross-firm by design: a
 * firm's own Admin audit log already shows that firm's failed logins (the
 * "Logins" tab), but nothing anywhere previously surfaced a pattern spanning
 * *multiple* firms (e.g. one IP/email hammering several tenants' login
 * pages) — that's the actual gap this closes. Read-only monitoring, not an
 * account-lockout mechanism.
 *
 * Director-only, same as HubAuditLogResource: "security/audit logs" is one
 * of the categories the Section 18 Sales/Director split explicitly withholds
 * from hub_sales.
 */
class FailedLoginStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return auth()->user()->hasHubPermission(HubAccess::PERMISSION_VIEW_AUDIT_LOG);
    }

    protected function getStats(): array
    {
        $since = now()->subDay();

        $emailCounts = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'failed_login')
            ->where('created_at', '>=', $since)
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(properties, '$.email')) as email, count(*) as attempts")
            ->groupBy('email')
            ->orderByDesc('attempts')
            ->get();

        $topAccount = $emailCounts->first();
        $topAttempts = $topAccount->attempts ?? 0;

        return [
            Stat::make('Failed Logins (24h)', (string) $emailCounts->sum('attempts'))
                ->color($emailCounts->sum('attempts') > 0 ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedExclamationTriangle),

            Stat::make('Accounts Targeted (24h)', (string) $emailCounts->count())
                ->color('gray')
                ->icon(Heroicon::OutlinedUserGroup),

            Stat::make(
                'Most Attempts on One Account (24h)',
                $topAccount ? "{$topAttempts} — {$topAccount->email}" : '0',
            )
                ->color($topAttempts >= 5 ? 'danger' : 'gray')
                ->icon(Heroicon::OutlinedShieldExclamation),
        ];
    }
}
