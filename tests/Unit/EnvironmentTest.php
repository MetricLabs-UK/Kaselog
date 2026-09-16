<?php

namespace Tests\Unit;

use App\Support\Environment;
use Tests\TestCase;

/**
 * The one shared "is this a genuine local dev address" check behind the
 * debug-mode safety net, the session cookie's Secure default, and
 * ForceHttps — all three must agree, so this is worth pinning directly.
 */
class EnvironmentTest extends TestCase
{
    public function test_local_looking_urls_are_local(): void
    {
        $this->assertTrue(Environment::isLocalUrl('http://localhost'));
        $this->assertTrue(Environment::isLocalUrl('http://127.0.0.1'));
        $this->assertTrue(Environment::isLocalUrl('http://[::1]'));
        $this->assertTrue(Environment::isLocalUrl('http://counselstone.test'));
        $this->assertTrue(Environment::isLocalUrl('http://kase.localhost'));
    }

    public function test_real_looking_urls_are_not_local(): void
    {
        $this->assertFalse(Environment::isLocalUrl('https://app.kaselog.example'));
        $this->assertFalse(Environment::isLocalUrl('https://kaselog.co.uk'));
        // Not a *.test suffix match — a real TLD that merely contains "test".
        $this->assertFalse(Environment::isLocalUrl('https://testing-environment.example'));
    }

    public function test_a_blank_url_is_not_local(): void
    {
        $this->assertFalse(Environment::isLocalUrl(''));
    }

    public function test_a_null_url_falls_back_to_the_real_app_url_env_var(): void
    {
        // phpunit.xml doesn't override APP_URL, so this falls through to
        // .env's counselstone.test — a genuine local address.
        $this->assertTrue(Environment::isLocalUrl());
        $this->assertTrue(Environment::isLocalUrl(null));
    }
}
