<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * App\Http\Middleware\ForceHttps (bootstrap/app.php, global — not ->web(),
 * since Filament panels build their own middleware stacks directly on their
 * routes) redirects a plain-http request once APP_URL stops looking local;
 * config/session.php's cookie 'secure' derives from the same check.
 * Manually verified end-to-end against a real running server for this same
 * behaviour (see the session this was built in); these pin it as a
 * permanent regression check.
 *
 * The env override happens in setUp(), *before* parent::setUp() boots a
 * fresh application for this test — config/session.php's 'secure' key is
 * computed once at that boot, so overriding APP_URL from inside a test
 * method (after boot) would be too late for the cookie assertions, even
 * though it's early enough for the middleware ones (which re-read it live
 * per request). See HttpsEnforcementLocalTest for the local-APP_URL control
 * cases, kept in a separate class for the same reason — they must NOT run
 * under this override.
 */
class HttpsEnforcementTest extends TestCase
{
    protected function setUp(): void
    {
        $this->setAppUrl('https://app.kaselog.example');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->setAppUrl('http://counselstone.test');

        parent::tearDown();
    }

    private function setAppUrl(string $url): void
    {
        putenv("APP_URL={$url}");
        $_ENV['APP_URL'] = $url;
        $_SERVER['APP_URL'] = $url;

        // Illuminate\Support\Env caches an *immutable* repository snapshot
        // in a static property on first use, for the life of the process —
        // env()/env-backed config reads stay frozen at whatever putenv()/
        // $_ENV said at that first call, ignoring every later write above,
        // unless that cache is explicitly dropped. enablePutenv() (already
        // the default; called again purely for its $repository = null side
        // effect) forces the next env() call to rebuild it from the current
        // superglobals instead of serving the stale snapshot.
        Env::enablePutenv();
    }

    public function test_a_plain_http_request_is_redirected_to_https_when_app_url_is_not_local(): void
    {
        $response = $this->get('http://app.kaselog.example/hub/login');

        $response->assertRedirect('https://app.kaselog.example/hub/login');
        $response->assertStatus(301);
    }

    public function test_the_redirect_covers_filament_panel_routes_too(): void
    {
        // The admin panel, not a plain 'web' group route — proves this
        // isn't scoped to ->web(), which Filament panels don't use.
        $this->get('http://app.kaselog.example/admin/login')
            ->assertRedirect('https://app.kaselog.example/admin/login');
    }

    public function test_an_already_secure_request_is_not_redirected(): void
    {
        // HTTPS=on is a raw server var (what a TLS-terminating webserver
        // sets), not an HTTP header — call() rather than get() to set it.
        $response = $this->call('GET', 'https://app.kaselog.example/hub/login', server: ['HTTPS' => 'on']);

        $response->assertSuccessful();
    }

    public function test_the_session_cookie_gets_the_secure_flag(): void
    {
        $response = $this->call('GET', 'https://app.kaselog.example/hub/login', server: ['HTTPS' => 'on']);

        $sessionCookie = collect($response->headers->getCookies())
            ->firstOrFail(fn ($cookie) => str_ends_with($cookie->getName(), '-session'));

        $this->assertTrue($sessionCookie->isSecure());
    }
}
