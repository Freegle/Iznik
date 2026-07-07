# Logging and Email Tracking Research

This document covers research on:
1. Searchable application logs (not DB-based)
2. Email content archiving with 7-day retention
3. Visual email flow design tools
4. Retention & user journey automation
5. Migrating iznik-server `logs` table to Loki
6. Self-hosted Loki implementation plan
7. User activity timeline UI for Support Tools

## Current State

### Existing Logs in iznik-batch

The Laravel batch code currently uses ~50 log statements across services:

**DEBUG level** (technical/developer):
- `DigestService`: "No new messages for {group} at frequency {freq}"
- `UserManagementService`: "Could not update {table}.{column}"

**INFO level** (operations/support):
- Job start/complete messages with stats
- "Skipping closed group: {group}"
- "Purged {count} spam chat messages"
- "Merged user {id} into {id}"
- "Deadline expired for message #{id}"

**ERROR level** (failures):
- "Failed to send digest to user {userid}"
- "MJML compilation failed"
- "Error processing chat room {id}"

### Current Problems
1. Logs go to filesystem only - not searchable by support staff
2. No email content archiving - can't see what was sent to users
3. DB-based logging in iznik-server is problematic at scale
4. No visual way to understand/design email flows

---

## Part 1: Searchable Application Logs

### Option A: Grafana Loki (Recommended for logs)

[Grafana Loki](https://grafana.com/oss/loki/) - Lightweight log aggregation with search UI.

**Why Loki over ELK:**
- Much simpler to run (single binary)
- Lower resource usage
- Free to self-host, or [Grafana Cloud free tier](https://grafana.com/pricing/) includes 50GB logs
- Good enough search for support staff queries

**Setup:**
```yaml
# Add to docker-compose
loki:
  image: grafana/loki:latest
  ports:
    - "3100:3100"
  command: -config.file=/etc/loki/local-config.yaml

grafana:
  image: grafana/grafana:latest
  ports:
    - "3000:3000"
  environment:
    - GF_AUTH_ANONYMOUS_ENABLED=true
```

Laravel integration via [alexmacarthur/laravel-loki-logging](https://packagist.org/packages/alexmacarthur/laravel-loki-logging).

**Log Routing Strategy:**
- DEBUG → Local file with rotation (developers only)
- INFO/WARNING/ERROR → Loki (searchable by support staff)

### Option B: Papertrail / Logtail (SaaS)

If you don't want to run infrastructure:
- [Papertrail](https://papertrailapp.com/) - Free tier: 50MB/month
- [Logtail](https://betterstack.com/logtail) - Free tier: 1GB/month
- Both have nonprofit discounts available on request

---

## Part 2: Email Content Archiving (7-day retention)

You want to store the actual content of sent emails so support can see "what did we send to user X?"

### Option A: Piler (Recommended - Postfix-Native)

[Piler](https://www.mailpiler.org/) - **Actively maintained**, enterprise-grade open source email archiver designed for Postfix.

**Why Piler:**
- Works at the MTA level (Postfix) - archives ALL emails regardless of what app sends them
- Mature, widely deployed (enterprise alternative to commercial solutions)
- Full-text search with Sphinx indexing
- Web UI with authentication (LDAP, Google OAuth, 2FA, IMAP)
- Deduplication, encryption, digital fingerprinting
- Retention policies built-in
- Docker deployment available

**How it works:**
1. Configure Postfix to BCC all emails to Piler via `always_bcc`
2. Piler receives, parses, compresses, encrypts, and stores emails
3. Sphinx indexes content for fast full-text search
4. PHP web UI for searching and viewing emails

**Postfix setup:**
```bash
# In /etc/postfix/main.cf
always_bcc = archive@piler.yourdomain.com
```

**7-day retention:**
```bash
# In piler.conf
default_retention_days = 7
```

**Advantages over Laravel packages:**
- Archives emails from iznik-server PHP mail AND iznik-batch Laravel mail
- Doesn't add database load to your application
- Battle-tested at scale
- Support staff can search without access to your app database

**Deduplication & Compression (built-in):**

Piler deduplicates at the **attachment level** and compresses all content:
- Same attachment in multiple emails = stored once (e.g., PDFs, attached images)
- Email bodies are compressed with zlib (~50-70% savings)
- Bodies with per-user content (greetings, unsubscribe links) are NOT deduplicated

**Freegle storage depends on email structure:**
- If digest images are **MIME attachments** → Good dedup (images stored once)
- If digest images are **inline base64 in HTML** → No dedup, just compression
- If digest images are **URL references** → Tiny emails, minimal storage

**Actual Freegle storage estimate (from postfix logs Dec 2025):**
- Volume: ~350,000 emails/day
- Average size: 47KB (mostly 20-50KB HTML with URL-referenced images)
- Unique message-ids ≈ total emails (per-user customization limits dedup)

| Scenario | 7-Day Storage |
|----------|---------------|
| Raw (no compression) | ~115GB |
| With ~60% compression | ~46GB |
| With attachment dedup | ~40-45GB (minimal benefit - emails are unique) |

**Realistic requirement: ~50GB storage for 7-day retention**

**Pricing:**
- **Open Source** (self-hosted): **Free** - GPLv3 license, full features
- **Enterprise** (self-hosted): €1,200 first year, €300/year after - adds AI features, priority support
- **No hosted service** - Piler is self-hosted only

Self-hosting cost: ~€7/month for Hetzner VM (4GB RAM, 80GB SSD) or run on existing infrastructure.

**Grafana Integration:**

Piler has its own web UI for email search - it doesn't integrate directly into Grafana as a data source. Options:

| Approach | Description |
|----------|-------------|
| **Separate UIs** (recommended) | Grafana for logs (Loki), Piler for email content |
| **Link panel** | Add Grafana dashboard link that opens Piler UI |
| **Iframe embed** | Embed Piler UI in Grafana panel (separate auth) |

Piler does support Grafana for **monitoring the archive itself** (disk usage, email volume metrics) via Grafana Agent + Promtail, but email searching stays in Piler's dedicated UI.

**ModTools Integration:**

Piler supports custom PHP authentication hooks. To restrict access to support/admin only:

```php
// /etc/piler/config-site.php
$config['CUSTOM_PRE_AUTH_FUNCTION'] = 'freegle_auth';

function freegle_auth($username) {
    // Call Freegle API to check user role
    $ch = curl_init("https://api.ilovefreegle.org/api/session");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-User-Email: ' . $username]);
    $response = curl_exec($ch);
    $user = json_decode($response);

    // Only allow Support and Admin roles
    if ($user && in_array($user->systemrole, ['Support', 'Admin'])) {
        return [$username];
    }
    return []; // Deny access
}
```

### Option B: wnx/laravel-sends (Laravel-Only)

[wnx/laravel-sends](https://github.com/stefanzweifel/laravel-sends) - **Very actively maintained** (Nov 2025, 316k downloads, Laravel 11/12 support)

Only archives emails sent from Laravel (not from iznik-server PHP).

**Features:**
- Tracks all outgoing emails with metadata
- Associate emails with Eloquent models (user, message, etc.)
- Built-in pruning support via Laravel's Prunable trait
- Optional full email body storage
- UUID tracking for webhook integration (bounces, opens)
- No web UI - you'd build a simple viewer or use with Laravel Nova

**Setup:**
```bash
composer require wnx/laravel-sends
php artisan vendor:publish --tag="sends-migrations"
php artisan migrate
```

**7-day pruning (built-in):**
```php
// In your Send model or config
protected function prunable(): Builder
{
    return static::where('created_at', '<=', now()->subDays(7));
}
```

Then run `php artisan model:prune` daily.

### Option C: Store in Object Storage (High Volume)

For very high email volume, consider:
1. Store email content as JSON/HTML files in S3/MinIO
2. S3 lifecycle rules auto-delete after 7 days
3. Index metadata (recipient, subject, date) in a simple table for searching
4. Retrieve content from S3 on-demand

This avoids bloating the database with HTML content.

### Comparison

| Solution | Scope | Web UI | Search | Maintenance | Widely Used |
|----------|-------|--------|--------|-------------|-------------|
| **Piler** | All Postfix mail | Yes | Full-text | Active | Yes (enterprise) |
| wnx/laravel-sends | Laravel only | No | Basic | Very Active | 316k downloads |
| gearbox-solutions/mail-log | Laravel only | Yes | Basic | Active | Low adoption |

### Recommendation

**Piler** is the better choice because:
1. Archives all outgoing email (PHP and Laravel) at the MTA level
2. Doesn't add load to your application database
3. Enterprise-grade search and retention
4. Works regardless of which app sends the email
5. Support staff get a dedicated archive interface

---

## Part 3: Email Flow Visualization & Design Tools

You want to visually see/design what emails are sent when, and potentially control re-engagement flows.

### Option A: Dittofeed (Best Fit - Recommended)

[Dittofeed](https://www.dittofeed.com) ([GitHub](https://github.com/dittofeed/dittofeed))

**What it does:**
- **Visual Journey Builder** - Drag-and-drop flow designer
- Design sequences like: "User posts offer → Wait 7 days → If no responses, send reminder → Wait 3 days → Send expiry warning"
- Branch logic based on user actions
- Re-engagement campaigns

**Pricing:**
- **Self-hosted: FREE**
- Cloud: $75/month

**Integration approach:**
1. Self-host Dittofeed alongside your stack
2. Send events from Laravel when things happen (user joins, posts message, message expires, etc.)
3. Dittofeed handles the "what email to send when" logic
4. Your existing SMTP setup delivers the emails

**Example flow you could design:**
```
[User posts OFFER]
    ↓
[Wait 48 hours]
    ↓
[Check: Has message been taken?]
    ├─ Yes → [End]
    └─ No → [Send "Any luck with your offer?" email]
              ↓
           [Wait 5 days]
              ↓
           [Check: Has message been taken?]
              ├─ Yes → [End]
              └─ No → [Send "Your post is expiring soon" email]
```

### Option B: Laudspeaker

[Laudspeaker](https://github.com/laudspeaker/laudspeaker)

Similar to Dittofeed - visual journey builder, self-hostable, open source. More focused on product onboarding than marketing.

### Option C: Mautic

[Mautic](https://www.mautic.org)

Full marketing automation platform. More heavyweight - includes landing pages, CRM features, lead scoring. Probably overkill for your needs.

### Option D: Just Document It

If you don't want to add another system, create a visual diagram (Mermaid/draw.io) of your current email flows and maintain it manually. Less powerful but simpler.

### Comparison

| Tool | Visual Builder | Self-Host Cost | Event-Triggered | Re-engagement |
|------|---------------|----------------|-----------------|---------------|
| Dittofeed | Yes | Free | Yes | Yes |
| Laudspeaker | Yes | Free | Yes | Yes |
| Mautic | Yes | Free | Yes | Yes (heavy) |
| Manual docs | No | Free | N/A | N/A |

---

## Part 4: Retention & User Journey Automation

This section focuses on keeping users engaged and bringing inactive users back.

### What Dittofeed Offers for Retention

Dittofeed is particularly strong for retention because it's designed for **lifecycle marketing** - the ongoing communication with users throughout their relationship with your platform.

**Key Retention Features:**

1. **Segment-Based Targeting**
   - Create segments like "Users who haven't logged in for 14 days"
   - "Users who posted but never received a reply"
   - "Users who browsed but never posted"
   - Segments update automatically based on user behavior

2. **Multi-Channel Journeys**
   - Email (primary)
   - Push notifications (mobile)
   - SMS (if needed)
   - Webhooks (trigger custom actions)

3. **Journey Analytics**
   - See conversion rates at each step
   - Identify where users drop off
   - A/B test different message content or timing

4. **Event-Driven Triggers**
   - "User hasn't opened app in X days" → Send re-engagement email
   - "User's message expired without success" → Send encouragement
   - "User received first reply" → Send "how to respond" tips

### Freegle-Specific Retention Journeys

Here are example journeys you could design for Freegle:

**New User Onboarding:**
```
[User signs up]
    ↓
[Wait 1 hour]
    ↓
[Send "Welcome to Freegle" email with tips]
    ↓
[Wait 3 days]
    ↓
[Check: Has user posted anything?]
    ├─ Yes → [Send "Great first post!" email]
    └─ No → [Send "Looking for something?" nudge email]
              ↓
           [Wait 7 days]
              ↓
           [Check: Has user posted?]
              ├─ Yes → [End - they're active]
              └─ No → [Send "Your neighbors are sharing" with local stats]
```

**Re-engagement for Dormant Users:**
```
[User inactive for 30 days]
    ↓
[Send "We miss you" email with recent local offers]
    ↓
[Wait 7 days]
    ↓
[Check: Did user return?]
    ├─ Yes → [End - they're back]
    └─ No → [Send "Quick poll: Why did you stop using Freegle?"]
              ↓
           [Wait 14 days]
              ↓
           [Check: Still inactive?]
              ├─ No → [End]
              └─ Yes → [Mark as "long-term dormant", reduce email frequency]
```

**Post Success Follow-up:**
```
[Message marked as TAKEN/RECEIVED]
    ↓
[Wait 1 day]
    ↓
[Send "How did it go?" email with feedback request]
    ↓
[Wait 2 days]
    ↓
[Send "Got more to give?" prompt]
```

**Failed Post Recovery:**
```
[Message expires with no success]
    ↓
[Wait 1 day]
    ↓
[Send "No luck this time" empathy email with suggestions]
    ↓
[Check: Did user repost or post something else?]
    ├─ Yes → [Send encouragement]
    └─ No → [Wait 5 days]
              ↓
           [Send "Still have it? Try again" with tips]
```

### Integration with Laravel

To use Dittofeed with iznik-batch, you'd send events when things happen:

```php
// Example: Send event when user posts a message
use GuzzleHttp\Client;

class DittofeedService
{
    public function trackEvent(User $user, string $event, array $properties = []): void
    {
        $client = new Client(['base_uri' => config('freegle.dittofeed_url')]);

        $client->post('/api/v1/track', [
            'json' => [
                'userId' => (string) $user->id,
                'event' => $event,
                'properties' => array_merge([
                    'email' => $user->email_preferred,
                    'name' => $user->fullname,
                ], $properties),
                'timestamp' => now()->toIso8601String(),
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . config('freegle.dittofeed_api_key'),
            ],
        ]);
    }
}

// Usage in services:
$dittofeed->trackEvent($user, 'message_posted', ['type' => 'offer', 'group' => $group->nameshort]);
$dittofeed->trackEvent($user, 'message_taken', ['message_id' => $message->id]);
$dittofeed->trackEvent($user, 'reply_received', ['chat_id' => $chat->id]);
```

### Alternative: Build It In Laravel

If you'd prefer not to add another system, you could build simple retention logic directly in Laravel:

**Pros:**
- No extra infrastructure
- Full control over logic
- Uses existing database

**Cons:**
- No visual journey builder
- Harder to iterate on campaigns
- Need to build analytics yourself

**Simple Laravel approach:**
```php
// Track user engagement in a simple table
Schema::create('user_engagement', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('userid');
    $table->timestamp('last_active_at');
    $table->timestamp('last_post_at')->nullable();
    $table->timestamp('last_email_sent_at')->nullable();
    $table->string('engagement_status'); // active, cooling, dormant, churned
    $table->timestamps();
});

// Scheduled job to send re-engagement emails
class SendReengagementEmails extends Command
{
    public function handle()
    {
        // Users inactive for 14+ days who haven't received email in 7 days
        $dormantUsers = UserEngagement::where('engagement_status', 'dormant')
            ->where('last_email_sent_at', '<', now()->subDays(7))
            ->get();

        foreach ($dormantUsers as $engagement) {
            Mail::send(new ReengagementEmail($engagement->user));
            $engagement->update(['last_email_sent_at' => now()]);
        }
    }
}
```

### Recommendation for Retention

**Start simple, grow into Dittofeed:**

1. **Phase 1 (Now):** Document your ideal user journeys in markdown/mermaid
2. **Phase 2 (Soon):** Implement basic re-engagement emails in Laravel scheduled jobs
3. **Phase 3 (Later):** Evaluate Dittofeed when you want:
   - Visual journey design
   - A/B testing
   - Multi-channel (push notifications)
   - Complex branching logic

Dittofeed's self-hosted version is genuinely free and would give you powerful tools, but it's another system to maintain. The Laravel approach is simpler but less flexible.

---

---

## Part 5: Migrating iznik-server `logs` Table to Loki

The main `logs` table in iznik-server contains activity logs (joins, posts, approvals, bounces, etc.). This can be migrated to Grafana Loki.

### Current `logs` Table Structure

| Column | Description | Loki Label? |
|--------|-------------|-------------|
| `id` | Auto-increment | No (value) |
| `timestamp` | When logged | Timestamp |
| `type` | Group/User/Message/Config/etc. | Yes |
| `subtype` | Created/Deleted/Joined/Left/etc. | Yes |
| `groupid` | Group reference | Yes |
| `user` | User affected | No (value) |
| `byuser` | User who did action | No (value) |
| `msgid` | Message reference | No (value) |
| `text` | Free text | No (value) |
| `configid`, `stdmsgid`, `bulkopid` | References | No (value) |

### Current Retention (from `purge_logs.php`)

| Log Type | Current Retention |
|----------|-------------------|
| Login/Logout | 365 days |
| User Created/Deleted | 31 days |
| Bounce | 90 days |
| Plugin | 1 day |
| Non-Freegle groups | 31 days |
| Orphaned (user/message gone) | 30 days |

### Migration Plan

**Phase 1: Dual-Write (iznik-server)**

Modify `Log::log()` in `include/misc/Log.php` to also send to Loki:

```php
// include/misc/Log.php - add after existing INSERT

public function log($params) {
    // Existing DB insert
    $params['timestamp'] = date("Y-m-d H:i:s", time());
    $atts = implode('`,`', array_keys($params));
    $vals = implode(',', $q);
    $sql = "INSERT INTO logs (`$atts`) VALUES ($vals);";
    $this->dbhm->background($sql);

    // NEW: Also send to Loki
    $this->sendToLoki($params);
}

private function sendToLoki($params) {
    $lokiUrl = getenv('LOKI_URL'); // e.g., https://logs-prod-us-central1.grafana.net
    if (!$lokiUrl) return;

    $labels = [
        'app' => 'iznik-server',
        'type' => $params['type'] ?? 'unknown',
        'subtype' => $params['subtype'] ?? 'unknown',
    ];

    if (!empty($params['groupid'])) {
        $labels['groupid'] = (string)$params['groupid'];
    }

    $payload = [
        'streams' => [[
            'stream' => $labels,
            'values' => [[
                (string)(time() * 1000000000), // nanoseconds
                json_encode($params)
            ]]
        ]]
    ];

    // Fire-and-forget HTTP POST
    $ch = curl_init($lokiUrl . '/loki/api/v1/push');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . getenv('LOKI_API_KEY')
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500); // Don't block
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}
```

**Phase 2: Backfill Historical Data**

One-time script to export recent logs:

```php
// scripts/migrate/migrate_logs_to_loki.php

$start = date('Y-m-d', strtotime('30 days ago'));
$batchSize = 1000;
$lastId = 0;

do {
    $logs = $dbhr->preQuery(
        "SELECT * FROM logs WHERE id > ? AND timestamp >= ? ORDER BY id LIMIT ?",
        [$lastId, $start, $batchSize]
    );

    foreach ($logs as $log) {
        sendToLoki($log); // Same function as above
        $lastId = $log['id'];
    }

    error_log("Migrated up to id $lastId");
    usleep(100000); // Rate limit
} while (count($logs) > 0);
```

**Phase 3: Verify & Disable DB Writes**

1. Run dual-write for 1-2 weeks
2. Verify Loki has all expected logs
3. Update `purge_logs.php` to delete faster (7 days instead of current retention)
4. Eventually remove DB INSERT from `Log::log()`

**Phase 4: Laravel Integration**

For iznik-batch, use the Laravel Loki package:

```bash
composer require alexmacarthur/laravel-loki-logging
```

```php
// config/logging.php
'channels' => [
    'loki' => [
        'driver' => 'custom',
        'via' => \AlexMacArthur\LaravelLokiLogging\LokiLoggerFactory::class,
        'url' => env('LOKI_URL'),
        'auth' => [
            'type' => 'basic',
            'username' => env('LOKI_USERNAME'),
            'password' => env('LOKI_API_KEY'),
        ],
        'labels' => [
            'app' => 'iznik-batch',
        ],
    ],
],
```

### Loki Retention Configuration

Match current `purge_logs.php` retention in Loki:

```yaml
# Self-hosted Loki config
limits_config:
  retention_period: 744h  # 31 days default

  retention_stream:
    - selector: '{subtype="Login"} or {subtype="Logout"}'
      period: 8760h  # 365 days
      priority: 1

    - selector: '{subtype="Bounce"}'
      period: 2160h  # 90 days
      priority: 2

    - selector: '{type="Plugin"}'
      period: 24h    # 1 day
      priority: 3
```

### Grafana Cloud Free Tier Fit

With aggregated logging (job summaries, not per-email):
- Estimated: ~3-10GB/month (well under 50GB limit)
- 3 users included (support staff)
- 14-day retention on free tier (shorter than some current retention)

**Note:** Grafana Cloud free tier has fixed 14-day retention. For longer retention (365 days for login/logout), you'd need:
- Self-hosted Loki (free, you control retention)
- Grafana Cloud Pro (see pricing below)

### Grafana Cloud Pricing

| Tier | Monthly Cost | Logs Included | Log Retention | Additional Logs |
|------|-------------|---------------|---------------|-----------------|
| **Free** | $0 | 50GB | 14 days | N/A |
| **Pro** | $19/month | 50GB | 30 days | $0.50/GB |
| **Advanced** | $299/month | 100GB | 13 months | Tiered pricing |
| **Enterprise** | $25,000+/year | Custom | Custom | Negotiated |

**Pro tier notes:**
- $19/month base includes 10k metrics, 50GB logs, 50GB traces
- Additional logs billed at $0.50/GB
- 30-day retention (still not enough for 365-day login logs)

**For Freegle's needs:**
- **Free tier** works if: aggregated logging only, 14-day retention acceptable
- **Pro tier** works if: need more users/dashboards, can accept 30-day max retention
- **Self-hosted Loki** works if: need custom retention (365 days for login), have server capacity

### Implementation Order

See **Part 6: Self-Hosted Loki Implementation Plan** below for detailed steps.

---

## Part 6: Self-Hosted Loki Implementation Plan

This section covers the complete implementation of self-hosted Loki with Google Cloud backup integration.

### Overview

| Phase | Description | Outcome |
|-------|-------------|---------|
| 1 | Docker setup + historical migration | Evaluate search capability |
| 2 | Near real-time sync from DB | Dual-write via cron |
| 3 | Google Cloud backup integration | Yesterday restore capability |
| 4 | Production cutover | Remove DB logging |

### Phase 1: Docker Setup & Historical Migration

#### 1.1 Docker Compose Configuration

Add to `docker-compose.yml`:

```yaml
  loki:
    image: grafana/loki:2.9.0
    container_name: freegle-loki
    ports:
      - "3100:3100"
    volumes:
      - loki-data:/loki
      - ./conf/loki-config.yaml:/etc/loki/local-config.yaml:ro
    command: -config.file=/etc/loki/local-config.yaml
    networks:
      - default
    restart: unless-stopped

  grafana:
    image: grafana/grafana:latest
    container_name: freegle-grafana
    ports:
      - "3200:3000"
    volumes:
      - grafana-data:/var/lib/grafana
    environment:
      - GF_SECURITY_ADMIN_PASSWORD=changeme
      - GF_USERS_ALLOW_SIGN_UP=false
    depends_on:
      - loki
    networks:
      - default
    restart: unless-stopped

volumes:
  loki-data:
  grafana-data:
```

#### 1.2 Loki Configuration

Create `conf/loki-config.yaml`:

```yaml
auth_enabled: false

server:
  http_listen_port: 3100
  grpc_listen_port: 9096

common:
  instance_addr: 127.0.0.1
  path_prefix: /loki
  storage:
    filesystem:
      chunks_directory: /loki/chunks
      rules_directory: /loki/rules
  replication_factor: 1
  ring:
    kvstore:
      store: inmemory

query_range:
  results_cache:
    cache:
      embedded_cache:
        enabled: true
        max_size_mb: 100

schema_config:
  configs:
    - from: 2020-10-24
      store: boltdb-shipper
      object_store: filesystem
      schema: v11
      index:
        prefix: index_
        period: 24h

ruler:
  alertmanager_url: http://localhost:9093

# Retention configuration - match purge_logs.php
limits_config:
  retention_period: 744h  # 31 days default

  # Per-stream retention (requires Loki 2.8+)
  retention_stream:
    - selector: '{subtype="Login"}'
      period: 8760h  # 365 days
    - selector: '{subtype="Logout"}'
      period: 8760h  # 365 days
    - selector: '{subtype="Bounce"}'
      period: 2160h  # 90 days
    - selector: '{type="Plugin"}'
      period: 24h    # 1 day
    - selector: '{subtype="Created"}'
      period: 744h   # 31 days
    - selector: '{subtype="Deleted"}'
      period: 744h   # 31 days

compactor:
  working_directory: /loki/compactor
  shared_store: filesystem
  retention_enabled: true
  retention_delete_delay: 2h
  retention_delete_worker_count: 150
```

#### 1.3 Historical Migration Script

Create `iznik-server/scripts/migrate/migrate_logs_to_loki.php`:

```php
<?php
/**
 * Migrate historical logs from MySQL to Loki.
 *
 * Usage: php migrate_logs_to_loki.php [--from=YYYY-MM-DD] [--batch=1000] [--dry-run]
 */
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

// Configuration
$lokiUrl = getenv('LOKI_URL') ?: 'http://localhost:3100';
$batchSize = 1000;
$fromDate = date('Y-m-d', strtotime('365 days ago'));
$dryRun = false;

// Parse arguments
foreach ($argv as $arg) {
    if (strpos($arg, '--from=') === 0) {
        $fromDate = substr($arg, 7);
    }
    if (strpos($arg, '--batch=') === 0) {
        $batchSize = (int)substr($arg, 8);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

error_log("Migrating logs from $fromDate (batch size: $batchSize)" . ($dryRun ? " [DRY RUN]" : ""));

$lastId = 0;
$totalMigrated = 0;
$totalBatches = 0;

do {
    $logs = $dbhr->preQuery(
        "SELECT * FROM logs WHERE id > ? AND timestamp >= ? ORDER BY id LIMIT ?",
        [$lastId, $fromDate, $batchSize]
    );

    if (empty($logs)) {
        break;
    }

    $streams = [];

    foreach ($logs as $log) {
        $lastId = $log['id'];

        // Build labels
        $labels = [
            'app' => 'iznik-server',
            'type' => $log['type'] ?? 'unknown',
            'subtype' => $log['subtype'] ?? 'unknown',
            'source' => 'migration',
        ];

        if (!empty($log['groupid'])) {
            $labels['groupid'] = (string)$log['groupid'];
        }

        // Convert timestamp to nanoseconds
        $ts = strtotime($log['timestamp']);
        $tsNano = (string)($ts * 1000000000);

        // Build log line as JSON
        $logLine = json_encode([
            'id' => $log['id'],
            'user' => $log['user'],
            'byuser' => $log['byuser'],
            'msgid' => $log['msgid'],
            'groupid' => $log['groupid'],
            'text' => $log['text'],
            'configid' => $log['configid'],
            'stdmsgid' => $log['stdmsgid'],
            'bulkopid' => $log['bulkopid'],
        ]);

        // Group by label set
        $labelKey = json_encode($labels);
        if (!isset($streams[$labelKey])) {
            $streams[$labelKey] = [
                'stream' => $labels,
                'values' => [],
            ];
        }
        $streams[$labelKey]['values'][] = [$tsNano, $logLine];
    }

    if (!$dryRun && !empty($streams)) {
        $payload = ['streams' => array_values($streams)];

        $ch = curl_init($lokiUrl . '/loki/api/v1/push');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 204) {
            error_log("ERROR: Loki returned HTTP $httpCode: $response");
            sleep(5); // Back off on error
        }
    }

    $totalMigrated += count($logs);
    $totalBatches++;

    if ($totalBatches % 10 === 0) {
        error_log("Progress: $totalMigrated logs migrated (last id: $lastId)");
    }

    // Rate limit to avoid overwhelming Loki
    usleep(100000); // 100ms between batches

} while (count($logs) === $batchSize);

error_log("Migration complete: $totalMigrated logs in $totalBatches batches");
```

#### 1.4 Start and Test

```bash
# Start Loki and Grafana
docker-compose up -d loki grafana

# Check Loki is running
curl http://localhost:3100/ready

# Run migration (dry run first)
docker exec freegle-app php /var/www/iznik/scripts/migrate/migrate_logs_to_loki.php --dry-run

# Run actual migration
LOKI_URL=http://loki:3100 docker exec freegle-app php /var/www/iznik/scripts/migrate/migrate_logs_to_loki.php

# Access Grafana at http://localhost:3200
# Add Loki data source: http://loki:3100
```

### Phase 2: Near Real-Time Sync from DB

Rather than modifying `Log::log()` immediately, use a cron-based sync that reads new logs from the DB and pushes to Loki.

#### 2.1 Sync Script

Create `iznik-server/scripts/cron/sync_logs_to_loki.php`:

```php
<?php
/**
 * Sync new logs from MySQL to Loki.
 * Runs every minute via cron.
 * Tracks last synced ID in a state file.
 */
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

// Configuration
$lokiUrl = getenv('LOKI_URL') ?: 'http://localhost:3100';
$stateFile = '/tmp/loki_sync_last_id.txt';
$batchSize = 500;
$maxBatches = 10; // Max batches per run to avoid long-running script

// Get last synced ID
$lastId = 0;
if (file_exists($stateFile)) {
    $lastId = (int)trim(file_get_contents($stateFile));
}

error_log("Loki sync starting from ID $lastId");

$totalSynced = 0;
$batchCount = 0;

do {
    $logs = $dbhr->preQuery(
        "SELECT * FROM logs WHERE id > ? ORDER BY id LIMIT ?",
        [$lastId, $batchSize]
    );

    if (empty($logs)) {
        break;
    }

    $streams = [];
    $maxId = $lastId;

    foreach ($logs as $log) {
        $maxId = max($maxId, $log['id']);

        $labels = [
            'app' => 'iznik-server',
            'type' => $log['type'] ?? 'unknown',
            'subtype' => $log['subtype'] ?? 'unknown',
        ];

        if (!empty($log['groupid'])) {
            $labels['groupid'] = (string)$log['groupid'];
        }

        $ts = strtotime($log['timestamp']);
        $tsNano = (string)($ts * 1000000000);

        $logLine = json_encode([
            'id' => $log['id'],
            'user' => $log['user'],
            'byuser' => $log['byuser'],
            'msgid' => $log['msgid'],
            'groupid' => $log['groupid'],
            'text' => $log['text'],
        ]);

        $labelKey = json_encode($labels);
        if (!isset($streams[$labelKey])) {
            $streams[$labelKey] = [
                'stream' => $labels,
                'values' => [],
            ];
        }
        $streams[$labelKey]['values'][] = [$tsNano, $logLine];
    }

    if (!empty($streams)) {
        $payload = ['streams' => array_values($streams)];

        $ch = curl_init($lokiUrl . '/loki/api/v1/push');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 204) {
            // Success - update state
            file_put_contents($stateFile, $maxId);
            $lastId = $maxId;
            $totalSynced += count($logs);
        } else {
            error_log("ERROR: Loki returned HTTP $httpCode: $response");
            break; // Stop on error, will retry next run
        }
    }

    $batchCount++;

} while (count($logs) === $batchSize && $batchCount < $maxBatches);

if ($totalSynced > 0) {
    error_log("Loki sync complete: $totalSynced logs synced, last ID: $lastId");
}

Utils::unlockScript($lockh);
```

#### 2.2 Cron Entry

Add to crontab:

```bash
# Sync logs to Loki every minute
* * * * * cd /var/www/iznik && php scripts/cron/sync_logs_to_loki.php >> /var/log/loki_sync.log 2>&1
```

### Phase 3: Google Cloud Backup Integration

#### 3.1 Backup Script

Create `scripts/backup/backup_loki.sh`:

```bash
#!/bin/bash
#
# Backup Loki data to Google Cloud Storage.
# Integrates with existing DB backup workflow.
#
# Usage: ./backup_loki.sh [bucket-name]

set -e

BUCKET=${1:-"freegle-backups"}
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="loki_backup_${TIMESTAMP}.tar.gz"
LOKI_CONTAINER="freegle-loki"
TEMP_DIR="/tmp/loki_backup_$$"

echo "Starting Loki backup at $(date)"

# Create temp directory
mkdir -p "$TEMP_DIR"

# Method 1: If Loki is running in Docker with a named volume
echo "Extracting Loki data from Docker volume..."
docker run --rm \
    -v loki-data:/loki:ro \
    -v "$TEMP_DIR":/backup \
    alpine tar czf /backup/loki_data.tar.gz -C /loki .

# Upload to Google Cloud Storage
echo "Uploading to gs://${BUCKET}/loki/${BACKUP_NAME}..."
gsutil cp "$TEMP_DIR/loki_data.tar.gz" "gs://${BUCKET}/loki/${BACKUP_NAME}"

# Keep only last 7 days of backups in GCS
echo "Cleaning up old backups..."
gsutil ls "gs://${BUCKET}/loki/" | sort | head -n -7 | xargs -r gsutil rm

# Cleanup
rm -rf "$TEMP_DIR"

echo "Loki backup complete: gs://${BUCKET}/loki/${BACKUP_NAME}"
```

#### 3.2 Restore Script

Create `scripts/backup/restore_loki.sh`:

```bash
#!/bin/bash
#
# Restore Loki data from Google Cloud Storage.
# For use on Yesterday system or disaster recovery.
#
# Usage: ./restore_loki.sh [bucket-name] [backup-name]
#        ./restore_loki.sh freegle-backups loki_backup_20251210_120000.tar.gz
#        ./restore_loki.sh freegle-backups latest

set -e

BUCKET=${1:-"freegle-backups"}
BACKUP_NAME=${2:-"latest"}
LOKI_CONTAINER="freegle-loki"
TEMP_DIR="/tmp/loki_restore_$$"

echo "Starting Loki restore at $(date)"

# Create temp directory
mkdir -p "$TEMP_DIR"

# Determine backup file
if [ "$BACKUP_NAME" == "latest" ]; then
    echo "Finding latest backup..."
    BACKUP_FILE=$(gsutil ls "gs://${BUCKET}/loki/" | sort | tail -1)
    echo "Using: $BACKUP_FILE"
else
    BACKUP_FILE="gs://${BUCKET}/loki/${BACKUP_NAME}"
fi

# Download backup
echo "Downloading backup..."
gsutil cp "$BACKUP_FILE" "$TEMP_DIR/loki_data.tar.gz"

# Stop Loki
echo "Stopping Loki..."
docker stop "$LOKI_CONTAINER" 2>/dev/null || true

# Clear existing data and restore
echo "Restoring data to Docker volume..."
docker run --rm \
    -v loki-data:/loki \
    -v "$TEMP_DIR":/backup \
    alpine sh -c "rm -rf /loki/* && tar xzf /backup/loki_data.tar.gz -C /loki"

# Start Loki
echo "Starting Loki..."
docker start "$LOKI_CONTAINER"

# Wait for ready
echo "Waiting for Loki to be ready..."
for i in {1..30}; do
    if curl -s http://localhost:3100/ready > /dev/null; then
        echo "Loki is ready"
        break
    fi
    sleep 1
done

# Cleanup
rm -rf "$TEMP_DIR"

echo "Loki restore complete"
```

#### 3.3 Integration with Existing Backup Cron

Update existing backup script to include Loki:

```bash
# In existing backup cron or script, add:
/path/to/scripts/backup/backup_loki.sh freegle-backups
```

#### 3.4 Yesterday System Restore Procedure

Add to `CLAUDE.md` or create `docs/yesterday-restore.md`:

```markdown
## Restoring Yesterday System with Loki

After restoring the database:

1. Restore Loki data:
   ```bash
   ./scripts/backup/restore_loki.sh freegle-backups latest
   ```

2. Verify Loki is working:
   ```bash
   curl http://localhost:3100/ready
   curl 'http://localhost:3100/loki/api/v1/query?query={app="iznik-server"}&limit=10'
   ```

3. Access Grafana at http://localhost:3200
   - Default credentials: admin / changeme
   - Loki data source should auto-connect
```

### Phase 4: Production Cutover

Once sync is stable and backup/restore is tested:

#### 4.1 Update purge_logs.php

Reduce DB retention since Loki is now authoritative:

```php
// In purge_logs.php, change retention to 7 days for all log types
// Loki handles the longer retention based on its config
$start = date('Y-m-d', strtotime("7 days ago"));
```

#### 4.2 Optional: Direct Loki Writes

If desired, modify `Log::log()` to write directly to Loki instead of via sync:

```php
// In Log::log() - add alongside or instead of DB insert
$this->sendToLoki([
    'type' => $params['type'],
    'subtype' => $params['subtype'],
    'user' => $params['user'] ?? null,
    'byuser' => $params['byuser'] ?? null,
    'msgid' => $params['msgid'] ?? null,
    'groupid' => $params['groupid'] ?? null,
    'text' => $params['text'] ?? null,
]);
```

### Grafana Dashboard Setup

#### Example Queries

```logql
# All logs for a specific user
{app="iznik-server"} |= "\"user\":12345"

# Login activity
{app="iznik-server", subtype="Login"}

# Errors in last hour
{app="iznik-server"} |~ "error|failed|exception"

# Activity for a specific group
{app="iznik-server", groupid="789"}

# Message approvals
{app="iznik-server", type="Message", subtype="Approved"}
```

#### Dashboard JSON

Create a basic dashboard in Grafana with panels for:
- Recent errors (last 24h)
- Login/logout activity
- Message activity by type
- Logs by group

### Volume & Cost Summary

Based on production data (Dec 2025):

| Metric | Value |
|--------|-------|
| Current logs in DB | 37 million |
| Daily new logs | ~14,000 |
| Historical data size | ~3.2 GB |
| Monthly new data | ~0.04 GB |
| Storage after 1 year | ~3.7 GB |

**Cost: $0** (self-hosted)

Minimal infrastructure required - Loki can run alongside existing services.

---

## Log Level Guidelines for Support Staff

### INFO (visible to support staff)
Write messages that make sense to someone who knows the system but not the code:
- "Sent digest with 12 offers to user 'jane@example.com' for FreegleEdinburgh"
- "Processed 25 expired messages, sent 18 expiry notifications"
- "Skipped user 'bob@example.com' - email bounced 3 times"

### ERROR (visible to support staff + needs attention)
- "Failed to send digest to 'user@example.com': Connection refused"
- "Cannot process chat room 1234: Room has been deleted"

### DEBUG (developers only, not in Loki)
- "Query returned 47 rows in 0.003s"
- "Checking eligibility for user 12345"
- "Message 678 has fromuser=null, skipping"

---

## Implementation Priority

### Immediate (Phase 1)
1. Install dmcbrn/laravel-email-database-log
2. Add 7-day purge job
3. Review existing Log:: calls and adjust levels/messages for support staff

### Short-term (Phase 2)
1. Set up Loki + Grafana for searchable INFO/ERROR logs
2. Create simple Grafana dashboard showing recent errors and job summaries

### Future (Phase 3)
1. Evaluate Dittofeed for visual flow design
2. Consider migrating some cron-triggered emails to event-triggered via Dittofeed
3. Design re-engagement campaigns visually

---

## Sources

### Logging
- [Grafana Loki OSS](https://grafana.com/oss/loki/)
- [Laravel Loki Logging Package](https://packagist.org/packages/alexmacarthur/laravel-loki-logging)
- [Grafana Cloud Pricing](https://grafana.com/pricing/) - 50GB free tier

### Email Archiving
- [Piler (mailpiler)](https://www.mailpiler.org/) - Open source Postfix email archiver (recommended)
- [Piler Documentation](https://docs.mailpiler.com/) - Setup and Postfix integration
- [Piler Docker](https://github.com/woa7/docker-piler) - Docker deployment
- [wnx/laravel-sends](https://github.com/stefanzweifel/laravel-sends) - Laravel-only, very active, 316k downloads

### Email Flow/Automation
- [Dittofeed](https://www.dittofeed.com) - [GitHub](https://github.com/dittofeed/dittofeed) - Visual journey builder, free self-host
- [Laudspeaker](https://github.com/laudspeaker/laudspeaker) - Similar, more onboarding focused
- [Open Source Marketing Automation Tools](https://blog.n8n.io/open-source-marketing-automation-tools/)

### Retention & User Journeys
- [Dittofeed Documentation](https://docs.dittofeed.com/) - Segments, journeys, and event tracking
- [Dittofeed Self-Hosting Guide](https://docs.dittofeed.com/deployment/self-hosted) - Docker deployment
- [Customer.io Journey Builder](https://customer.io/docs/journeys/) - Commercial alternative with similar concepts (for reference)

---

## Part 7: User Activity Timeline (Support Tools UI)

This section addresses a different need from Loki: presenting the existing `logs` table data in a **user-friendly timeline UI** for support staff investigating user issues.

### The Problem

The current ModTools log viewer (Support Tools) has several usability issues:

**Current Format Issues:**
- Repetitive user info on every row (redundant when viewing a specific user's logs)
- Internal IDs exposed (#35909200, #44182860) - meaningless to support staff
- Dense run-on text with technical jargon ("using Approve and Unmod")
- No visual hierarchy - all events have same visual weight
- No grouping by time period
- Hard to scan quickly for relevant events

### Target Audience

1. **Primary:** Support/Admin staff investigating user issues ("User X reported a problem - what happened?")
2. **Secondary:** Moderators looking at member activity

### Proposed UI Design

**Grouped timeline with collapsible sections:**

```
▼ Today (3 events)
  ✓ Approved chat message for offr                           06:41

▼ Yesterday (8 events)
  ✓ Approved "Extendable dining table & 6 chairs" on HuddersfieldFreegle   14:30
  ⚙ Changed posting status on HuddersfieldFreegle            14:30
  ✓ Approved "Bosch power washer" on EdinburghFreegle        14:30
  ...

▸ Last 7 days (23 events)
▸ Last 30 days (87 events)
▸ Older (142 events) - collapsed by default
```

**Key improvements:**
- Human-readable sentences (no raw JSON, no internal IDs)
- Visual icons for event types (✓ approval, ⚙ settings, 🚪 join/leave, etc.)
- Clickable links to messages/groups/users
- Time grouping with older events collapsed by default
- No redundant user info (already viewing that user's logs)

### Implementation Options

#### Option A: Nuxt UI Timeline + Custom Grouping (Recommended)

[Nuxt UI Timeline](https://ui.nuxt.com/docs/components/timeline) - Native to existing stack.

**Pros:**
- Already using Nuxt in iznik-nuxt3-modtools
- Minimal dependencies
- Full control over simplicity

**Cons:**
- Need to build grouping/collapsing logic ourselves
- Need to build human-readable message transformation

#### Option B: PrimeVue Timeline

[PrimeVue Timeline](https://primevue.org/timeline/) - Popular component library.

**Pros:**
- More built-in features
- Good documentation
- Active community

**Cons:**
- Additional dependency
- Still need custom grouping

#### Option C: Full Audit Log Service (Retraced)

[Retraced](https://github.com/retracedhq/retraced) - Full audit log service with embeddable UI.

**Pros:**
- Ready-made embeddable UI
- Searchable, exportable

**Cons:**
- Adds Elasticsearch dependency
- Separate service to maintain
- Probably overkill for this use case

### Recommended Approach: Option A

Use Nuxt UI Timeline with custom components for:

1. **API endpoint** - Fetches logs for a user, grouped by time period
2. **Human-readable transformer** - Converts `{type: 'User', subtype: 'Joined', groupid: 123}` into "Joined Barnet Freegle group"
3. **Collapsible time groups** - Today, Yesterday, Last 7 days, Last 30 days, Older
4. **Clickable entity links** - Messages, groups, users open in new tab

### Human-Readable Message Mapping

Transform type/subtype combinations into sentences:

| Type | Subtype | Template |
|------|---------|----------|
| User | Joined | "Joined {group}" |
| User | Left | "Left {group}" |
| User | Login | "Logged in via {method}" |
| User | Bounce | "Email bounced: {email}" |
| Message | Approved | "Approved '{subject}' on {group}" |
| Message | Rejected | "Rejected '{subject}' on {group}" |
| Message | Created | "Posted '{subject}' on {group}" |
| Message | Outcome | "Marked '{subject}' as {outcome}" |
| Chat | Created | "Started chat about '{subject}'" |
| Chat | Replied | "Replied in chat about '{subject}'" |

### Filtering Capabilities

Simple filters (not complex query language):

- **Event type:** All / Messages / Groups / Logins / Chats
- **Time range:** All time / Last 7 days / Last 30 days / Last year
- **Search:** Free text search in message subjects/group names

### Location in ModTools

Add as new tab/section in **Support Tools** user view:
- Existing: Summary, Groups, Messages, etc.
- New: **Activity Timeline**

### Implementation Steps

1. Create API endpoint `/api/user/{id}/activity-timeline`
2. Build human-readable message transformer service
3. Create Vue component with Nuxt UI Timeline
4. Add collapsible time grouping
5. Wire up entity links (messages, groups)
6. Add to Support Tools user view

### Relationship to Loki

This UI is **independent of Loki migration**:
- Currently reads from MySQL `logs` table
- After Loki migration, could read from Loki instead
- The UI layer and human-readable transformation remain the same
- Loki would provide better search/retention, but the UI problem is separate

### Sources

- [Nuxt UI Timeline](https://ui.nuxt.com/docs/components/timeline)
- [PrimeVue Timeline](https://primevue.org/timeline/)
- [Retraced Audit Log](https://github.com/retracedhq/retraced)
- [simple-vue-timeline](https://scottie34.github.io/simple-vue-timeline/)
