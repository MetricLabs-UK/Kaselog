<?php

return [

    /*
    |--------------------------------------------------------------------
    | Solicitor Matter Scoping
    |--------------------------------------------------------------------
    |
    | When false (default), solicitors see all matters. When true,
    | solicitors are restricted to matters assigned to them.
    |
    */
    'scope_solicitors' => env('SCOPE_SOLICITORS', false),

    /*
    |--------------------------------------------------------------------
    | Client Portal Invites
    |--------------------------------------------------------------------
    |
    | How long a signed "set your password" invite link stays valid before
    | it must be resent. The link is also single-use regardless of this
    | window — it's invalidated the moment a password is successfully set.
    |
    */
    'portal_invite_expiry_hours' => env('PORTAL_INVITE_EXPIRY_HOURS', 72),

];
