<?php

namespace App\Support\Hub;

use Spatie\Permission\PermissionRegistrar;

/**
 * The Hub (Kase's own back-office panel) authorizes via the same
 * spatie/laravel-permission tables every firm's panel uses, but Hub staff
 * don't belong to any firm — there's no Tenant row for "Kase itself". Since
 * teams mode is on globally (config/permission.php), every row in
 * model_has_roles/model_has_permissions still requires a non-null team id
 * (see the package's own migration), so Hub roles are stored under the
 * reserved sentinel team id below instead of a real tenant_id. It is not a
 * real Tenant and must never be inserted into the tenants table.
 *
 * Role/permission names are prefixed `hub_` so they read unambiguously
 * alongside a firm's own roles when eyeballing the roles table (both live in
 * the same table, distinguished only by tenant_id).
 */
final class HubAccess
{
    public const TEAM_ID = 0;

    public const ROLE_SALES = 'hub_sales';

    public const ROLE_DIRECTOR = 'hub_director';

    /**
     * Baseline permission every Hub role holds — the actual gate
     * User::canAccessPanel() checks for the 'hub' panel. Split further as
     * Sales vs. Director-specific permissions are added.
     */
    public const PERMISSION_ACCESS = 'hub_access';

    /**
     * View the firm list, aggregate counts, and a firm's routine (non-
     * compliance) details. Both roles.
     */
    public const PERMISSION_VIEW_FIRMS = 'hub_view_firms';

    /**
     * Create a new firm, and edit its routine details (name, tagline, logo,
     * trading-style parent). Both roles — this is the "set up new firms"
     * half of Sales' job. Does NOT cover the sensitive actions/fields below,
     * which used to share this one permission before the Section 18
     * Sales/Director split.
     */
    public const PERMISSION_MANAGE_FIRMS = 'hub_manage_firms';

    /**
     * Edit a firm's legal-entity/regulatory fields (SRA number, company
     * number, legal entity name) — director-only: compliance info, not
     * routine setup.
     */
    public const PERMISSION_MANAGE_FIRM_COMPLIANCE = 'hub_manage_firm_compliance';

    /**
     * Suspend/reactivate a firm (the is_active toggle) — director-only: an
     * immediate, live access-cutting action, not routine setup.
     */
    public const PERMISSION_SUSPEND_FIRMS = 'hub_suspend_firms';

    /**
     * View the actual list of a firm's users (real names/emails) —
     * director-only. Sales gets the aggregate count on the firm list
     * (PERMISSION_VIEW_FIRMS already covers that), not the named list.
     */
    public const PERMISSION_VIEW_FIRM_USERS = 'hub_view_firm_users';

    /**
     * Gates Section 19's user impersonation feature — director-only, not
     * granted to hub_sales. The first real divergence between the two Hub
     * roles' permission sets since ROLE_GRANTS was seeded identically for
     * both (see HubRoleSeeder), since superseded by the fuller Section 18
     * Sales/Director split above and PERMISSION_VIEW_AUDIT_LOG below.
     */
    public const PERMISSION_IMPERSONATE = 'hub_impersonate';

    /**
     * View the Hub-scoped audit log (Section 18 item 3) — director-only:
     * "security/audit logs" is explicitly one of the categories Sales must
     * not see.
     */
    public const PERMISSION_VIEW_AUDIT_LOG = 'hub_view_audit_log';

    /**
     * Look up which firm (and client/lead name) a phone number belongs to,
     * across every tenant (Section 18 item 4) — director-only: it's real PII
     * correlation across firms, deliberately narrower than full record
     * browsing (that's what consent-gated Impersonation is for), but still
     * kept in the same access tier as the audit log and firm-user list.
     */
    public const PERMISSION_PHONE_LOOKUP = 'hub_phone_lookup';

    /**
     * Manage standard rates and a firm's billing configuration (subscription,
     * seat purchases, add-ons, negotiated overrides) — director-only, per
     * Section 18 item 1's original split: pricing/billing was explicitly one
     * of the categories kept out of Sales' scope.
     */
    public const PERMISSION_MANAGE_BILLING = 'hub_manage_billing';

    /**
     * Author and edit the master precedent template library that firms
     * adopt into their own tenant-scoped copies — content/product work,
     * not billing- or compliance-sensitive, so both roles hold it (same
     * tier as PERMISSION_MANAGE_FIRMS).
     */
    public const PERMISSION_MANAGE_TEMPLATE_LIBRARY = 'hub_manage_template_library';

    /**
     * Runs $callback with spatie's permissions team id temporarily switched
     * to the Hub's reserved sentinel, restoring whatever was active
     * beforehand — mirrors User::hasRoleInAnyTeam()'s save/restore pattern so
     * a Hub permission check never leaks into, or is leaked into by, a
     * firm's tenant-scoped roles.
     */
    public static function withHubTeam(callable $callback): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(self::TEAM_ID);

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
