<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The one shared "is this a genuine local dev address" check — originally
 * inline in AppServiceProvider::preventDebugModeOnNonLocalUrl(), now reused
 * by config/session.php's cookie 'secure' default and
 * App\Http\Middleware\ForceHttps, so all three can never drift apart on what
 * counts as local.
 *
 * Reads APP_URL via env() directly, not config('app.url') — safe to call
 * from a config file being loaded (config/session.php), where reading
 * another config group mid-load isn't guaranteed to be ready yet.
 */
final class Environment
{
    /**
     * @var list<string>
     */
    private const LOCAL_HOST_PATTERNS = ['localhost', '127.0.0.1', '::1', '[::1]', '*.test', '*.localhost'];

    public static function isLocalUrl(?string $url = null): bool
    {
        $url ??= env('APP_URL');

        $host = parse_url((string) $url, PHP_URL_HOST) ?? '';

        return collect(self::LOCAL_HOST_PATTERNS)
            ->contains(fn (string $pattern) => Str::is($pattern, $host));
    }
}
