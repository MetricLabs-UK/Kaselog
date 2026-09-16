<?php

namespace App\Listeners;

use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Covers the firm-facing admin panel and the Hub panel (guard 'web', shared
 * by both — see AdminPanelProvider/HubPanelProvider, neither declares its
 * own ->authGuard()). Deliberately excludes the client portal (guard
 * 'portal') — out of scope per the request that introduced this.
 *
 * Never logs a password or credential value — Failed::$credentials is
 * #[SensitiveParameter] in Laravel's own event for exactly that reason; only
 * the email key is ever read out of it here.
 */
class LogAuthenticationActivity
{
    private const GUARD = 'web';

    public function handleLogin(Login $event): void
    {
        if ($event->guard !== self::GUARD) {
            return;
        }

        $this->log('login', 'User logged in', $event->user, $this->emailOf($event->user));
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->guard !== self::GUARD) {
            return;
        }

        $this->log('logout', 'User logged out', $event->user, $this->emailOf($event->user));
    }

    public function handleFailed(Failed $event): void
    {
        if ($event->guard !== self::GUARD) {
            return;
        }

        $this->log('failed_login', 'Failed login attempt', $event->user, $event->credentials['email'] ?? null);
    }

    private function emailOf(?Authenticatable $user): ?string
    {
        return $user->email ?? null;
    }

    private function log(string $event, string $description, ?Authenticatable $user, ?string $email): void
    {
        $panel = Filament::getCurrentPanel();

        activity('auth')
            ->causedBy($user instanceof Model ? $user : null)
            ->withProperties(array_filter([
                'email' => $email,
                'panel' => $panel?->getId(),
                'ip' => request()?->ip(),
            ], fn (mixed $value): bool => $value !== null))
            ->tap(function (Activity $activity): void {
                $tenant = Filament::getTenant();

                if ($tenant instanceof Tenant) {
                    $activity->tenant_id = $tenant->id;
                }
            })
            ->event($event)
            ->log($description);
    }
}
