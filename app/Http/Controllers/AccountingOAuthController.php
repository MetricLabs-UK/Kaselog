<?php

namespace App\Http\Controllers;

use App\Enums\AccountingProviderKey;
use App\Filament\Admin\Pages\Integrations\AccountingIntegration;
use App\Models\AccountingConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Accounting\AccountingProviderRegistry;
use App\Support\Accounting\OAuthState;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Plain (non-Filament-panel) route — Xero's (or a future provider's)
 * redirect back to Kase hits one shared URL regardless of which firm
 * started the flow, so it can't be a Livewire action inside a specific
 * tenant's Admin panel session the way the "Connect" button itself is. Same
 * reasoning as ImpersonationController's cross-panel routes — see
 * routes/web.php.
 */
class AccountingOAuthController extends Controller
{
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $providerKey = AccountingProviderKey::tryFrom($provider);

        if ($providerKey === null || ! $providerKey->isRealProvider()) {
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
            if (! $user->can('manage_integrations')) {
                return $this->failed('You do not have permission to manage this firm\'s integrations.', $tenant);
            }

            $code = $request->query('code');

            if (blank($code)) {
                return $this->failed('Xero did not return an authorization code — the connection was not completed.', $tenant);
            }

            $connection = AccountingConnection::forTenant($tenant) ?? new AccountingConnection(['tenant_id' => $tenant->id]);
            $connection->connected_by = $user->id;

            AccountingProviderRegistry::get($providerKey)->handleAuthorizationCallback((string) $code, $connection);

            return redirect()
                ->to(AccountingIntegration::getUrl(panel: 'admin', tenant: $tenant))
                ->with('success', "Connected to {$providerKey->getLabel()}.");
        } catch (Throwable $exception) {
            Log::error("Accounting OAuth callback failed for tenant {$tenant->id}: {$exception->getMessage()}");

            return $this->failed('The connection could not be completed. Please try again.', $tenant);
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }

    private function failed(string $message, ?Tenant $tenant = null): RedirectResponse
    {
        $url = $tenant !== null
            ? AccountingIntegration::getUrl(panel: 'admin', tenant: $tenant)
            : '/admin';

        return redirect()->to($url)->with('error', $message);
    }
}
