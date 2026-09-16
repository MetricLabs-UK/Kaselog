<?php

namespace App\Http\Controllers;

use App\Enums\BackupDestinationProvider;
use App\Filament\Admin\Pages\Integrations\BackupIntegration;
use App\Models\BackupDestinationConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Accounting\OAuthState;
use App\Support\Backups\BackupDestinationProviderRegistry;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Mirrors AccountingOAuthController exactly — a plain (non-panel) route
 * because SharePoint's (or Google Drive's) redirect back to Kase hits one
 * shared URL regardless of which firm started the flow. Reuses OAuthState
 * unchanged (it was already provider-agnostic).
 */
class BackupOAuthController extends Controller
{
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $providerKey = BackupDestinationProvider::tryFrom($provider);

        if ($providerKey === null || ! BackupDestinationProviderRegistry::isAvailable($providerKey)) {
            abort(404);
        }

        /** @var User $user */
        $user = Auth::user();

        try {
            $state = OAuthState::verify((string) $request->query('state'), $user);
        } catch (RuntimeException $exception) {
            return $this->failed($exception->getMessage());
        }

        $tenant = Tenant::find($state['tenant_id']);

        if ($tenant === null || ! $user->tenants->contains($tenant)) {
            return $this->failed('This connection attempt does not belong to your firm.');
        }

        $previousTenant = CurrentTenant::get();
        CurrentTenant::set($tenant);

        try {
            if (! $user->can('manage_backups')) {
                return $this->failed('You do not have permission to manage this firm\'s backup destinations.', $tenant);
            }

            $code = $request->query('code');

            if (blank($code)) {
                return $this->failed('Authorization was not completed — no code was returned.', $tenant);
            }

            $connection = BackupDestinationConnection::forTenantAndProvider($tenant, $providerKey)
                ?? new BackupDestinationConnection(['tenant_id' => $tenant->id, 'provider' => $providerKey]);
            $connection->connected_by = $user->id;

            BackupDestinationProviderRegistry::get($providerKey)->handleAuthorizationCallback((string) $code, $connection);

            return redirect()
                ->to(BackupIntegration::getUrl(panel: 'admin', tenant: $tenant))
                ->with('success', "Connected to {$providerKey->getLabel()}.");
        } catch (Throwable $exception) {
            Log::error("Backup destination OAuth callback failed for tenant {$tenant->id}: {$exception->getMessage()}");

            return $this->failed('The connection could not be completed. Please try again.', $tenant);
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }

    private function failed(string $message, ?Tenant $tenant = null): RedirectResponse
    {
        $url = $tenant !== null
            ? BackupIntegration::getUrl(panel: 'admin', tenant: $tenant)
            : '/admin';

        return redirect()->to($url)->with('error', $message);
    }
}
