<?php

namespace App\Support\Tenancy;

/**
 * The single source of truth for tenant slugs that can never be allowed to
 * exist (audit finding F12). Portal URLs live at the domain root as
 * /{tenant-slug}/..., so a tenant slug equal to a literal top-level route
 * prefix would have every one of its portal URLs shadowed by that
 * exact-match route (e.g. a tenant "storage" could never serve
 * /storage/{reference} — Laravel's own storage route wins).
 *
 * Used from both directions so the two can't drift apart:
 * - Tenant::booted() rejects reserved slugs at save time (there is no
 *   tenant CRUD UI yet — the model hook covers seeders, tinker, and any
 *   future UI alike; a form can reuse isReserved() in a validation rule).
 * - AppServiceProvider::excludeAdminPathFromTenantSlugMatching() compiles
 *   routeExclusionPattern() into every portal {tenant} route parameter, so
 *   even a reserved slug that somehow existed would never be matched as a
 *   tenant by the router.
 *
 * If a new top-level route prefix is ever added to the app, add it here.
 */
final class ReservedSlugs
{
    /**
     * Literal first-path-segments owned by the app or its packages —
     * mirror `php artisan route:list`'s top-level prefixes.
     *
     * @var list<string>
     */
    public const LITERAL = [
        'admin',
        'filament',
        'hub',
        'login',
        'logout',
        'retell',
        'storage',
        'up',
        'webhooks',
    ];

    /**
     * Reserved slug prefixes (Livewire's asset routes embed a version hash:
     * livewire-<hash>/...).
     *
     * @var list<string>
     */
    public const PREFIXES = [
        'livewire-',
    ];

    public static function isReserved(string $slug): bool
    {
        $slug = strtolower(trim($slug));

        if (in_array($slug, self::LITERAL, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($slug, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Regex for a route's {tenant} parameter that refuses every reserved
     * slug while still matching a single path segment. The (?:/|$)
     * lookahead boundary keeps e.g. "administrator" valid while "admin"
     * is refused.
     */
    public static function routeExclusionPattern(): string
    {
        $literals = implode('|', array_map(fn (string $slug): string => preg_quote($slug, '#'), self::LITERAL));

        $prefixGuards = implode('', array_map(
            fn (string $prefix): string => '(?!'.preg_quote($prefix, '#').')',
            self::PREFIXES,
        ));

        return '(?!(?:'.$literals.')(?:/|$))'.$prefixGuards.'[^/]+';
    }
}
