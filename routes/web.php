<?php

use App\Http\Controllers\AccountingOAuthController;
use App\Http\Controllers\BackupOAuthController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\Webhooks\RetellWebhookController;
use App\Http\Controllers\Webhooks\XeroWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/webhooks/xero', XeroWebhookController::class);
Route::post('/retell/webhook', [RetellWebhookController::class, 'handle']);

// Plain (non-panel) routes — see ImpersonationController's docblock for why
// these can't be Livewire actions inside either panel.
Route::middleware('auth')->group(function () {
    Route::get('/impersonate/{session}/enter', [ImpersonationController::class, 'enter'])->name('impersonation.enter');
    Route::post('/impersonate/leave', [ImpersonationController::class, 'leave'])->name('impersonation.leave');

    // Section 20 — a provider's OAuth redirect back to Kase hits one shared
    // URL regardless of which firm started the flow (see
    // AccountingOAuthController's docblock and App\Support\Accounting\
    // OAuthState for how it still finds its way back to the right tenant).
    Route::get('/integrations/accounting/{provider}/callback', [AccountingOAuthController::class, 'callback'])
        ->name('integrations.accounting.callback');

    // Firm-facing self-service backup (distinct from Section 12's
    // Kase-internal one) — same shared-URL reasoning as the accounting
    // callback above.
    Route::get('/integrations/backups/{provider}/callback', [BackupOAuthController::class, 'callback'])
        ->name('integrations.backups.callback');
});
