<?php

use App\Http\Middleware\ForceHttps;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'webhooks/xero',
            'retell/webhook',
        ]);

        // Global, not ->web(): Filament panels build their own middleware
        // stacks directly on their routes rather than going through the
        // 'web' group, so a group-scoped redirect wouldn't cover them.
        // Prepended so it runs before anything else (session, CSRF, ...) —
        // no point doing that work for a request about to be redirected.
        $middleware->prepend(ForceHttps::class);

        // NOT YET CONFIGURED — hosting isn't decided yet. If this app ends
        // up behind a reverse proxy or load balancer that terminates TLS
        // (the request Laravel sees is plain HTTP even though the client
        // used HTTPS), this MUST be set to that proxy's real IP(s)/CIDR
        // range before deploying — otherwise Laravel doesn't trust its
        // X-Forwarded-Proto header, $request->secure() always reports
        // false, and ForceHttps above redirect-loops forever. '*' trusts
        // every proxy in the chain, which is only safe if this app is
        // NEVER reachable except through that trusted, TLS-terminating
        // proxy — trusting X-Forwarded-* from an untrusted source lets a
        // client spoof its own scheme/IP.
        // $middleware->trustProxies(at: ['<proxy IP or CIDR here>']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
