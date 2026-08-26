# Logging Configuration

## Overview

Freegle uses Grafana Loki for centralised log aggregation. Logs come from three places:

- **iznik-server-go** (the apiv2 Go API) - request logs, client-side logs relayed from the
  browser, and rows mirrored from the `logs` table.
- **iznik-batch** (Laravel) - batch jobs, outbound and incoming mail.
- **Container stdout/stderr** on the edge host, shipped by Grafana Alloy.

All application logging is fire-and-forget: a logging failure must never fail or slow the
request that produced it.

> The V1 PHP API (`iznik-server`) also logged here. It was **removed from the repo on
> 2026-07-09**, so `api_version="v1"` lines only exist in historical data and nothing emits
> them now.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         Application Servers                                  │
│   Go API (apiv2)  │  Laravel Batch  │  Browser (via apiv2)  │  Containers   │
└────────┬────────┴───────┬───────┴────────┬────────┴───────────┬─────────────┘
         │                │                │                     │
         ▼                ▼                ▼                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Docker: Direct to Loki    │    Live: JSON files → Alloy → Loki            │
└─────────────────────────────────────────────────────────────────────────────┘
         │                                          │
         ▼                                          ▼
┌─────────────────────────┐            ┌─────────────────────────┐
│        MySQL            │            │         Loki            │
│   (Source of Truth)     │            │   (Primary for API)     │
│   - logs table          │            │   - 7-day API retention │
│                         │            │   - Grafana dashboards  │
└─────────────────────────┘            └─────────────────────────┘
```

**Current Status:** MySQL and Loki run in parallel. MySQL remains source of truth until all read dependencies are migrated.

## What we log

Everything carries `app="freegle"` and a `source` label saying which pipeline produced it.
`source` is the first thing to filter on, and the only reliable way to know what fields a line
will have.

| `source` | Emitted by | What it is |
|---|---|---|
| `api` | apiv2 | One line per request: endpoint, method, status, duration, `user_id`, `session_id` |
| `api_headers` | apiv2 | Request/response headers for the same request, split out because they are bulky |
| `client` | Browser, relayed via `POST /clientlog` | Browser-side events and errors. **Carries `session_id` but no `user_id`** |
| `logs_table` | apiv2 | Rows mirrored from the MySQL `logs` table - carries `type` and `subtype` |
| `email` | Laravel | Outbound mail: recipient, type, spool outcome |
| `incoming_mail` | Laravel | Inbound mail routing decisions |
| `bounce` | Laravel | Bounce processing |
| `batch` | Laravel | Scheduled/queue job start, finish, failure |
| `batch_event` | Laravel | Notable events inside a job, where the job itself is too coarse |
| `chat_reply` | apiv2 | Chat reply creation, for the "my reply vanished" class of support case |
| `deprecated_endpoint` | apiv2 | Calls to endpoints marked for removal, so we can see who still uses them |
| `search`, `similar_posts`, `vector_search` | apiv2 | Search and recommendation serving |

### Which lines identify a user

This matters more than it looks: `user_id` is a **JSON field in the body**, not a label, and
not every source has one.

- `api`, `api_headers`, `email`, `incoming_mail`, `chat_reply`, `vector_search` - `user_id` in
  the JSON body (0 means anonymous).
- `client` - **`session_id` only**. To get a member's browser activity you have to harvest
  their session ids from `api` lines first and query by those.
- `batch`, `batch_event` - system level, no user.

`iznik-server-go/userdump/loki.go` implements the three-pass version of this (by `user_id`,
then full-text by email address, then by harvested `session_id`) for subject access requests.

## Retention

Set per stream in `conf/loki-config.yaml`, and deliberately mirrors the retention the old
`purge_logs.php` applied to the database.

| Stream | Retention |
|---|---|
| default (anything not listed) | 31 days |
| `{source="api"}`, `{source="api_headers"}`, `{source="client"}` | 7 days |
| `{subtype="Login"}`, `{subtype="Logout"}` | 365 days |
| `{subtype="Bounce"}` | 90 days |
| `{subtype="Created"}`, `{subtype="Deleted"}` | 31 days |
| `{source="email"}`, `{source="batch"}`, `{source="batch_event"}` | 31 days |
| `{type="Plugin"}` | 1 day (high volume, low value) |

**Old data is simply not there.** A support case about something three weeks ago will find
nothing in `api` or `client`, and that is expected rather than a fault. `reject_old_samples`
is off so historical backfill is accepted.

## How it gets there

Three different paths, which is worth knowing because they fail differently.

### 1. Applications, in Docker: straight to Loki

`LOKI_ENABLED=true` and `LOKI_URL=http://loki:3100` (set in `docker-compose.yml`). Apps POST to
`/loki/api/v1/push` themselves. Simplest path; if Loki is down the log is dropped and the
request carries on.

### 2. Applications, on live servers: JSON files, then Alloy

Apps write newline-delimited JSON to `/var/log/freegle` (`freegle.loki.log_path`), and Grafana
Alloy tails those files and ships them. See `iznik-batch/app/Services/LokiService.php` for the
writing side: each entry is `{timestamp, labels{}, message{}}`, where `labels` become Loki
labels and `message` becomes the JSON body.

This exists because a file survives Loki being down or slow, where a direct push does not.
Logrotate keeps the files bounded (step 6 below).

### 3. Container output on the edge host: Alloy via the Docker socket

`alloy-edge.alloy`, run under the `edge` compose profile. It discovers containers on the Docker
socket and ships stdout/stderr for the user-facing ones only - `frontend-nginx`, `tusd`,
`delivery`, `tile-server`, `wiki-media`, `wiki-mysql` - labelled **`app="edge"`**,
`container=<name>`, `stream=stdout|stderr`.

Note the different `app` label: `{app="freegle"}` finds application logs, `{app="edge"}` finds
container logs, and a query for one will never show the other. This pipeline was added after an
upload failure went unnoticed for want of tusd's own output (2026-07-09).

## Querying: the API calls

### The Loki HTTP API

| Endpoint | Used for |
|---|---|
| `POST /loki/api/v1/push` | Ingestion (apps in Docker, and Alloy everywhere) |
| `GET /loki/api/v1/query_range` | Everything that reads a time window - the normal case |
| `GET /loki/api/v1/query` | Instant queries, used for the counts endpoint |
| `GET /loki/api/v1/labels`, `/label/<name>/values` | Discovering what labels exist |

Local Docker: `http://localhost:3100`. Production is reached over an SSH tunnel the developer
runs from the Windows host; from WSL that is **the default-gateway IP on port 3102**, not
localhost.

```bash
GW=$(ip route | awk '/^default/{print $3; exit}')

curl -s -G "http://$GW:3102/loki/api/v1/query_range" \
  --data-urlencode 'query={app="freegle", source="api"} | json | user_id="12345"' \
  --data-urlencode "start=$(date -u -d '2026-08-01 09:00:00' +%s)000000000" \
  --data-urlencode "end=$(date -u -d '2026-08-01 10:00:00' +%s)000000000" \
  --data-urlencode 'limit=300' \
  --data-urlencode 'direction=backward'
```

Four things that catch people out every time:

- **Timestamps are nanoseconds.** Seconds-since-epoch silently returns nothing rather than
  erroring, so `+%s` needs `000000000` after it.
- **Use `-G` with `--data-urlencode`.** A LogQL query put straight in the URL will be mangled by
  the braces, quotes and pipes.
- **Label values must be quoted** inside the selector: `{source="api"}`, never `{source=api}`.
- **The label endpoints default to a one-hour lookback.** Asking what values exist without
  passing `start`/`end` will suggest a label is unused when it simply had no traffic in the
  last hour.

### Finding one user's logs

This changed on **2026-08-23** and the two eras look different, so read this before
writing a query against `user_id`.

**What was wrong.** On the production database nodes `user_id` was promoted to a real Loki
**stream label** - despite this page previously saying it was not. One stream per user is the
pattern Loki's own guidance warns against, and it did exactly what you would expect: 11,565
distinct values in 24 hours against Loki's default ceiling of 5,000 active streams, with the
overflow **discarded silently** - 535,859 entries in two days. Anything built on those logs,
the subject-access dump included, was quietly incomplete.

**Why it was not simply moved to a JSON field.** Structured metadata and JSON fields are not
indexed. Measured over a one-hour window, `{app="freegle"} | user_id="x"` reads 115MB and
138,594 lines where the label lookup reads 324KB and 239 - roughly **356x the work**. The dump
queries a 30-day window under a timeout, so that trade was not available either.

**What it is now.** Both, deliberately:

- **`user_bucket`** is a stream label holding `user_id % 32` - coarse, so it is 32 values
  instead of tens of thousands, and it keeps the query index-narrowed.
- **`user_id`** is **structured metadata** - attached to each entry, exact, and creating no
  streams. Match it with a `| user_id="..."` filter, not inside `{}`.

The bucket count lives in `misc.UserBucket` in `iznik-server-go`, and **every reader recomputes
it**. Change it there and the JS support tools must change with it, or a user's logs go
missing without an error.

```logql
# Entries written from 2026-08-23 onwards
{app="freegle", user_bucket="25"} | user_id="12345"      # 12345 % 32 = 25

# Entries written before then - still inside retention until late September 2026
{app="freegle", user_id="12345"}

# Anonymous / pre-login traffic has no user in either era
{app="freegle", source="client"} | user_id=""
```

Until nothing older than 2026-08-23 is left in retention, **a complete answer needs both
forms merged**. That is what `iznik-server-go/userdump/loki.go` and the support tools in
`claude-agent-sdk/` do; they are disjoint - a stream selector cannot match structured
metadata - so there is nothing to de-duplicate.

Put the `| user_id="..."` filter **before** any `| json` stage. `| json` also pulls a
`user_id` out of the line body, and the parsed one is renamed rather than replacing the
structured-metadata value.

`session_id` remains a JSON field on every source: filter it after `| json`.

`trace_id` is the awkward one: it is a **JSON field on most sources but a real label on
`source="email"`**, which is the only source that promotes it. So
`{app="freegle", trace_id="..."}` finds the mail lines for a trace and silently misses the API
and client lines; `{app="freegle"} | json | trace_id="..."` finds them all. Prefer the second
form unless you specifically want the mail.

That promotion is also a cardinality smell worth keeping an eye on - one stream per trace is
the pattern Loki's own guidance warns against. It is small today (8 series in 24h on a dev
instance); if email volume makes it a problem, the fix is to demote it to a JSON field like
everywhere else.

### The ModTools API

Moderators do not query Loki directly. `iznik-server-go/systemlogs/systemlogs.go` wraps it:

| Route | Returns |
|---|---|
| `GET /api/modtools/systemlogs` | Log entries, filtered and permission-checked |
| `GET /api/modtools/systemlogs/counts` | Counts by source, for the filter UI |

Both sit behind `RequireModeratorMiddleware`, and the handler additionally checks that the
moderator may see the specific user or group asked about - a moderator can only see logs for
users in their own groups. `buildLogQLQuery` assembles the LogQL, putting `source`, `type`,
`subtype` and `level` in the label selector and everything else after `| json`.

`POST /api/clientlog` is the ingestion side of `source="client"`: the browser posts batches of
events, and apiv2 relays them with `source=client` plus an `event_type` label.

---

<details>
<summary><strong>Docker Development Setup</strong></summary>

### Accessing Logs

- **Loki API**: http://localhost:3100
- Query logs via curl: `curl -G "http://localhost:3100/loki/api/v1/query" --data-urlencode 'query={app="freegle"}'`

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `LOKI_ENABLED` | `false` | Enable/disable Loki logging |
| `LOKI_URL` | `http://loki:3100` | Loki server URL |

In Docker, `LOKI_ENABLED=true` is set in docker-compose.yml. Apps write directly to the Loki container.

### Configuration

Loki config: `conf/loki-config.yaml`

Key settings:
- Default retention: 31 days
- Stream-specific retention per log category
- Compaction runs every 10 minutes

</details>

---

<details>
<summary><strong>Live Server Setup</strong></summary>

On live servers, apps write JSON logs to local files, and Grafana Alloy ships them to Loki.

### Step 1: Install Grafana Alloy

```bash
cd /tmp
curl -LO https://github.com/grafana/alloy/releases/download/v1.4.2/alloy-linux-amd64.zip
unzip alloy-linux-amd64.zip
sudo mv alloy-linux-amd64 /usr/local/bin/alloy
sudo chmod +x /usr/local/bin/alloy
sudo mkdir -p /etc/alloy
```

### Step 2: Create Alloy Configuration

Create `/etc/alloy/config.alloy`:

```hcl
// Discovery for local JSON log files
local.file_match "freegle_logs" {
  path_targets = [{
    __path__ = "/var/log/freegle/*.log",
  }]
}

// JSON log file source
loki.source.file "freegle_json_logs" {
  targets    = local.file_match.freegle_logs.targets
  forward_to = [loki.process.freegle_process.receiver]
  tail_from_end = true
}

// Process logs - extract JSON and add labels
loki.process "freegle_process" {
  forward_to = [loki.write.loki_remote.receiver]

  stage.json {
    expressions = {
      timestamp = "timestamp",
      labels    = "labels",
      message   = "message",
    }
  }

  stage.json {
    source     = "labels"
    expressions = {
      app        = "app",
      source     = "source",
      level      = "level",
      event_type = "event_type",
      api_version = "api_version",
      method     = "method",
      status_code = "status_code",
      type       = "type",
      subtype    = "subtype",
      job_name   = "job_name",
      email_type = "email_type",
      groupid    = "groupid",
    }
  }

  // CHANGE THIS for each server
  stage.static_labels {
    values = {
      hostname = "live1.ilovefreegle.org",
    }
  }

  stage.labels {
    values = {
      app = "app", source = "source", level = "level",
      event_type = "event_type", api_version = "api_version",
      method = "method", status_code = "status_code",
      type = "type", subtype = "subtype",
      job_name = "job_name", email_type = "email_type",
      groupid = "groupid",
    }
  }

  stage.timestamp {
    source = "timestamp"
    format = "RFC3339"
  }

  stage.output {
    source = "message"
  }
}

// Write to remote Loki server
loki.write "loki_remote" {
  endpoint {
    url = "http://docker:3100/loki/api/v1/push"
  }
}
```

### Step 3: Create Systemd Service

Create `/etc/systemd/system/alloy.service`:

```ini
[Unit]
Description=Grafana Alloy
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/alloy run /etc/alloy/config.alloy
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Start Alloy:

```bash
sudo systemctl daemon-reload
sudo systemctl enable alloy
sudo systemctl start alloy
```

### Step 4: Create Log Directory

```bash
sudo mkdir -p /var/log/freegle
sudo chown www-data:www-data /var/log/freegle
sudo chmod 755 /var/log/freegle
```

### Step 5: Configure Applications

**iznik-batch (Laravel)** - Set environment variables:
```bash
LOKI_ENABLED=true
LOKI_JSON_FILE=true
LOKI_JSON_PATH=/var/log/freegle
```

**iznik-server-go (Go)** - Set environment variables:
```bash
LOKI_ENABLED=true
LOKI_URL=http://docker:3100
```

### Step 6: Configure Logrotate

Create `/etc/logrotate.d/freegle-loki`:

```
/var/log/freegle/*.log {
    daily
    rotate 7
    missingok
    notifempty
    compress
    delaycompress
    create 0644 www-data www-data
    copytruncate
    postrotate
        find /var/log/freegle -name "*.gz" -mtime +14 -delete 2>/dev/null || true
    endscript
}
```

### Verification

```bash
# Check Loki is reachable
curl -s http://docker:3100/ready

# Check Alloy is running
sudo systemctl status alloy

# View Alloy logs for errors
sudo journalctl -u alloy -f
```

</details>

---

<details>
<summary><strong>Querying Logs</strong></summary>

### LogQL Examples

```logql
# API errors in the last hour
{source="api", status_code=~"5.."}

# Login events for a specific user
{source="logs_table", subtype="Login"} |= "user_id\":12345"

# API headers for debugging a specific endpoint
{source="api_headers"} |= "/api/message"

# All batch job logs
{app="freegle-batch"}

# High latency API calls (>1000ms)
{source="api"} | json | duration_ms > 1000

# Logs from specific server
{hostname="live1.ilovefreegle.org"}
```

### Useful Filters

- `|=` - Line contains string
- `!=` - Line does not contain string
- `|~` - Line matches regex
- `| json` - Parse JSON and enable field queries

### Log Labels

Labels are the only thing you may put in the `{}` selector, and they are deliberately
low-cardinality. Anything per-user or per-request is a JSON field instead - see above.

The live label set, as reported by `GET /loki/api/v1/labels`:

`api_version`, `app`, `email_type`, `event_type`, `filename`, `host`, `job_name`, `level`,
`method`, `service_name`, `source`, `status_code`, `subtype`, `trace_id`, `type`

**All logs:**
| Label | Description |
|-------|-------------|
| `app` | `freegle` for application logs, `edge` for shipped container output |
| `source` | Which pipeline produced it - see "What we log" |
| `host` | Container or server hostname |
| `filename` | Source log file, where the line came via Alloy tailing a file |
| `level` | `info`, `error`, ... |

**API-specific:**
| Label | Description |
|-------|-------------|
| `api_version` | `v2` (Go). `v1` appears only in historical data - the PHP API is gone |
| `method` | HTTP method |
| `status_code` | HTTP response status |
| `level` | `info` or `error` (5xx only) |

**Logs table (`source="logs_table"`):**
| Label | Description |
|-------|-------------|
| `type` | Log type (User, Group, Message, etc.) |
| `subtype` | Log subtype (Login, Logout, Created, etc.) |
| `groupid` | Group ID if applicable |

**Container logs (`app="edge"`):**
| Label | Description |
|-------|-------------|
| `container` | Container name, e.g. `freegledocker-tusd` |
| `stream` | `stdout` or `stderr` |

</details>

---

<details>
<summary><strong>Log Viewer (ModTools)</strong></summary>

### User Roles & Perspectives

| Perspective | Who Can Access | What They See |
|-------------|----------------|---------------|
| **User** | All moderators | Logs for users in their groups |
| **Group** | All moderators | Logs for groups they moderate |
| **System** | Support/Admin only | API requests, errors, system events |

### API Endpoint

`GET /api/lokilogs`

**Parameters:**
- `perspective` - user | group | system
- `userid` - User ID (for user perspective)
- `groupid` - Group ID (for group perspective)
- `types[]` - Filter by log types
- `start`, `end` - Time range (ISO format)
- `limit`, `context` - Pagination

### Integration Points

1. **Support Tools → Find User** - "Activity Logs" button shows user's Loki logs
2. **Support Tools → System Logs** - Admin/Support can view API metrics and errors
3. **Group Settings** - Moderators can view group activity logs

### Icons

| Icon | Meaning |
|------|---------|
| Login/Logout | Authentication |
| Message posted | New post |
| Approved | Message or member approved |
| Rejected/Deleted | Content removed |
| Warning/Flagged | Needs attention |
| Chat activity | Conversation |
| Member activity | Join/leave |
| Email event | Bounce, send |
| Performance warning | Slow request |
| Error | Failed operation |

</details>

---

<details>
<summary><strong>Client-Side Tracing</strong></summary>

### Trace and Session IDs

| ID | Scope | Generated When | Purpose |
|----|-------|----------------|---------|
| `session_id` | Browser session | Page load | Group all activity in one browser session |
| `trace_id` | User interaction | Route change, modal open | Group related actions into one trace |

### HTTP Headers

All API requests include:
| Header | Description |
|--------|-------------|
| `X-Trace-ID` | Current trace UUID |
| `X-Session-ID` | Browser session UUID |
| `X-Client-Timestamp` | ISO timestamp |

### Sentry Integration

Sentry events are tagged with `trace_id` and `session_id` for correlation:
- See `trace_id` tag on any error
- Query Loki: `{app="freegle"} | json | trace_id="<value>"`
- See timeline of client actions + API calls leading to error

### Querying Traces

```logql
# All logs for a specific trace
{app="freegle"} | json | trace_id="a1b2c3d4-..."

# All traces for a user session
{app="freegle"} | json | session_id="11111111-..."

# Client-side errors with their traces
{source="client"} | json | event="error"
```

</details>

---

<details>
<summary><strong>Backup and Restore</strong></summary>

### GCS Storage (Production)

Production Loki stores data in Google Cloud Storage:
- **Bucket**: `gs://freegle-loki/`
- **Location**: `europe-west2` (London)
- **Object versioning**: Enabled

### Backup Strategy

1. **Cross-Region Replication**: `gcloud storage replication set gs://freegle-loki gs://freegle-loki-backup-us`
2. **Daily Snapshots**: `gcloud storage rsync -r gs://freegle-loki/ gs://freegle-backups/loki/$DATE/`

Retention: 7 days daily, 4 weeks weekly, 12 months monthly.

### Yesterday Restore

**Option A: Point at same GCS bucket (read-only)**

Configure yesterday's Loki to read from production bucket with read-only settings.

**Option B: Sync to local filesystem**

```bash
gcloud storage rsync -r gs://freegle-loki/ /data/loki-restore/
```

</details>

---

<details>
<summary><strong>Troubleshooting</strong></summary>

### Logs not appearing in Grafana

1. Check Loki is running: `docker logs freegle-loki`
2. Verify LOKI_ENABLED is set
3. Test Loki connection: `curl http://localhost:3100/ready`

### High memory usage

Reduce batch size or increase flush interval in handler configurations.

### Missing old logs

Check retention configuration in `conf/loki-config.yaml`. Logs are automatically deleted after their retention period.

### Performance

All logging uses async patterns:
- **Go**: goroutines with a background flusher
- **Laravel**: writes JSON lines for Alloy to pick up, fire-and-forget
- **Batching**: 10 entries or 5 seconds before sending

(The V1 PHP API used `register_shutdown_function()` with non-blocking sockets. That code is
gone.)

</details>

---

## Implementation Status

### Phase 1: MySQL Primary (Complete)
- [x] MySQL `logs` table as source of truth
- [x] Direct Loki integration in PHP/Go (feature-flagged)

### Phase 2: Parallel Logging (Current)
- [x] Apps write to Loki (Docker: direct, Live: via Alloy)
- [x] Grafana Alloy deployed to live servers
- [x] GCS backend configured with backups
- [x] ModTools System Log viewer built
- [x] **logs_api table retired** - all reads now use Loki queries
- [ ] **DROP logs_api table from live DB** - after code is fully deployed, run: `DROP TABLE logs_api;`
- [ ] **Migrate MySQL logs table reads to Loki** (see below)

### Phase 3: Loki Primary (Future)
- [ ] Disable MySQL logging after 3+ months reliability
- [ ] Keep MySQL tables for audit compliance

### MySQL Dependencies to Migrate

Before disabling MySQL logging, the remaining read paths against the `logs` table need Loki
alternatives.

**This list needs re-deriving.** It was written against the V1 PHP classes
(`Dashboard.php`, `Spam.php`, `Log.php`, `group_stats.php`, `web_graph.php`), and V1 was
removed from the repo on 2026-07-09 - those files no longer exist. The surviving names
(`Group.php`, `User.php`, `Message.php`) are now Laravel *models*, which are not the same code
and do not necessarily make the same queries.

Rather than leave a table that reads as current, the honest statement is: work out what still
reads `logs`, `logs_emails`, `logs_events` and `logs_jobs` by grepping `iznik-batch` and
`iznik-server-go`, and record the answer here. `logs_api` is already unreferenced in code.
