<?php

namespace App\Jobs;

use App\Models\AccountingConnection;
use App\Support\Accounting\AccountingProviderRegistry;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Section 20 — access tokens last 30 minutes and refresh tokens rotate and
 * expire after 60 days of non-use. Every real API call already refreshes
 * on-demand (AccountingProviderContract::ensureFreshToken(), called from
 * every method that hits the provider's API), but a firm that hasn't sent
 * an instalment in a while would otherwise drift toward that 60-day cliff
 * with nothing ever calling it — this sweep refreshes every connected
 * tenant daily regardless of use, so that never happens. Deliberately
 * unconditional per connection (not "only if near expiry"): a 30-minute
 * access token is stale on essentially every daily run anyway, and
 * ensureFreshToken() is already a no-op when it isn't.
 */
class RefreshAccountingConnections implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $previousTenant = CurrentTenant::get();

        try {
            AccountingConnection::allTenants()
                ->get()
                ->filter(fn (AccountingConnection $connection) => $connection->isRealProvider())
                ->each(function (AccountingConnection $connection): void {
                    CurrentTenant::set($connection->tenant);

                    try {
                        AccountingProviderRegistry::get($connection->provider)->ensureFreshToken($connection);
                    } catch (Throwable $exception) {
                        Log::error("Failed to refresh accounting connection for tenant {$connection->tenant_id}: {$exception->getMessage()}");
                    }
                });
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }
}
