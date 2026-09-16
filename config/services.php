<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'xero' => [
        'webhook_secret' => env('XERO_WEBHOOK_SECRET'),
        'client_id' => env('XERO_CLIENT_ID'),
        'client_secret' => env('XERO_CLIENT_SECRET'),
    ],

    // Firm-facing self-service backup's SharePoint destination
    // (App\Support\Backups\SharePoint\SharePointBackupProvider) — a
    // *separate* Azure AD app registration from Section 12's SHAREPOINT_*
    // vars above: this one is multi-tenant with delegated permissions (each
    // firm authorizes their own Microsoft account), not Kase's own
    // app-only one. See .env.example.
    'microsoft_backup' => [
        'client_id' => env('AZURE_BACKUP_CLIENT_ID'),
        'client_secret' => env('AZURE_BACKUP_CLIENT_SECRET'),
    ],

    // Firm-facing self-service backup's Google Drive destination
    // (App\Support\Backups\GoogleDrive\GoogleDriveBackupProvider) — this
    // app's first Google integration of any kind, so there's no existing
    // Google Cloud project/OAuth client to extend. See .env.example.
    'google_backup' => [
        'client_id' => env('GOOGLE_BACKUP_CLIENT_ID'),
        'client_secret' => env('GOOGLE_BACKUP_CLIENT_SECRET'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from_number' => env('TWILIO_FROM_NUMBER'),
    ],

    'retell' => [
        // Retell signs webhook requests with the account API key (HMAC-SHA256
        // over raw_body.timestamp), not a separate webhook secret — see
        // https://docs.retellai.com/features/secure-webhook. webhook_secret
        // is kept for compatibility but isn't used for signature verification.
        'webhook_secret' => env('RETELL_WEBHOOK_SECRET'),
        'api_key' => env('RETELL_API_KEY'),
    ],

];
