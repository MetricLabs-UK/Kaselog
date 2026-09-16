<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Control cases for HttpsEnforcementTest, kept separate because they must
 * run under the *default* test APP_URL (.env's counselstone.test, a local
 * address) rather than that class's simulated-production override — see its
 * docblock for why the override has to happen before app boot.
 */
class HttpsEnforcementLocalTest extends TestCase
{
    public function test_a_local_app_url_is_never_redirected(): void
    {
        $this->get('http://counselstone.test/hub/login')->assertSuccessful();
    }

    public function test_the_session_cookie_has_no_secure_flag(): void
    {
        $response = $this->get('http://counselstone.test/hub/login');

        $sessionCookie = collect($response->headers->getCookies())
            ->firstOrFail(fn ($cookie) => str_ends_with($cookie->getName(), '-session'));

        $this->assertFalse($sessionCookie->isSecure());
    }
}
