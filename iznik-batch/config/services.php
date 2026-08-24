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

    'mjml' => [
        // Compile engine:
        //   'mrml' (default) — in-process via the bundled mrml PHP extension
        //                      (Rust). No HTTP, no sidecar. ~170x faster.
        //   'node'           — legacy adrianrudnik/mjml-server sidecar over
        //                      HTTP. Kept as a fallback and for differential
        //                      rendering tests.
        'engine' => env('MJML_ENGINE', 'mrml'),

        // MJML server URL (adrianrudnik/mjml-server on port 80) — node engine only.
        'url' => env('MJML_URL', 'http://mjml/'),

        // HTTP request timeout in seconds — node engine only.
        'http_timeout' => env('MJML_HTTP_TIMEOUT', 30),
    ],

    // Community-reuse outreach mailbox (Gmail API, domain-wide delegation).
    // Used by App\Services\Gmail\GmailService for the outreach sender and the
    // concierge mailbox transport. Sends/reads as a real Workspace mailbox.
    'gmail_outreach' => [
        // The real mailbox the service account impersonates and reads/sends as.
        'mailbox' => env('GMAIL_OUTREACH_MAILBOX', 'natalie-wagg@ilovefreegle.org'),

        // From display name on outreach mail.
        'from_name' => env('GMAIL_OUTREACH_FROM_NAME', 'Natalie @ Freegle'),

        // Path (inside the container) to the service-account JSON key. Mounted
        // read-only via a host-path volume; never committed. Leave unset to
        // force dry-run.
        'credentials_path' => env('GMAIL_OUTREACH_CREDENTIALS_PATH'),

        // Sub-address that receives unsubscribe requests (mailbox-native opt-out).
        'unsub_address' => env('GMAIL_OUTREACH_UNSUB_ADDRESS', 'natalie-wagg+unsub@ilovefreegle.org'),

        // DRY_RUN writes the rendered .eml to storage/app/outreach/dryrun/ instead
        // of sending. Default TRUE: live sending must be explicitly enabled.
        'dry_run' => env('GMAIL_OUTREACH_DRY_RUN', true),
    ],

];
