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

    'digest' => [
        // Score multiplier for a daily-digest post the recipient has already had a
        // chance to see (an in-app view, or an opened/clicked digest that contained
        // it). Below 1 sinks seen posts beneath fresh ones without hard-dropping them.
        'seen_penalty' => (float) env('FREEGLE_DIGEST_SEEN_PENALTY', 0.15),
    ],

    'api' => [
        'base_url' => env('FREEGLE_API_BASE_URL', 'https://api.ilovefreegle.org'),
        'v2_url' => env('FREEGLE_API_V2_URL', 'https://api.ilovefreegle.org/apiv2'),
    ],

    // Matched-posts email (matches:notify): emails a member the opposite-type
    // posts near them that match their own open Offer/Wanted, resurrected with
    // vector matching. Killswitch FREEGLE_MATCHED_ENABLED=false stops all sends
    // without a deploy (apiv2 has its own FEATURE_MATCHED_POSTS killswitch for
    // the vector endpoint).
    'matched' => [
        'enabled' => env('FREEGLE_MATCHED_ENABLED', true),
        // How far back to treat a post as "fresh" each run. Wider than the 10-min
        // schedule so a post embedded a few minutes after arrival is still caught;
        // the per-(msgid,userid) ledger stops the overlap re-mailing anything.
        'fresh_window_minutes' => env('FREEGLE_MATCHED_FRESH_WINDOW_MINUTES', 20),
        // Matches requested from apiv2 per fresh post.
        'match_limit_per_post' => env('FREEGLE_MATCHED_LIMIT_PER_POST', 10),
        // Max matched posts shown in one email (rest wait for a later run / the site).
        'max_items_per_email' => env('FREEGLE_MATCHED_MAX_ITEMS', 10),
        // Minimum apiv2 vector-similarity score for a match to be shown. apiv2
        // returns the nearest neighbours regardless of similarity, and the scores
        // are only weakly discriminative (a relevant "bicycle inner tube" ~0.668
        // barely beats an irrelevant "pot pourri" ~0.656 for "bicycle pump"), so
        // this is a coarse tail-trim, not a quality guarantee — tune per corpus,
        // and treat the underlying embedding quality as the real lever.
        'match_min_score' => (float) env('FREEGLE_MATCHED_MIN_SCORE', 0.66),
        // Don't email the same member more often than this (hours). Uses
        // users.lastrelevantcheck, on top of the never-mail-the-same-post ledger.
        'cooldown_hours' => env('FREEGLE_MATCHED_COOLDOWN_HOURS', 4),
        // Don't email members who haven't visited in this many days.
        'min_lastaccess_days' => env('FREEGLE_MATCHED_MIN_LASTACCESS_DAYS', 90),
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
        // Matches the legacy V1 PHP implementation's Mail::ourDomain() + GROUP_DOMAIN + yahoogroups.
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
        // Spam team - receives chat reports where the two users share no group.
        'spam_addr' => env('FREEGLE_SPAM_ADDR', env('FREEGLE_SUPPORT_ADDR', 'support@ilovefreegle.org')),
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

    'mod_welfare' => [
        // Paused 2026-08-03: mentors asked us to stop the weekly "X does not
        // have an active owner" alerts for now. Set to true to resume.
        'notify_no_owner' => env('FREEGLE_MOD_WELFARE_NOTIFY_NO_OWNER', false),
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

    // First-week ONBOARDING tip sequence (mail:reengage). One short tip a day
    // for a new member's first five days, after the welcome mail. Kept under the
    // "reengage" key/table/dashboard name; the trigger and content are onboarding.
    'reengage' => [
        // Dark-ship gate (mirrors FREEGLE_DIGEST_DAILY_ALLOWLIST):
        //   ''            → send to nobody (default — feature is dark)
        //   '*'           → send to everyone eligible
        //   'a@x,b@y'     → only these recipient addresses (rollout pilot)
        'allowlist' => env('FREEGLE_REENGAGE_ALLOWLIST', ''),
        // Day the FIRST tip lands (account age in days). 1 leaves day 0 for the
        // welcome mail, so tip 1 arrives the day after joining.
        'start_day' => (int) env('FREEGLE_ONBOARD_START_DAY', 1),
        // Gap between tips — one a day. Tip N lands on day start_day+(N-1)*gap,
        // i.e. days 1..5 with the defaults.
        'stage_gap_days' => (int) env('FREEGLE_ONBOARD_STAGE_GAP_DAYS', 1),
        // Never START the sequence for an account already older than this. Stops
        // a first enable from back-blasting everyone who joined recently; a member
        // mid-sequence keeps getting the rest regardless.
        'max_start_days' => (int) env('FREEGLE_ONBOARD_MAX_START_DAYS', 7),

        // A/B experiment layer. Stays inert by default: rollout_pct=0 means
        // nobody is in the experiment, everyone eligible falls through to the
        // single current 'a' template with NO control holdout — i.e. exactly the
        // pre-experiment behaviour. Ramp with FREEGLE_REENGAGE_EXPERIMENT_ROLLOUT_PCT
        // (0 → 10 → 50 → 100) *on top of* the allowlist gate above, so two
        // independent knobs must both be opened.
        'experiment' => [
            // Salts the deterministic per-user bucket; bump to reshuffle arms
            // for a brand-new experiment (do NOT bump mid-experiment).
            'name' => env('FREEGLE_REENGAGE_EXPERIMENT', 'reengage-v1'),
            // Fraction of eligible users pulled into the experiment (0-100).
            // Those outside it get the default 'a' arm with no holdout.
            'rollout_pct' => (int) env('FREEGLE_REENGAGE_EXPERIMENT_ROLLOUT_PCT', 0),
            // Arm split over the 0-99 bucket space (inclusive, must tile 0-99).
            // 'control' is a REAL holdout: recorded but never mailed, so lift is
            // measurable. 'a' = current copy, 'b' = alternate copy variant.
            'arms' => [
                'control' => ['from' => 0, 'to' => 19],   // 20% holdout
                'a' => ['from' => 20, 'to' => 59],        // 40% current copy
                'b' => ['from' => 60, 'to' => 99],        // 40% alternate copy
            ],
            // Window (days after a send) in which a login/reply/post counts as
            // re-engagement caused by the mail, written by mail:reengage-outcomes.
            'outcome_window_days' => (int) env('FREEGLE_REENGAGE_OUTCOME_WINDOW_DAYS', 14),
        ],
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

    // GeoIP database for IP country lookups. Defaults to the copy bundled in
    // the repo (resources/geoip) so the lookup works out of the box wherever
    // the app is deployed - no separately-provisioned mmdb file required.
    // GEOIP_MMDB_PATH can point at a system-managed/fresher database.
    'geoip' => [
        'mmdb_path' => env('GEOIP_MMDB_PATH', base_path('resources/geoip/GeoLite2-Country.mmdb')),
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
    | Community News
    |--------------------------------------------------------------------------
    |
    | The area-based local-news digest: a weekly Freegle-branded email plus a
    | ChitChat (newsfeed) engagement trial. See docs/COMMUNITY-NEWS.md.
    |
    */
    'communitynews' => [
        // Global kill switch for the SCHEDULED runs (manual artisan invocation
        // always works). Off by default so nothing fires until ops opts in.
        'enabled' => (bool) env('COMMUNITY_NEWS_ENABLED', false),

        // Anthropic key (shared with the eee reference labeller) + model for the
        // research-and-write call. Defaults to Opus; override for cost/latency.
        'anthropic_api_key' => env('ANTHROPIC_API_KEY', ''),
        'model'             => env('COMMUNITY_NEWS_MODEL', 'claude-opus-4-8'),

        // Run the research on a Claude SUBSCRIPTION instead of a metered API key:
        // set CLAUDE_CODE_OAUTH_TOKEN (from `claude setup-token`). When present it
        // takes precedence over anthropic_api_key, and research shells out to the
        // `claude` CLI (WebSearch tool) rather than the raw Messages API — a raw
        // OAuth Bearer call to /v1/messages is not supported by Anthropic. Needs
        // the `claude` CLI in the container (the batch image installs it).
        'oauth_token' => env('COMMUNITY_NEWS_OAUTH_TOKEN', env('CLAUDE_CODE_OAUTH_TOKEN', '')),
        // Override the CLI binary / its config dir (blank => a clean per-run temp
        // dir, so this repo's Claude hooks/skills/settings don't load into the job).
        'claude_bin'        => env('COMMUNITY_NEWS_CLAUDE_BIN', 'claude'),
        'claude_config_dir' => env('COMMUNITY_NEWS_CLAUDE_CONFIG_DIR', ''),

        // Post to ChitChat / send the digest AS this account ("Freegle").
        'system_user_email' => env('COMMUNITY_NEWS_SYSTEM_USER_EMAIL', env('FREEGLE_NOREPLY_ADDR', 'noreply@ilovefreegle.org')),

        // Town assignment radius: an enabled group joins its nearest `towns`-table
        // town within this many miles (the town names the area — the searchable
        // unit); beyond it the group stands alone as its own area.
        'area_cluster_miles' => (float) env('COMMUNITY_NEWS_AREA_MILES', 20),

        // How many nuggets the researcher aims to produce per area.
        'items_per_area' => (int) env('COMMUNITY_NEWS_ITEMS_PER_AREA', 6),

        // ChitChat drip trial: items per post, and the minimum days between
        // posts for one area.
        'chitchat_items_per_post' => (int) env('COMMUNITY_NEWS_CHITCHAT_ITEMS', 1),
        'chitchat_min_days'       => (int) env('COMMUNITY_NEWS_CHITCHAT_MIN_DAYS', 3),

        // Weekly email: minimum days between digests for one area, and how many
        // items to include.
        'email_min_days'  => (int) env('COMMUNITY_NEWS_EMAIL_MIN_DAYS', 7),
        'email_max_items' => (int) env('COMMUNITY_NEWS_EMAIL_MAX_ITEMS', 6),

        // Safety bound on the Anthropic server-tool (web_search) loop.
        'max_search_iterations' => (int) env('COMMUNITY_NEWS_MAX_SEARCH_ITER', 8),

        // How many days a researched item stays eligible for posting/emailing.
        'item_freshness_days' => (int) env('COMMUNITY_NEWS_ITEM_FRESHNESS_DAYS', 10),

        // Curated per-place source store (JSON files). Research seeds the model
        // with these known-good local feeds, health-checks them each run, and
        // re-discovers new ones roughly quarterly. See
        // data/community-news-sources/README.md.
        'sources_path' => env('COMMUNITY_NEWS_SOURCES_PATH', base_path('data/community-news-sources')),
        'source_recheck_hours' => (int) env('COMMUNITY_NEWS_SOURCE_RECHECK_HOURS', 24),
        'source_dead_after' => (int) env('COMMUNITY_NEWS_SOURCE_DEAD_AFTER', 3),
        'source_discovery_days' => (int) env('COMMUNITY_NEWS_SOURCE_DISCOVERY_DAYS', 90),
    ],

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
        // Arrival cutoff (server local time). Only posts that arrived on or after this
        // instant ever START rippling; older pending posts are left alone. This is the
        // flood guard: when rippling first turns on, every historical pending post would
        // otherwise become eligible at once and fan out a wall of mail. With the cutoff,
        // only recent posts ripple, so turn-on is a trickle. It applies to the scoped
        // group experiment too (an area run still ripples only post-cutoff posts inside
        // the polygon), so for a clean before/after boundary set RIPPLE_ENABLED_AT in
        // .env.background to the day you switch the experiment on, alongside
        // RIPPLE_WITHIN_GROUPS - otherwise the first run back-fills the gap to this date.
        // Empty string disables the cutoff (ripple everything, e.g. in tests).
        'enabled_at' => env('RIPPLE_ENABLED_AT', '2026-06-23'),
        // Group experiment scope: comma-separated group ids that ripple even while the global
        // RIPPLE_ENABLED switch is OFF. When non-empty, the scheduled ripple:expand cron runs SCOPED
        // to these groups' polygons, so ONLY these groups' posts ripple (origin-in-polygon) and
        // everyone else stays dark - this is the per-group before/after experiment. Empty = no
        // experiment. RIPPLE_ENABLED remains the network-wide kill switch for the unscoped rollout.
        'within_groups' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('RIPPLE_WITHIN_GROUPS', ''))
        ))),
        // Density curve passed to /v1/ripple-schedule (see iznik-routing-go ripple.go).
        'curve' => env('RIPPLE_CURVE', 'step-70'),
        // Travel mode for the reach isochrone.
        'mode' => env('RIPPLE_MODE', 'drive'),
        // Maximum drive-time (minutes) the reach may grow to.
        'max_minutes' => (float) env('RIPPLE_MAX_MINUTES', 30),
        // How many reach schedules to compute CONCURRENTLY (Http::pool fan-out in
        // ExpandService::initialiseNew). Each /v1/ripple-schedule request is CPU-bound on the
        // routing host (one Dijkstra + polygon rasterisations), so cap this near the routing
        // host's core count; exceeding it just queues on the routing server. DB writes stay
        // serial regardless. 1 = sequential (the old behaviour).
        'compute_concurrency' => (int) env('RIPPLE_COMPUTE_CONCURRENCY', 8),
        // Reuse an already-computed reach when another live post shares the SAME blurred origin,
        // instead of hitting the routing server again. The reach schedule is a deterministic
        // function of the blurred origin (4dp) + the ripple config here, so this is exact and it
        // removes the bulk of routing calls on the recompute drain (co-located posts, repeat posters
        // from home). Set false for one full recompute after changing curve/max_minutes/extent so
        // reaches computed under the OLD config are not reused. true = on.
        'reuse_reach' => filter_var(env('RIPPLE_REUSE_REACH', true), FILTER_VALIDATE_BOOLEAN),
        // Per-request timeout (seconds) for a /v1/ripple-schedule call. A dense-origin post can
        // take tens of seconds; the pool path uses this so one slow post does not abort the chunk.
        'request_timeout' => (int) env('RIPPLE_REQUEST_TIMEOUT', 60),
        // ripple:proximity-notes (moderator "quicker to get to" note) — computed out of the hot
        // ripple:expand cron, so a slacker timeout is fine. Slow calls (> proximity_slow_ms) and
        // failures are reported to Sentry. proximity_notes gates the whole feature (kill switch
        // independent of RIPPLE_ENABLED).
        'proximity_notes' => filter_var(env('RIPPLE_PROXIMITY_NOTES', true), FILTER_VALIDATE_BOOLEAN),
        'proximity_timeout' => (int) env('RIPPLE_PROXIMITY_TIMEOUT', 15),
        // reachable_gate: ripple targeting is gated on the routing server's
        // reachable-group signal - a group is targeted only when an active member
        // living inside the group's own polygon has a road-reachable street node,
        // so a post never crosses an uncrossable barrier. Default ON; set
        // RIPPLE_REACHABLE_GATE=false as the killswitch (reverts targeting and
        // retraction to polygon-overlap only). Independent of RIPPLE_ENABLED; an
        // empty/absent list falls back to polygon-only for that post.
        'reachable_gate' => filter_var(env('RIPPLE_REACHABLE_GATE', true), FILTER_VALIDATE_BOOLEAN),
        'proximity_slow_ms' => (int) env('RIPPLE_PROXIMITY_SLOW_MS', 3000),
        // Reply-saturation stop (extent-governor design T1.1): a post with at least this many
        // DISTINCT repliers (distinct users with an Interested chat reply on the post,
        // chat_messages.refmsgid = msgid) stops expanding - it already has plenty of interest, so
        // further reach is wasted. Applies both at init (an already-saturated post never starts
        // rippling) and per tick (a post that becomes saturated stops fanning out). 0 disables.
        // 5 = the figure from the Discourse rippling thread.
        'reply_saturation_stop' => (int) env('RIPPLE_REPLY_SATURATION_STOP', 5),
        // Hours a rippled-in (messages_groups.rippled_in=1) post, already Approved on its
        // origin group, waits before it is approved onto the rippled-in group (it was already
        // vetted on origin). Default 0 = approve AT ripple-in time, so it never even flickers
        // into the Pending mod queue - this keeps the moderation load of rippling off the
        // receiving groups, since the post was already moderated on its origin group. >0 leaves
        // it Pending for AutoApproveService to approve after the window (a mod-veto window for
        // groups that want to eyeball rippled-in posts before they appear).
        'rippled_in_pending_hours' => (int) env('RIPPLE_RIPPLED_IN_PENDING_HOURS', 0),
        // Wall-clock hazard schedule (hours since arrival) at which the reach
        // expands one tick. One schedule tick is requested per entry, so the
        // number of ticks equals the length of this array.
        'hazard_hours' => [1, 3, 6, 12, 24, 48, 72, 120, 168],
        // Only expand during active hours (server local time): inclusive start,
        // exclusive end. Outside this window, due expansions wait.
        'active_start_hour' => (int) env('RIPPLE_ACTIVE_START_HOUR', 6),
        'active_end_hour' => (int) env('RIPPLE_ACTIVE_END_HOUR', 23),
        // Audience-budget extent governor — Stage A (feed-forward). Caps reach at
        // the ~target_users NEAREST freeglers instead of letting a fixed drive-time
        // sweep density-blind: in dense areas (London) the cap binds at a small
        // radius; where the reachable pool is already below target_users it never
        // binds (rural unchanged). The cap is applied in the routing server
        // (/v1/ripple-schedule target_users), so ExpandService is untouched. Ships
        // DARK: with enabled=false ReachService sends no target_users and reach is
        // identical to before. See plans/2026-06-28-ripple-extent-governor-mvp.md.
        // (Per-RU-class stratification — target_by_ru — is the planned Stage-A
        // refinement and is not yet wired; this first cut is a single global cap.)
        'extent' => [
            'enabled' => (bool) env('RIPPLE_EXTENT_ENABLED', false),
            'target_users' => (int) env('RIPPLE_EXTENT_TARGET_USERS', 4000),
        ],
        // Unified-digest score-ordering (see App\Services\Ripple\DigestPostScorer).
        // Mirrors the /rippling "Digest preview" weights. Tunable via env without a deploy.
        'score' => [
            'weights' => [
                'close'  => (float) env('RIPPLE_DIGEST_W_CLOSE', 1.0),
                'fresh'  => (float) env('RIPPLE_DIGEST_W_FRESH', 0.0),
                'budget' => (float) env('RIPPLE_DIGEST_W_BUDGET', 1.0),
                'anchor' => (float) env('RIPPLE_DIGEST_W_ANCHOR', 0.0),
            ],
            'window_hours' => (float) env('RIPPLE_DIGEST_WINDOW_HOURS', 24),
            'budget_decay' => (float) env('RIPPLE_DIGEST_BUDGET_DECAY', 25),
            // ~30km, the 30-min drive-isochrone analogue. Used for posts with no
            // rippling_reach row (the dominant case while rippling is dark, and for
            // all backlog posts after go-live).
            'default_reach_metres' => (float) env('RIPPLE_DIGEST_DEFAULT_REACH_M', 30000),
        ],

        // Email distance-preference filter (Nearby browse's distance slider, extended
        // to member-facing emails) — see App\Services\Ripple\DistancePreferenceFilter and
        // docs/superpowers/specs/2026-07-01-distance-preference-email-filtering-design.md.
        // Narrows the daily digest / immediate cursor / reach-mail pipelines to posts
        // within a member's settings.browseMaxDistance (miles); absent or the sentinel
        // (Number.MAX_SAFE_INTEGER) means "no limit" — the default for every member who
        // has never touched the slider, so the feature is inherently opt-in and safe on
        // its own. This flag is an EXTRA emergency kill-switch on top of that: default
        // true (filtering active for members who set a limit); set to false to fall back
        // to today's fully-unfiltered behaviour for EVERY member (including those with a
        // configured limit) with no deploy, if a bug is ever found in the filter itself.
        'distance_filter' => [
            'enabled' => filter_var(env('RIPPLE_DISTANCE_FILTER_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | First Reply
    |--------------------------------------------------------------------------
    |
    | Getting a first reply in quickly, and making the wait bearable when there
    | isn't one. 44% of rippled posts get no reply at all, and a poster who hears
    | nothing has no way to tell "nobody wants it" from "it isn't working".
    |
    | Four levers, each independently switchable:
    |   passthrough - never hold a post's FIRST reply if the replier is somewhere
    |                 the post's reach will eventually get to anyway.
    |   scouts      - tell a handful of likely-interested people early, instead of
    |                 waiting for their digest or for the ripple to arrive.
    |   chat        - Freegle talks to the poster: asks the questions that make a
    |                 post more likely to succeed, and says what is happening.
    |
    | Ships DARK. Every lever is off until switched on, so deploying this changes
    | nothing.
    |
    */

    'firstreply' => [
        // Master switch. Off means none of the below runs, whatever they say.
        'enabled' => filter_var(env('FIRSTREPLY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        // Share of POSTS in the trial, 0-100. A post is in or out for its whole
        // life and across all three levers at once, so the arms never overlap and
        // an effect can actually be attributed. See App\Services\FirstReply\Rollout.
        //
        // DEFAULTS TO 0: switching a lever on does nothing until a percentage is
        // set too. Forgetting the percentage then costs a quiet run that says so
        // in the cron log, where the opposite default would cost an unplanned
        // full-network rollout of something that sends mail.
        'rollout_percent' => (int) env('FIRSTREPLY_ROLLOUT_PERCENT', 0),

        // Let a post's first reply through even when the replier is outside the
        // reach the post has RIGHT NOW, as long as they are inside the reach it
        // will eventually have. Holding them buys nothing - they were always
        // going to be allowed - so the hold only turns a fast reply into a slow
        // one, on exactly the posts that can least afford it.
        'passthrough' => [
            'enabled' => filter_var(env('FIRSTREPLY_PASSTHROUGH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            // How many distinct repliers a post may already have and still get the
            // passthrough. 1 = only the very first reply. Raise to soften the cliff
            // for posts where the first replier goes quiet.
            'max_existing_repliers' => (int) env('FIRSTREPLY_PASSTHROUGH_MAX_REPLIERS', 1),
        ],

        // Tell a few likely-interested people early about a post nobody has
        // replied to yet.
        'scouts' => [
            'enabled' => filter_var(env('FIRSTREPLY_SCOUTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            // How long a post gets to attract a reply on its own before we help.
            // ZERO: scout as soon as the post is seen.
            //
            // An earlier version waited 45 minutes on the theory that it would
            // avoid spending mail on posts that were about to get a reply anyway.
            // That theory does not survive contact with the timings: whatever we
            // save by holding back is dwarfed by how long the scout then takes to
            // read their mail and reply. The wait removed nothing from the mail
            // bill and added itself to every reply.
            //
            // Kept as a knob rather than deleted, because it is the natural lever
            // if scout mail ever needs rationing.
            'quiet_minutes' => (int) env('FIRSTREPLY_SCOUTS_QUIET_MINUTES', 0),
            // Give up after this: a day-old silent post is a job for reposting and
            // better post quality, not for more notifications.
            'max_age_hours' => (int) env('FIRSTREPLY_SCOUTS_MAX_AGE_HOURS', 24),
            // Cap on PROPENSITY scouts per post - the "you reply to a lot of
            // things" ones. Small on purpose: that signal is a guess, and a guess
            // is what should be rationed. Does NOT apply to people who actually
            // asked for the item; see max_strong_per_post.
            //
            // OVERRIDABLE AT RUNTIME: a `firstreply_scouts_max_per_post` row in
            // the `config` table wins over this, so the mail bill can be turned
            // down (or off, with 0) without waiting for a deploy. This env value
            // is the default when that row is absent. See ScoutService::scoutConfig().
            'max_per_post' => (int) env('FIRSTREPLY_SCOUTS_MAX_PER_POST', 10),
            // Safety ceiling on wanted/search scouts - people with an open post
            // for this item or a matching saved search. They asked, so there is
            // no good reason to tell the first ten and not the eleventh, and the
            // small cap above deliberately does not apply to them.
            //
            // Not unlimited, though. In the most recent 200k rows of
            // users_searches alone (0.7% of a 27M-row table) 358 members hold the
            // term "Sofa" and 313 "Table", so an unbounded common OFFER could mail
            // thousands. The true fan-out is unmeasured - the one live source at
            // this threshold, messages_matched_notified, is itself capped by
            // matched.match_limit_per_post - so this is set well above anything
            // expected, logged and counted when it bites, and tunable at runtime
            // via `firstreply_scouts_max_strong_per_post`.
            'max_strong_per_post' => (int) env('FIRSTREPLY_SCOUTS_MAX_STRONG_PER_POST', 50),
            // Nobody should become Freegle's unpaid alerting service. A member is
            // not scouted again within this many hours...
            'user_cooldown_hours' => (int) env('FIRSTREPLY_SCOUTS_USER_COOLDOWN_HOURS', 24),
            // ...nor more than this many times in a rolling week.
            'user_max_per_week' => (int) env('FIRSTREPLY_SCOUTS_USER_MAX_PER_WEEK', 5),
            // Minimum score to be worth mailing at all. A post with no good match
            // should mail nobody rather than pad the list out to max_per_post.
            'min_score' => (float) env('FIRSTREPLY_SCOUTS_MIN_SCORE', 1.0),
            // How many distinct Interested replies in the last 90 days make someone
            // a "frequent replier" worth considering on propensity alone.
            'frequent_replier_min' => (int) env('FIRSTREPLY_SCOUTS_FREQUENT_MIN', 3),
            // Candidate pool size before scoring. Bounds the cost of the geo query.
            'candidate_limit' => (int) env('FIRSTREPLY_SCOUTS_CANDIDATE_LIMIT', 500),
        ],

        // The Freegle chat: Freegle itself talks to the poster.
        'chat' => [
            'enabled' => filter_var(env('FIRSTREPLY_CHAT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            // The account the messages come from. Resolved by email, created on
            // first use if absent, so there is nothing to seed by hand.
            'system_user_email' => env('FIRSTREPLY_SYSTEM_USER_EMAIL', 'freegle@ilovefreegle.org'),
            'system_user_name' => env('FIRSTREPLY_SYSTEM_USER_NAME', 'Freegle'),

            // Minimum gap between two Freegle messages to the same member. With
            // grouping this is a backstop rather than the main control - one
            // message already covers everything they have outstanding.
            'user_gap_hours' => (int) env('FIRSTREPLY_CHAT_USER_GAP_HOURS', 6),
            // How long before the same question can be asked again. The unit is
            // the MEMBER, not the post: one message covers everything they have
            // outstanding, so "have they been asked this lately" is a question
            // about them. Long, because these are nudges, not a conversation.
            'kind_cooldown_days' => (int) env('FIRSTREPLY_CHAT_KIND_COOLDOWN_DAYS', 14),
            // Prompts stop being answerable after this - a week-old "could you
            // deliver?" on a long-gone item should not still have live buttons.
            'expiry_days' => (int) env('FIRSTREPLY_CHAT_EXPIRY_DAYS', 7),

            // When each prompt becomes due, in hours after the post arrived. The
            // order here is the order they are considered; the first one that is
            // due and applicable wins, and only one is sent per run per post.
            'schedule' => [
                // Ask early, while editing still feels like part of posting.
                'photo' => (float) env('FIRSTREPLY_CHAT_PHOTO_HOURS', 1.5),
                // Offering delivery is the single biggest thing a poster can
                // change about a silent post, so it comes before anything else
                // that is merely informative.
                'delivery' => (float) env('FIRSTREPLY_CHAT_DELIVERY_HOURS', 3),
                // "People are looking" only reassures once there is something to
                // report, hence later than the asks.
                'views' => (float) env('FIRSTREPLY_CHAT_VIEWS_HOURS', 8),
                // A deadline is what turns "someday" into "this weekend", and it
                // only makes sense once the easy wins have not worked.
                'deadline' => (float) env('FIRSTREPLY_CHAT_DEADLINE_HOURS', 24),
            ],

            // Do not claim people are looking until enough of them have. One
            // curious visitor is not encouraging, it is depressing.
            'views_min' => (int) env('FIRSTREPLY_CHAT_VIEWS_MIN', 5),

            // Posts older than this are past the point where a nudge helps.
            'max_age_hours' => (int) env('FIRSTREPLY_CHAT_MAX_AGE_HOURS', 72),

            // How many posts one run of the cadence engine will consider.
            'batch_limit' => (int) env('FIRSTREPLY_CHAT_BATCH_LIMIT', 200),
        ],
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
        // Read side: Loki's HTTP query API, for monitor:deprecated-endpoints.
        'query_url' => env('LOKI_URL', 'http://loki:3100'),
    ],

    // apiv2's deprecated-endpoint registry for monitor:deprecated-endpoints — the
    // single source of truth for which routes are deprecated + their sunset dates
    // (served from the same deprecation.Marker() calls that log the hits, so the
    // set and the logging can't drift). NOT the OpenAPI spec: go-swagger's
    // swagger:route form can't carry an x-sunset extension. The Go API serves it
    // at /deprecated on port 8192; inside the compose network the batch container
    // reaches it as http://apiv2:8192 (NOT port 80). PROD must set
    // APIV2_DEPRECATED_URL for its network (batch-prod isn't in this compose
    // network); if the fetch fails the command warns and exits non-zero.
    'apiv2_deprecated_url' => env('APIV2_DEPRECATED_URL', 'http://apiv2:8192/apiv2/deprecated'),

    // The routing-backed town/near endpoint browse:backfill-max-distance uses to
    // recompute the mile radius a travel-time budget reaches - the same source the
    // frontend slider uses, so the derived cap matches what a member re-dragging
    // the slider would get. Same reachability note as apiv2_deprecated_url: inside
    // this compose network the batch container reaches apiv2 on port 8192, and
    // batch-prod must set BROWSE_TOWN_NEAR_URL for its own network.
    'town_near_url' => env('BROWSE_TOWN_NEAR_URL', 'http://apiv2:8192/api/town/near'),

    // monitor:deprecated-endpoints observation window (days). Bounded under Loki's
    // max_query_length (~30d) — a longer since-sunset range 400s; this many days of
    // post-sunset silence is enough to call an endpoint retirable.
    'deprecated_endpoints' => [
        'observation_window_days' => (int) env('DEPRECATED_ENDPOINTS_WINDOW_DAYS', 29),
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
