<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Freegle Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Freegle-specific settings. These can be overridden
    | via environment variables to support deployment on different domains.
    |
    */

    'sites' => [
        'user' => env('FREEGLE_USER_SITE', 'https://www.ilovefreegle.org'),
        'mod' => env('FREEGLE_MOD_SITE', 'https://modtools.org'),
    ],

    'api' => [
        'base_url' => env('FREEGLE_API_BASE_URL', 'https://api.ilovefreegle.org'),
        'v2_url' => env('FREEGLE_API_V2_URL', 'https://api.ilovefreegle.org/apiv2'),
    ],

    'branding' => [
        'name' => env('FREEGLE_SITE_NAME', 'Freegle'),
        'logo_url' => env('FREEGLE_LOGO_URL', 'https://www.ilovefreegle.org/icon.png'),
        'wallpaper_url' => env('FREEGLE_WALLPAPER_URL', 'https://www.ilovefreegle.org/wallpaper.png'),
    ],

    'mail' => [
        'noreply_addr' => env('FREEGLE_NOREPLY_ADDR', 'noreply@ilovefreegle.org'),
        'user_domain' => env('FREEGLE_USER_DOMAIN', 'users.ilovefreegle.org'),
        'group_domain' => env('FREEGLE_GROUP_DOMAIN', 'groups.ilovefreegle.org'),
        // Internal domains that should be excluded when selecting a user's preferred email.
        // These are Freegle-internal addresses that can't receive external mail.
        // Matches iznik-server's Mail::ourDomain() + GROUP_DOMAIN + yahoogroups.
        'internal_domains' => [
            'users.ilovefreegle.org',
            'groups.ilovefreegle.org',
            'direct.ilovefreegle.org',
            'republisher.freegle.in',
        ],
        'excluded_domain_patterns' => [
            '@yahoogroups.',
        ],
        // Email logging - send BCC copies of specific email types for debugging/monitoring.
        // Format: comma-separated list of email types to log (e.g., "Welcome,ChatNotification").
        'log_types' => env('FREEGLE_MAIL_LOG_TYPES', ''),
        'log_address' => env('FREEGLE_MAIL_LOG_ADDRESS', ''),
        // Email types enabled for sending from iznik-batch.
        // Comma-separated list of email type names that this system should send.
        // Email types: Welcome, ChatNotification, etc.
        // If empty, NO emails will be sent (fail-safe default).
        'enabled_types' => env('FREEGLE_MAIL_ENABLED_TYPES', ''),
        // GeekAlerts email for system alerts and failure notifications.
        'geek_alerts_addr' => env('FREEGLE_GEEK_ALERTS_ADDR', 'geek-alerts@ilovefreegle.org'),
        // Geeks address for system emails (FROM address for reports etc).
        'geeks_addr' => env('FREEGLE_GEEKS_ADDR', 'geeks@ilovefreegle.org'),
        // Support address for user-facing support.
        'support_addr' => env('FREEGLE_SUPPORT_ADDR', 'support@ilovefreegle.org'),
        // ChitChat support - receives newsfeed report emails.
        'chitchat_support_addr' => env('FREEGLE_CHITCHAT_SUPPORT_ADDR', 'support@ilovefreegle.org'),
        // Partnerships address for charity partner signups.
        'partnerships_addr' => env('FREEGLE_PARTNERSHIPS_ADDR', 'partnerships@ilovefreegle.org'),
        // Info address for donation notifications and general admin emails.
        'info_addr' => env('FREEGLE_INFO_ADDR', 'info@ilovefreegle.org'),
        // CC address for donation notification emails (legacy logging).
        'donation_cc_addr' => env('FREEGLE_DONATION_CC_ADDR', 'log@ehibbert.org.uk'),
        // Trash Nothing domain for incoming mail detection
        'trashnothing_domain' => env('FREEGLE_TRASHNOTHING_DOMAIN', 'trashnothing.com'),
        // Trash Nothing shared secret for mail authentication (skips spam check)
        'trashnothing_secret' => env('FREEGLE_TRASHNOTHING_SECRET', ''),
    ],

    // Firebase Cloud Messaging for push notifications
    'firebase' => [
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH', '/etc/firebase.json'),
    ],

    'images' => [
        // Image domain for user profile images
        'domain' => env('FREEGLE_IMAGES_DOMAIN', 'https://images.ilovefreegle.org'),

        // Base URLs for source images
        'welcome1' => env('FREEGLE_WELCOME_IMAGE1', 'https://www.ilovefreegle.org/images/welcome1.jpg'),
        'welcome2' => env('FREEGLE_WELCOME_IMAGE2', 'https://www.ilovefreegle.org/images/welcome2.jpg'),
        'welcome3' => env('FREEGLE_WELCOME_IMAGE3', 'https://www.ilovefreegle.org/images/welcome3.jpg'),
        // Email assets (icons and small graphics for email templates)
        'email_assets' => env('FREEGLE_EMAIL_ASSETS_URL', 'https://www.ilovefreegle.org/emailimages'),

        // Rule images for welcome emails (from email_assets folder)
        'rule_free' => env('FREEGLE_RULE_FREE_IMAGE', 'https://www.ilovefreegle.org/emailimages/rule-free.png'),
        'rule_nice' => env('FREEGLE_RULE_NICE_IMAGE', 'https://www.ilovefreegle.org/emailimages/rule-nice.png'),
        'rule_safe' => env('FREEGLE_RULE_SAFE_IMAGE', 'https://www.ilovefreegle.org/emailimages/rule-safe.png'),

        // Placeholder images for posts without photos (digest emails)
        // These default to the generic placeholder until type-specific SVGs are deployed.
        'offer_placeholder' => env('FREEGLE_OFFER_PLACEHOLDER', 'https://www.ilovefreegle.org/placeholder.jpg'),
        'wanted_placeholder' => env('FREEGLE_WANTED_PLACEHOLDER', 'https://www.ilovefreegle.org/placeholder.jpg'),
    ],

    // GeoIP database for IP country lookups
    'geoip' => [
        'mmdb_path' => env('GEOIP_MMDB_PATH', '/usr/share/GeoIP/GeoLite2-Country.mmdb'),
    ],

    // TUS uploader for AI-generated images
    'tus_uploader' => env('TUS_UPLOADER', 'https://uploads.ilovefreegle.org:8080'),

    /*
    |--------------------------------------------------------------------------
    | Image Delivery Service
    |--------------------------------------------------------------------------
    |
    | Configuration for the image delivery/resizing service (weserv/images).
    | All email images should use this service for optimal sizing.
    |
    */

    'delivery' => [
        'base_url' => env('FREEGLE_DELIVERY_URL', 'https://delivery.ilovefreegle.org'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geospatial Settings
    |--------------------------------------------------------------------------
    |
    | SRID 3857 is the Web Mercator projection used for geospatial data.
    |
    */

    'srid' => env('FREEGLE_SRID', 3857),

    /*
    |--------------------------------------------------------------------------
    | Loki Logging
    |--------------------------------------------------------------------------
    |
    | Configuration for Grafana Loki logging. Logs are written to JSON files
    | that Alloy ships to Loki.
    |
    */

    'loki' => [
        'enabled' => env('LOKI_ENABLED', false) || env('LOKI_JSON_FILE', false),
        'log_path' => env('LOKI_JSON_PATH', '/var/log/freegle'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spam Checking
    |--------------------------------------------------------------------------
    |
    | Configuration for spam checking emails during testing.
    | When enabled, emails are checked against SpamAssassin and Rspamd
    | and the scores are added as headers.
    |
    */

    'spam_check' => [
        'enabled' => env('SPAM_CHECK_ENABLED', false),
        'spamassassin_host' => env('SPAMASSASSIN_HOST', 'spamassassin-app'),
        'spamassassin_port' => env('SPAMASSASSIN_PORT', 783),
        'rspamd_host' => env('RSPAMD_HOST', 'rspamd'),
        'rspamd_port' => env('RSPAMD_PORT', 11334),
        'fail_threshold' => env('SPAM_FAIL_THRESHOLD', 5.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | AMP for Email
    |--------------------------------------------------------------------------
    |
    | Configuration for AMP (Accelerated Mobile Pages) email support.
    | AMP emails allow dynamic content and inline actions like replying
    | to messages directly from the email client.
    |
    */

    'amp' => [
        // Enable/disable AMP email generation
        'enabled' => env('AMP_EMAIL_ENABLED', true),

        // Secret key for HMAC token generation
        'secret' => env('AMP_SECRET', env('FREEGLE_AMP_SECRET', '')),

        // API endpoint for AMP requests
        'api_url' => env('AMP_API_URL', 'https://api.ilovefreegle.org/amp'),

        // Token expiry (single token used for both read and write)
        'token_expiry_hours' => env('AMP_TOKEN_EXPIRY', 168), // 7 days

        // Note: AMP CORS validation checks domain suffix, not specific sender.
        // Allowed domains are configured in the Go API: @ilovefreegle.org,
        // @users.ilovefreegle.org, @mail.ilovefreegle.org
        // Per-recipient FROM addresses like notify-xxx@users.ilovefreegle.org work fine.
    ],

    /*
    |--------------------------------------------------------------------------
    | Git Summary (Weekly Code Review)
    |--------------------------------------------------------------------------
    |
    | Configuration for AI-powered weekly git summaries sent to Discourse.
    | Uses Gemini API to summarize code changes across repositories.
    |
    */

    'git_summary' => [
        'gemini_api_key' => env('GOOGLE_GEMINI_API_KEY', ''),
        'github_token' => env('GIT_SUMMARY_GITHUB_TOKEN', ''),
        'from_address' => env('GIT_SUMMARY_FROM_ADDRESS', 'geeks@ilovefreegle.org'),
        'from_name' => env('GIT_SUMMARY_FROM_NAME', 'Freegle Geeks'),
        'repositories' => [
            [
                'name' => 'Freegle Direct & ModTools (Frontend)',
                'url' => 'https://github.com/Freegle/iznik-nuxt3.git',
                'branch' => 'production',
                'category' => 'FD',
            ],
            [
                'name' => 'PHP API (iznik-server)',
                'url' => 'https://github.com/Freegle/iznik-server.git',
                'branch' => 'master',
                'category' => 'API',
            ],
            [
                'name' => 'Go API (iznik-server-go)',
                'url' => 'https://github.com/Freegle/iznik-server-go.git',
                'branch' => 'master',
                'category' => 'API',
            ],
            [
                'name' => 'Batch Jobs (iznik-batch)',
                'url' => 'https://github.com/Freegle/iznik-batch.git',
                'branch' => 'master',
                'category' => 'BE',
            ],
            [
                'name' => 'Docker Infrastructure (FreegleDocker)',
                'url' => 'https://github.com/Freegle/FreegleDocker.git',
                'branch' => 'master',
                'category' => 'BE',
            ],
        ],
        'max_days_back' => 7,
        'discourse_email' => env('FREEGLE_DISCOURSE_TECH_EMAIL', ''),
    ],

    // Note: App release classification (hotfix: detection) is handled directly
    // in CircleCI via the check-hotfix-promote job. See iznik-nuxt3/.circleci/config.yml

    /*
    |--------------------------------------------------------------------------
    | Netlify SSL Certificate Upload
    |--------------------------------------------------------------------------
    |
    | Configuration for uploading renewed Let's Encrypt certificates to Netlify.
    | Used by the ssl:netlify-upload artisan command.
    |
    */

    'netlify' => [
        'token' => env('NETLIFY_TOKEN', ''),
        'site_id' => env('NETLIFY_SITE_ID', '75fa22f1-3d32-4474-a3fc-65afbd7f4f43'),
        'cert_path' => env('LETSENCRYPT_CERT_PATH', '/etc/letsencrypt/live/ilovefreegle.org'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Health Monitoring
    |--------------------------------------------------------------------------
    |
    | Thresholds for the monitor:email-health cron job.
    | The monitor only runs during daytime hours.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Freebie Alerts Integration
    |--------------------------------------------------------------------------
    |
    | API key and endpoint for freebiealerts.app — a third-party aggregator
    | that lists Freegle Offer posts. Posts are added when approved and
    | removed when taken, withdrawn, or deleted.
    |
    */

    'freebie_alerts' => [
        'api_url' => env('FREEBIE_ALERTS_API_URL', 'https://api.freebiealerts.app'),
        'api_key' => env('FREEBIE_ALERTS_KEY', ''),
    ],

    'email_health' => [
        'incoming_window_hours' => env('FREEGLE_EMAIL_HEALTH_INCOMING_WINDOW_HOURS', 2),
        'outgoing_min_per_hour' => env('FREEGLE_EMAIL_HEALTH_OUTGOING_MIN_PER_HOUR', 10),
        'daytime_start' => env('FREEGLE_EMAIL_HEALTH_DAYTIME_START', 7),
        'daytime_end' => env('FREEGLE_EMAIL_HEALTH_DAYTIME_END', 22),
    ],

    'dedup' => [
        // Guard for the dedup:tn artisan command. Defaults to false — the
        // command refuses to run unless this is true, so it's safe to deploy
        // the code without changing existing Trash Nothing behaviour.
        // --dry-run still works regardless (it only reads).
        'tn_enabled' => env('TN_DEDUP_ENABLED', false),
    ],
];
