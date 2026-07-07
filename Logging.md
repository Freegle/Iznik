# Logging Configuration

## Overview

Freegle uses Grafana Loki for centralised log aggregation, running in parallel with MySQL logging during the migration period. Logs are collected from:

- **iznik-server** (PHP v1 API)
- **iznik-server-go** (Go v2 API)
- **iznik-batch** (Laravel batch processing)

All logging uses fire-and-forget async patterns to avoid impacting API latency.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         Application Servers                                  │
│   PHP API (v1)  │  Go API (v2)  │  Laravel Batch  │  Other Services         │
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

## Log Categories and Retention

| Category | Source | Labels | Retention | Notes |
|----------|--------|--------|-----------|-------|
| API Requests | api | `source=api` | 7 days | Basic request info (endpoint, method, status, duration) |
| API Headers | api | `source=api_headers` | 7 days | Request/response headers for debugging |
| Login/Logout | logs_table | `subtype=Login/Logout` | 365 days | User authentication events |
| User Created | logs_table | `subtype=Created` | 31 days | New user registrations |
| User Deleted | logs_table | `subtype=Deleted` | 31 days | User account deletions |
| Bounces | logs_table | `subtype=Bounce` | 90 days | Email bounce events |
| Email Sends | email | `source=email` | 31 days | Outbound email tracking |
| Plugin | logs_table | `type=Plugin` | 1 day | Plugin activity (high volume) |
| Batch Jobs | batch | `source=batch` | 31 days | Laravel queue/scheduled jobs |
| Client Logs | client | `source=client` | 7 days | Browser-side events |

Retention times align with `purge_logs.php` policies from the database.

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

**iznik-server (PHP)** - Add to `/etc/iznik.conf`:
```php
define('LOKI_ENABLED', TRUE);
define('LOKI_JSON_FILE', TRUE);
define('LOKI_JSON_PATH', '/var/log/freegle');
```

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
# All v1 API errors in last hour
{source="api", api_version="v1", status_code=~"5.."}

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

**All logs:**
| Label | Description |
|-------|-------------|
| `app` | Application name (`freegle` or `freegle-batch`) |
| `source` | Log source (`api`, `api_headers`, `logs_table`, `email`, `batch`) |
| `hostname` | Server hostname (live only) |

**API-specific:**
| Label | Description |
|-------|-------------|
| `api_version` | `v1` (PHP) or `v2` (Go) |
| `method` | HTTP method |
| `status_code` | HTTP response status |
| `level` | `info` or `error` (5xx only) |

**Logs table:**
| Label | Description |
|-------|-------------|
| `type` | Log type (User, Group, Message, etc.) |
| `subtype` | Log subtype (Login, Logout, Created, etc.) |
| `groupid` | Group ID if applicable |

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

1. **Cross-Region Replication**: `gsutil replication set gs://freegle-loki gs://freegle-loki-backup-us`
2. **Daily Snapshots**: `gsutil -m rsync -r gs://freegle-loki/ gs://freegle-backups/loki/$DATE/`

Retention: 7 days daily, 4 weeks weekly, 12 months monthly.

### Yesterday Restore

**Option A: Point at same GCS bucket (read-only)**

Configure yesterday's Loki to read from production bucket with read-only settings.

**Option B: Sync to local filesystem**

```bash
gsutil -m rsync -r gs://freegle-loki/ /data/loki-restore/
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
- **PHP**: `register_shutdown_function()` with non-blocking sockets
- **Go**: Goroutines with background flusher
- **Laravel**: Custom Monolog handler with fire-and-forget
- **Batching**: 10 entries or 5 seconds before sending

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

Before disabling MySQL logging, these read operations need Loki alternatives:

**logs table:**
| File | Query Purpose |
|------|---------------|
| Dashboard.php | Moderator last active time |
| Group.php | Auto/manual approve counts |
| group_stats.php | Last autoapprove timestamp |
| User.php | User activity, merge logs, mod actions |
| Log.php | ModTools logs API |
| Message.php | Recent message activity |
| Spam.php | Group counts for spam detection |

**Other tables:**
| Table | Used By |
|-------|---------|
| logs_emails | User.php (email history) |
| logs_events | web_graph.php (analytics) |
| logs_jobs | Jobs.php (job tracking) |
