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

    // Local timezone for member-facing scheduling (e.g. the daily digest's
    // 07:00 send). The app itself runs in UTC; scheduled tasks that should
    // track UK wall-clock pin to this zone so Laravel resolves BST/GMT.
    'timezone' => env('FREEGLE_TIMEZONE', 'Europe/London'),

    'api' => [
        'base_url' => env('FREEGLE_API_BASE_URL', 'https://api.ilovefreegle.org'),
        'v2_url' => env('FREEGLE_API_V2_URL', 'https://api.ilovefreegle.org/apiv2'),
    ],

    'avatar_server_url' => env('FREEGLE_AVATAR_SERVER_URL', 'https://api.ilovefreegle.org/avatar'),

    'donations' => [
        // One-off donations below this amount (£) don't warrant a manual
        // thank-you (V1 Donations::MANUAL_THANKS). Recurring donations are
        // thanked once, on the first payment, regardless of amount.
        'manual_thanks' => (float) env('FREEGLE_MANUAL_THANKS', 20),

        // Payer emails that must never trigger a thank-you (PayPal Giving Fund,
        // Tipalti). Comma-separated; mirrors the Go DONATIONS_EXCLUDE so both
        // sides share one source of truth.
        'excluded_payers' => env('DONATIONS_EXCLUDE', 'ppgfukpay@paypalgivingfund.org,paypal.msb@tipalti.com'),
    ],

    'branding' => [
        'name' => env('FREEGLE_SITE_NAME', 'Freegle'),
        'logo_url' => env('FREEGLE_LOGO_URL', 'https://www.ilovefreegle.org/icon.png'),
        'wallpaper_url' => env('FREEGLE_WALLPAPER_URL', 'https://www.ilovefreegle.org/wallpaper.png'),
        'modtools_logo_url' => env('MODTOOLS_LOGO_URL', 'https://modtools.org/icon_modtools.png'),
        'registered_address' => env('FREEGLE_REGISTERED_ADDRESS', '64a North Road, Ormesby, Great Yarmouth, Norfolk NR29 3LE'),
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
        // Fundraising address — receives the daily donation summary email.
        'fundraising_addr' => env('FREEGLE_FUNDRAISING_ADDR', 'info@ilovefreegle.org'),
        // Thanks address — receives the daily thank-prep digest (rich per-donor
        // cards aimed at composing thank-you replies). Defaults to the
        // fundraising address; override to route the digest elsewhere
        // (e.g. directly to Jacky).
        'thanks_addr' => env('FREEGLE_THANKS_ADDR', env('FREEGLE_FUNDRAISING_ADDR', 'info@ilovefreegle.org')),
        // Mentors address — volunteer support team who handle escalations.
        'mentors_addr' => env('FREEGLE_MENTORS_ADDR', 'mentors@ilovefreegle.org'),
        // Central mods / Volunteer Support address — receives the Discourse
        // checkuser / not-signed-up reports (V1 CENTRALMODS_ADDR).
        'centralmods_addr' => env('FREEGLE_CENTRALMODS_ADDR', 'volunteersupport@ilovefreegle.org'),
        // CC address for donation notification emails (legacy logging).
        'donation_cc_addr' => env('FREEGLE_DONATION_CC_ADDR', 'log@ehibbert.org.uk'),
        // TrashNothing contact that receives the monthly LoveJunk invoice request
        // (V1 TN_ADDR). Held in env as it's a partner contact; no committed default.
        'tn_invoice_addr' => env('FREEGLE_TN_INVOICE_ADDR', ''),
        // Treasurer address quoted in the invoice body — where TN should send the
        // PDF invoice (V1 TREASURER_ADDR).
        'treasurer_addr' => env('FREEGLE_TREASURER_ADDR', 'treasurer@ilovefreegle.org'),
        // Modbot email — the automated moderator account; excluded from mod-welfare checks.
        'moderator_email' => env('FREEGLE_MODERATOR_EMAIL', 'modbot@users.ilovefreegle.org'),
        // Trash Nothing domain for incoming mail detection
        'trashnothing_domain' => env('FREEGLE_TRASHNOTHING_DOMAIN', 'trashnothing.com'),
        // Trash Nothing shared secret for mail authentication (skips spam check)
        'trashnothing_secret' => env('FREEGLE_TRASHNOTHING_SECRET', ''),
    ],

    'trashnothing' => [
        'api_key' => env('FREEGLE_TN_API_KEY', ''),
        'api_base_url' => env('FREEGLE_TN_API_BASE_URL', 'https://trashnothing.com/fd/api'),
        'sync_date_file' => env('FREEGLE_TN_SYNC_DATE_FILE', '/etc/tn_sync_last_date.txt'),
    ],

    // Discourse forum REST API (V1 discourse_not_signed_up.php).
    // When api_key is empty the Discourse cron commands skip with a warning.
    'discourse' => [
        'url' => env('DISCOURSE_URL', 'https://discourse.ilovefreegle.org'),
        'api_key' => env('DISCOURSE_APIKEY', ''),
        'api_username' => env('DISCOURSE_API_USERNAME', 'system'),
        // Per-user pacing between Discourse API calls (V1 used usleep(250000)).
        'throttle_us' => (int) env('DISCOURSE_THROTTLE_US', 250000),
        // Rate-limit retry policy (V1 Utils::curlWithRetry: 60 retries, 1s delay).
        'max_retries' => (int) env('DISCOURSE_MAX_RETRIES', 60),
        'retry_delay_s' => (int) env('DISCOURSE_RETRY_DELAY_S', 1),
    ],

    // PayPal NVP/SOAP API (V1 paypal_download.php fallback transaction downloader).
    // When username is empty the donations:paypal-download command skips with a warning.
    'paypal' => [
        'username' => env('PAYPAL_USERNAME', ''),
        'password' => env('PAYPAL_PASSWORD', ''),
        'signature' => env('PAYPAL_SIGNATURE', ''),
        // Live NVP endpoint; sandbox is https://api-3t.sandbox.paypal.com/nvp
        'nvp_endpoint' => env('PAYPAL_NVP_ENDPOINT', 'https://api-3t.paypal.com/nvp'),
        // How many days back to scan for missed transactions.
        'download_days' => (int) env('PAYPAL_DOWNLOAD_DAYS', 30),
    ],

    // Doogal UK postcode dataset (V1 cli/doogal.php + cron/doogal wrapper).
    'doogal' => [
        'zip_url' => env('DOOGAL_ZIP_URL', 'https://www.doogal.co.uk/files/postcodes.zip'),
    ],

    'digest' => [
        // Safety gate for the unified-digest IMMEDIATE mode.
        //   ''  or '*'      → allow all users (the normal production state)
        //   'a@x.com,b@y'   → restrict immediate emails to those addresses
        //
        // Default is '*' (everyone) — V1's bulk3 `digest.php -i -1` cron was
        // disabled on 2026-05-27 so this is the only source of immediate
        // notifications. Set FREEGLE_DIGEST_IMMEDIATE_ALLOWLIST in env to
        // restrict (e.g. a comma-separated list for a re-pilot).
        'immediate_allowlist' => env('FREEGLE_DIGEST_IMMEDIATE_ALLOWLIST', '*'),

        // Safety gate for the unified-digest DAILY mode ("What's New").
        //   ''              → send to NOBODY (the default). V1's bulk3
        //                     `digest.php -i 24` cron still owns daily, so an
        //                     unconfigured deploy can't double-mail everyone.
        //   'a@x.com,b@y'   → send the new-format daily digest to those pilot
        //                     addresses IN ADDITION to V1's daily mail, so we
        //                     can compare formats (and exercise tracking) on a
        //                     single recipient before any cutover.
        //   '*'             → everyone (the eventual full cutover switch).
        // The scheduled `mail:digest:unified --mode=daily` is inert until this
        // is set; an explicit `--user=` bypasses the gate for manual sampling.
        'daily_allowlist' => env('FREEGLE_DIGEST_DAILY_ALLOWLIST', ''),
    ],

    // Firebase Cloud Messaging for push notifications
    'firebase' => [
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH', '/etc/firebase.json'),
    ],

    // Safety gate for the daily new-posts push notification (push:daily-posts).
    //   ''   → send to NOBODY (the default). Safe to deploy — no pushes are
    //          sent until an operator explicitly enables via env.
    //   '*'  → all eligible users (FD-app token + opted-in).
    //   'a@x.com,b@y' → comma-separated email list for pilot testing.
    // An explicit --user= on the command bypasses this gate for manual sampling.
    'posts_push_allowlist' => env('FREEGLE_POSTS_PUSH_ALLOWLIST', ''),

    'images' => [
        // Image domain for user profile images
        'domain' => env('FREEGLE_IMAGES_DOMAIN', 'https://images.ilovefreegle.org'),
        // Domain for legacy archived images (Azure blob storage)
        'archived_domain' => env('FREEGLE_IMAGES_ARCHIVED_DOMAIN', 'https://freegle.blob.core.windows.net'),

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

        // Placeholder images for posts without photos (digest emails).
        // Type-specific: green OFFER / blue WANTED, matching the in-app
        // MessagePhotoPlaceholder gradients. Served from the user site
        // (iznik-nuxt3/public/placeholder-offer.png / placeholder-wanted.png).
        'offer_placeholder' => env('FREEGLE_OFFER_PLACEHOLDER', 'https://www.ilovefreegle.org/placeholder-offer.png'),
        'wanted_placeholder' => env('FREEGLE_WANTED_PLACEHOLDER', 'https://www.ilovefreegle.org/placeholder-wanted.png'),
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

    // The spatial-knn "finder" service (iznik-spatial-go). SPATIAL_KNN_URL is the
    // canonical name (also used by the Go client). NB: SPATIAL_SERVER_URL is taken
    // by the routing/isochrone server elsewhere, so it must NOT be relied on here —
    // it's only a last-resort legacy fallback. The admin (rebuild/remove/upsert)
    // API is the same host on the next port; derive it from SPATIAL_KNN_URL so any
    // container that sets SPATIAL_KNN_URL gets a working admin URL for free.
    'spatial_server_url' => env('SPATIAL_KNN_URL', env('SPATIAL_SERVER_URL', 'http://localhost:8194')),
    'spatial_admin_url' => env('SPATIAL_KNN_ADMIN_URL', env('SPATIAL_ADMIN_URL', str_replace(
        ':8194',
        ':8195',
        env('SPATIAL_KNN_URL', 'http://localhost:8194')
    ))),
    'spatial_data_dir' => env('SPATIAL_DATA_DIR', '/data'),

    // The routing/isochrone server (iznik-routing-go) — DISTINCT from the KNN
    // finder above. Hosts GET /v1/ripple-schedule and /v1/fairness, called over
    // HTTP by the ripple:expand reach engine from inside the existing batch
    // container (no new container).
    //
    // The routing server listens on TWO ports: the external port (SPATIAL_PORT,
    // 8196) requires a moderator JWT, while the internal port (SPATIAL_INTERNAL_PORT,
    // 8194) is unauthenticated and intended for trusted backend services — which is
    // exactly this engine (it has no user JWT). So we target the internal port.
    // Set ROUTING_SERVER_URL in any environment whose routing server is not reachable
    // at the default below (it must point at the routing server's INTERNAL/no-auth
    // port, NOT the KNN finder and NOT the JWT-guarded external port).
    'routing_server_url' => env('ROUTING_SERVER_URL', 'http://spatial:8194'),

    // Rippling-out reach engine parameters (ripple:expand / ReachService).
    'ripple' => [
        // Master activation switch for the whole rippling-out feature. Ships DARK (false) so all the
        // server + app code can deploy (and clear the app stores) ahead of go-live; flip
        // RIPPLE_ENABLED=true to turn rippling on with no code change. When false the ripple:expand
        // cron is not scheduled, so no reach is ever computed and every reach consumer stays inert.
        'enabled' => (bool) env('RIPPLE_ENABLED', false),
        // Go-live arrival cutoff (server local time). Only posts that arrived on or
        // after this instant ever START rippling; older pending posts are left alone.
        // This is the flood guard: when RIPPLE_ENABLED first flips on, every historical
        // pending post would otherwise become eligible at once and fan out a wall of
        // mail. With the cutoff, only recent posts ripple, so go-live is a trickle.
        // Empty string disables the cutoff (ripple everything, e.g. in tests).
        'enabled_at' => env('RIPPLE_ENABLED_AT', '2026-06-21'),
        // Density curve passed to /v1/ripple-schedule (see iznik-routing-go ripple.go).
        'curve' => env('RIPPLE_CURVE', 'step-70'),
        // Travel mode for the reach isochrone.
        'mode' => env('RIPPLE_MODE', 'drive'),
        // Maximum drive-time (minutes) the reach may grow to.
        'max_minutes' => (float) env('RIPPLE_MAX_MINUTES', 30),
        // Wall-clock hazard schedule (hours since arrival) at which the reach
        // expands one tick. One schedule tick is requested per entry, so the
        // number of ticks equals the length of this array.
        'hazard_hours' => [1, 3, 6, 12, 24, 48, 72, 120, 168],
        // Only expand during active hours (server local time): inclusive start,
        // exclusive end. Outside this window, due expansions wait.
        'active_start_hour' => (int) env('RIPPLE_ACTIVE_START_HOUR', 6),
        'active_end_hour' => (int) env('RIPPLE_ACTIVE_END_HOUR', 23),
    ],

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
        // Hard outgoing-stall check window. Runs 24/7: zero outgoing emails
        // across this window is a near-certain smarthost/spooler stall at any
        // hour, so it catches night-time outages the daytime-only volume floor
        // would miss.
        'outgoing_stall_window_hours' => env('FREEGLE_EMAIL_HEALTH_OUTGOING_STALL_WINDOW_HOURS', 1),
        // Alert (24/7) when at least this many SpoolMail durable-retry jobs have
        // exhausted their 24h window and parked in failed_jobs. A non-zero
        // count almost always means a render bug is dropping a whole class of
        // email; deploy the fix then run `php artisan mail:retry-failed`.
        'failed_mail_retry_threshold' => env('FREEGLE_EMAIL_HEALTH_FAILED_MAIL_RETRY_THRESHOLD', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled-task Outcome Monitoring
    |--------------------------------------------------------------------------
    |
    | Thresholds for the monitor:scheduled-outcomes cron job, which asserts that
    | scheduled tasks actually did their work (not just that the scheduler is
    | alive). Breaches escalate to Sentry. See docs/scheduled-outcome-monitoring.md.
    |
    */

    'monitoring' => [
        // Master kill-switch. When false, monitor:scheduled-outcomes no-ops.
        'enabled' => env('FREEGLE_MONITORING_ENABLED', true),

        // stats:generate-daily — minimum per-group stats rows expected for
        // yesterday once the day's 02:30 run has had time to complete.
        'stats_daily_min_expected' => (int) env('FREEGLE_MONITORING_STATS_DAILY_MIN', 1),

        // mail:digest:unified --mode=daily — minimum daily-digest sends expected
        // once the daily pilot is enabled (FREEGLE_DIGEST_DAILY_ALLOWLIST set).
        'digest_daily_min_expected' => (int) env('FREEGLE_MONITORING_DIGEST_DAILY_MIN', 1),

        // queue:background-tasks — a pending task (unprocessed, unfailed, under
        // the retry cap) older than this many minutes signals a stuck worker.
        'background_tasks_max_age_minutes' => (int) env('FREEGLE_MONITORING_BG_TASKS_MAX_AGE_MIN', 10),
        // Number of such stale-pending tasks tolerated before breaching.
        'background_tasks_backlog_threshold' => (int) env('FREEGLE_MONITORING_BG_TASKS_BACKLOG', 0),

        // spam:refresh-mobile-cidrs — alert if the monthly UK-mobile CIDR
        // refresh hasn't written a row within this many days.
        'mobile_cidrs_max_age_days' => (int) env('FREEGLE_MONITORING_MOBILE_CIDRS_MAX_AGE_DAYS', 40),

        // Shared backlog window for the per-minute processing queues
        // (messages:contentcheck, chats:process-incoming, memberships:process):
        // a row left unprocessed longer than this signals a stuck worker.
        'processing_backlog_max_age_minutes' => (int) env('FREEGLE_MONITORING_PROCESSING_BACKLOG_MAX_AGE_MIN', 15),

        // users:process-exports — exports are heavier, so a larger window.
        'exports_backlog_max_age_minutes' => (int) env('FREEGLE_MONITORING_EXPORTS_BACKLOG_MAX_AGE_MIN', 30),

        // integrations:sync-whatjobs — alert if jobs.seenat hasn't advanced
        // within this many hours (tolerates the overnight gap + slow cold runs).
        'whatjobs_max_age_hours' => (int) env('FREEGLE_MONITORING_WHATJOBS_MAX_AGE_HOURS', 24),

        // data:git-summary (weekly) — alert if its config timestamp is older
        // than this many days.
        'git_summary_max_age_days' => (int) env('FREEGLE_MONITORING_GIT_SUMMARY_MAX_AGE_DAYS', 10),

        // data:update-cpi (monthly) — alert if its config timestamp is older
        // than this many days.
        'cpi_max_age_days' => (int) env('FREEGLE_MONITORING_CPI_MAX_AGE_DAYS', 40),
    ],

    'dedup' => [
        // Guard for the dedup:tn artisan command. Defaults to false — the
        // command refuses to run unless this is true, so it's safe to deploy
        // the code without changing existing Trash Nothing behaviour.
        // --dry-run still works regardless (it only reads).
        'tn_enabled' => env('TN_DEDUP_ENABLED', false),
    ],

    'lovejunk' => [
        'api' => env('LOVE_JUNK_API', 'https://elmer.api-lovejunk.com/elmer/v1'),
        'secret' => env('LOVE_JUNK_SECRET', ''),
    ],

    // Default matches V1's hardcoded GEOCODER constant (iznik.conf.php). The
    // Laravel migration parameterised this via env, but .env.background was
    // never updated — so for months WhatJobsService::geocodeAddress() silently
    // returned null on the first line, leaving the live jobs table pinned at
    // ~400 rows (only DB cache + UK postcode fallback could ever match).
    'geocoder' => env('FREEGLE_GEOCODER_URL', 'https://geocode.ilovefreegle.org'),

    'whatjobs' => [
        'feed1' => env('WHATJOBS_FEED1', ''),
        'feed2' => env('WHATJOBS_FEED2', ''),
    ],

    'reach_volunteering' => [
        'feed_url' => env('REACH_VOLUNTEERING_FEED_URL', ''),
        'username' => env('REACH_VOLUNTEERING_USER', ''),
        'password' => env('REACH_VOLUNTEERING_PASSWORD', ''),
        // Reach's Drupal feed periodically returns a PHP fatal-error page
        // (max_execution_time exceeded) with HTTP 200 instead of JSON. Retry
        // a few times before giving up, since the timeout is load-related.
        'fetch_attempts'      => (int) env('REACH_VOLUNTEERING_FETCH_ATTEMPTS', 3),
        'retry_delay_seconds' => (int) env('REACH_VOLUNTEERING_RETRY_DELAY_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | EEE (Electrical and Electronic Equipment) Identification
    |--------------------------------------------------------------------------
    |
    | AI-powered classification of EEE items from Freegle post photos.
    | Results stored in SQLite to avoid MySQL schema churn.
    |
    | model options: 'claude' (reference), 'gemini', 'openai', 'together', 'ollama'
    |
    */
    'eee' => [
        'sqlite_path'     => env('EEE_SQLITE_PATH', storage_path('eee/classifications.sqlite')),
        'model'           => env('EEE_MODEL', 'gemini'),

        // Anthropic (Claude) — reference labeller.
        'anthropic_api_key' => env('ANTHROPIC_API_KEY', ''),
        'claude_model'      => env('EEE_CLAUDE_MODEL', 'claude-sonnet-4-6'),

        // Google Gemini.
        'gemini_api_key'  => env('GOOGLE_GEMINI_API_KEY', ''),
        'gemini_model'    => env('EEE_GEMINI_MODEL', 'gemini-2.0-flash'),

        // OpenAI.
        'openai_api_key'  => env('OPENAI_API_KEY', ''),
        'openai_model'    => env('EEE_OPENAI_MODEL', 'gpt-4o'),

        // Together.ai — for multi-model comparison (Llama, Qwen etc.).
        'together_api_key' => env('TOGETHER_API_KEY', ''),
        'together_model'   => env('EEE_TOGETHER_MODEL', 'meta-llama/Llama-3.2-90B-Vision-Instruct-Turbo'),

        // Ollama — runs on Windows host; accessible from WSL/Docker via host.docker.internal.
        'ollama_base_url' => env('EEE_OLLAMA_BASE_URL', 'http://host.docker.internal:11434'),
        'ollama_model'    => env('EEE_OLLAMA_MODEL', 'llama3.2-vision'),

        // Claude subscription bridge — file-based IPC so Claude Code processes
        // images using the subscription rather than the pay-per-token API.
        // PHP writes job files here; Claude Code reads them and writes results.
        // Path must be visible to both the PHP container and the host Claude session.
        'bridge_path'            => env('EEE_BRIDGE_PATH', storage_path('eee/bridge')),
        'bridge_timeout_seconds' => env('EEE_BRIDGE_TIMEOUT', 300),

        // Whether to include follow-on chat as classification context.
        // Off by default — chat messages are private between users.
        'use_chat_data'   => env('EEE_USE_CHAT_DATA', false),

        // Stats page: toggle each attribute on only after model comparison
        // confirms high inter-model agreement for that attribute.
        'publish_weight'    => env('EEE_PUBLISH_WEIGHT', false),
        'publish_brands'    => env('EEE_PUBLISH_BRANDS', false),
        'publish_category'  => env('EEE_PUBLISH_CATEGORY', true),
        'publish_condition' => env('EEE_PUBLISH_CONDITION', true),
    ],
];
