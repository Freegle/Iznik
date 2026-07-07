# Loki Live Server Setup Instructions

Instructions for Claude to execute on the live server to set up Grafana Loki for log aggregation.

## Prerequisites

- Docker installed and running
- Access to GCP for backups (gsutil configured)
- Existing MySQL backup scripts as reference

## Step 1: Create Directory Structure

```bash
# Create directories for Loki data and config
mkdir -p /opt/loki/data
mkdir -p /opt/loki/config
mkdir -p /var/log/freegle

# Set permissions (Loki runs as user 10001 in container)
chown -R 10001:10001 /opt/loki/data
chmod 755 /var/log/freegle
```

## Step 2: Create Loki Configuration

Create `/opt/loki/config/loki-config.yaml`:

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
    - from: 2024-01-01
      store: tsdb
      object_store: filesystem
      schema: v13
      index:
        prefix: index_
        period: 24h

ruler:
  alertmanager_url: http://localhost:9093

limits_config:
  # Allow ingestion of logs up to 30 days old (for backfill)
  reject_old_samples: true
  reject_old_samples_max_age: 720h
  # Increase ingestion limits for batch imports
  ingestion_rate_mb: 16
  ingestion_burst_size_mb: 32
  # Retention - keep logs for 90 days
  retention_period: 2160h

compactor:
  working_directory: /loki/compactor
  compaction_interval: 10m
  retention_enabled: true
  retention_delete_delay: 2h
  retention_delete_worker_count: 150
  delete_request_store: filesystem

# Stream-specific retention (optional, override global)
# Can set shorter retention for high-volume streams like api_headers
```

## Step 3: Start Loki Container

```bash
docker run -d \
  --name loki \
  --restart unless-stopped \
  -p 3100:3100 \
  -v /opt/loki/config/loki-config.yaml:/etc/loki/local-config.yaml:ro \
  -v /opt/loki/data:/loki \
  grafana/loki:3.0.0 \
  -config.file=/etc/loki/local-config.yaml
```

## Step 4: Verify Loki is Running

```bash
# Check container status
docker ps | grep loki

# Check Loki is responding
curl -s http://localhost:3100/ready

# Should return "ready"

# Check Loki metrics
curl -s http://localhost:3100/metrics | head -20
```

## Step 5: Install and Configure Grafana Alloy

Alloy ships logs from JSON files to Loki.

```bash
# Download Alloy
curl -LO https://github.com/grafana/alloy/releases/download/v1.4.2/alloy-linux-amd64.zip
unzip alloy-linux-amd64.zip
mv alloy-linux-amd64 /usr/local/bin/alloy
chmod +x /usr/local/bin/alloy

# Create config directory
mkdir -p /etc/alloy
```

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
  forward_to = [loki.write.loki_local.receiver]

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

  stage.labels {
    values = {
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

  stage.timestamp {
    source = "timestamp"
    format = "RFC3339"
  }

  stage.output {
    source = "message"
  }
}

// Write to local Loki
loki.write "loki_local" {
  endpoint {
    url = "http://localhost:3100/loki/api/v1/push"
  }
}
```

Create systemd service `/etc/systemd/system/alloy.service`:

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
systemctl daemon-reload
systemctl enable alloy
systemctl start alloy
systemctl status alloy
```

## Step 6: Configure Logrotate

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

## Step 7: Set Up GCP Backup

Create backup script `/opt/loki/backup-loki.sh`:

```bash
#!/bin/bash
# Loki backup to GCP - similar to MySQL backups

set -e

BACKUP_DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/opt/loki/backups"
GCS_BUCKET="gs://your-backup-bucket/loki"
RETENTION_DAYS=30

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Stop Loki briefly for consistent backup
docker stop loki

# Create tarball of Loki data
tar -czf "$BACKUP_DIR/loki-backup-$BACKUP_DATE.tar.gz" -C /opt/loki data

# Restart Loki
docker start loki

# Upload to GCS
gsutil cp "$BACKUP_DIR/loki-backup-$BACKUP_DATE.tar.gz" "$GCS_BUCKET/"

# Clean up local backup
rm -f "$BACKUP_DIR/loki-backup-$BACKUP_DATE.tar.gz"

# Clean up old GCS backups (keep last 30 days)
gsutil ls "$GCS_BUCKET/" | while read backup; do
    backup_date=$(echo "$backup" | grep -oP '\d{8}_\d{6}')
    if [ -n "$backup_date" ]; then
        backup_ts=$(date -d "${backup_date:0:8}" +%s 2>/dev/null || echo 0)
        cutoff_ts=$(date -d "$RETENTION_DAYS days ago" +%s)
        if [ "$backup_ts" -lt "$cutoff_ts" ]; then
            gsutil rm "$backup"
        fi
    fi
done

echo "Loki backup completed: loki-backup-$BACKUP_DATE.tar.gz"
```

Make executable and add to cron:

```bash
chmod +x /opt/loki/backup-loki.sh

# Add to crontab - run at 3 AM daily
echo "0 3 * * * /opt/loki/backup-loki.sh >> /var/log/loki-backup.log 2>&1" | crontab -
```

## Step 8: Enable Loki in Application

Set environment variables in your application containers:

```bash
# For PHP (iznik-server)
LOKI_ENABLED=true
LOKI_JSON_FILE=true
LOKI_JSON_PATH=/var/log/freegle

# For Go (iznik-server-go)
LOKI_ENABLED=true
LOKI_URL=http://localhost:3100

# For Laravel (iznik-batch)
LOKI_ENABLED=true
LOKI_JSON_FILE=true
LOKI_JSON_PATH=/var/log/freegle
```

## Step 9: Verify Everything Works

```bash
# Check Loki is receiving logs
curl -G -s "http://localhost:3100/loki/api/v1/query" \
  --data-urlencode 'query={app="freegle"}' | jq .

# Check log volume
curl -s "http://localhost:3100/loki/api/v1/label/source/values" | jq .

# Should show: api, client, batch, email, logs_table, etc.
```

## Step 10: Optional - Set Up Grafana Dashboard

If you want to view logs in Grafana:

```bash
docker run -d \
  --name grafana \
  --restart unless-stopped \
  -p 3000:3000 \
  -e GF_SECURITY_ADMIN_PASSWORD=your-secure-password \
  grafana/grafana:latest
```

Then add Loki as a data source in Grafana:
- URL: http://loki:3100 (or use host IP)
- Type: Loki

## Troubleshooting

### Loki not starting
```bash
docker logs loki
# Check for permission issues on /opt/loki/data
```

### Alloy not shipping logs
```bash
journalctl -u alloy -f
# Check /var/log/freegle/ has .log files
ls -la /var/log/freegle/
```

### Query returns no results
```bash
# Check labels are being extracted
curl -s "http://localhost:3100/loki/api/v1/labels" | jq .
```

## Restore from Backup

To restore Loki data from a GCP backup:

```bash
# Stop Loki
docker stop loki

# Download backup
gsutil cp gs://your-backup-bucket/loki/loki-backup-YYYYMMDD_HHMMSS.tar.gz /tmp/

# Clear existing data
rm -rf /opt/loki/data/*

# Extract backup
tar -xzf /tmp/loki-backup-*.tar.gz -C /opt/loki/

# Fix permissions
chown -R 10001:10001 /opt/loki/data

# Start Loki
docker start loki
```
