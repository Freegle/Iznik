#!/bin/bash
# Restore a backup with visible progress indicators
# Usage: ./restore-backup-with-progress.sh <YYYYMMDD>

set -e

BACKUP_DATE=$1
BACKUP_BUCKET="gs://freegle_backup_uk"
BACKUP_DIR="/var/www/FreegleDocker/yesterday/data/backups"
LOG_FILE="/var/log/yesterday-restore-${BACKUP_DATE}.log"
COMPOSE_FILE="/var/www/FreegleDocker/docker-compose.yml"
STATUS_FILE="/var/www/FreegleDocker/yesterday/data/restore-status.json"

# Derive the Docker Compose project name dynamically (defaults to directory name)
PROJECT_NAME=$(cd /var/www/FreegleDocker && docker compose config --format json 2>/dev/null | python3 -c "import sys,json; print(json.load(sys.stdin).get('name','freegledocker'))" 2>/dev/null || echo "freegledocker")

# Helper function to update restore status
update_status() {
    local status=$1
    local message=$2
    local files_remaining=${3:-0}

    cat > "$STATUS_FILE" <<EOF
{
  "status": "$status",
  "message": "$message",
  "backupDate": "$BACKUP_DATE",
  "filesRemaining": $files_remaining,
  "timestamp": "$(date -Iseconds)"
}
EOF
}

if [ -z "$BACKUP_DATE" ]; then
    echo "Usage: $0 <YYYYMMDD>"
    exit 1
fi

if ! [[ "$BACKUP_DATE" =~ ^[0-9]{8}$ ]]; then
    echo "Error: Invalid date format. Use YYYYMMDD"
    exit 1
fi

exec > >(tee -a "$LOG_FILE") 2>&1

# Trap errors: update status AND restart yesterday services
# This is critical - the restore script kills ALL containers (including yesterday services)
# early in the process. If it fails before reaching the restart code at the end,
# the yesterday services (API, 2FA, traefik) stay dead, breaking the entire system.
cleanup_on_failure() {
    update_status "failed" "Restore failed - check logs"
    # Clean up Loki temp file if it exists (can be 20GB+)
    rm -f /tmp/loki-backup.tar.gz 2>/dev/null || true
    # Always clean up skip-grant-tables if it was added (leaves percona with no TCP port)
    sed -i '/skip-grant-tables/d' /var/www/FreegleDocker/conf/percona-my.cnf 2>/dev/null || true
    echo "Restarting main Docker stack after failure..."
    cd /var/www/FreegleDocker
    docker compose up -d 2>/dev/null || true
    echo "Restarting yesterday services after failure..."
    docker compose -f /var/www/FreegleDocker/yesterday/docker-compose.yesterday-services.yml up -d 2>/dev/null || true
    echo "✅ Services restarted after failure"
}
trap cleanup_on_failure ERR

echo "=== Yesterday Restoration Started: $(date) ==="
echo "Restoring backup from date: ${BACKUP_DATE}..."
update_status "starting" "Finding backup..."

# Clean up old restore logs (keep last 7 days)
find /var/log/yesterday-restore-*.log -mtime +7 -delete 2>/dev/null || true

mkdir -p "$BACKUP_DIR"

FORMATTED_DATE="${BACKUP_DATE:0:4}-${BACKUP_DATE:4:2}-${BACKUP_DATE:6:2}"

echo "Finding backup for date ${FORMATTED_DATE}..."
BACKUP_FILE=$(gsutil ls "$BACKUP_BUCKET/iznik-${FORMATTED_DATE}-*.xbstream" 2>/dev/null | head -1)

if [ -z "$BACKUP_FILE" ]; then
    echo "❌ Could not find backup for date ${FORMATTED_DATE}"
    exit 1
fi

echo "Selected backup: $BACKUP_FILE"

# Extract backup time from filename (iznik-YYYY-MM-DD-HH-MM.xbstream -> HH:MM)
BACKUP_TIME=$(basename "$BACKUP_FILE" | grep -oP '\d{4}-\d{2}-\d{2}-\K\d{2}-\d{2}' | tr '-' ':')
echo "Backup time: $BACKUP_TIME"

BACKUP_SIZE=$(gsutil ls -l "$BACKUP_FILE" | grep -v TOTAL | awk '{print $1}')
BACKUP_SIZE_GB=$((BACKUP_SIZE / 1024 / 1024 / 1024))
echo "Backup size: ${BACKUP_SIZE_GB}GB (compressed)"

echo "Updating Yesterday code..."
cd /var/www/FreegleDocker
git fetch origin
git reset --hard origin/master
echo "✅ Code updated"

echo "Configuring Yesterday environment..."
if [ -f yesterday/docker-compose.override.yml ]; then
    cp yesterday/docker-compose.override.yml docker-compose.override.yml
    echo "✅ Copied docker-compose.override.yml (configured for production images)"
else
    echo "⚠️  Warning: yesterday/docker-compose.override.yml not found"
fi

echo "Cleaning up unused Docker images..."
docker image prune -af 2>/dev/null | tail -1 || true

echo "Stopping all Docker containers..."
update_status "stopping" "Stopping containers..."
# Aggressive shutdown - we're restoring from backup so don't need to preserve anything
docker compose down --timeout 10 --remove-orphans 2>/dev/null || true
# Force stop any remaining containers (in case compose down didn't get everything)
docker stop $(docker ps -q) 2>/dev/null || true
# Remove all containers to prevent name conflicts
docker rm -f $(docker ps -aq) 2>/dev/null || true
echo "✅ All containers stopped and removed"

echo "Restarting Yesterday services (status page available during restore)..."
# Recreate the freegledocker_default network (destroyed by docker compose down above).
# yesterday-2fa needs this network in its compose config even though backends are down.
docker network create freegledocker_default 2>/dev/null || true
docker compose -f /var/www/FreegleDocker/yesterday/docker-compose.yesterday-services.yml up -d 2>/dev/null || true
echo "✅ Yesterday services running — users see restore progress instead of connection errors"

echo "Getting volume path..."
VOLUME_PATH=$(docker volume inspect freegle_db -f '{{.Mountpoint}}' 2>/dev/null || echo "")

if [ -z "$VOLUME_PATH" ]; then
    echo "Creating fresh database volume..."
    docker volume create freegle_db
    VOLUME_PATH=$(docker volume inspect freegle_db -f '{{.Mountpoint}}')
fi

echo "Volume path: ${VOLUME_PATH}"

echo "Clearing old database data from volume..."
rm -rf "${VOLUME_PATH}"/*
echo "✅ Volume cleared"

ESTIMATED_FINAL_GB=$((BACKUP_SIZE_GB * 21 / 10))

echo ""
echo "=========================================="
echo "Streaming and extracting backup directly to volume..."
echo "Compressed: ${BACKUP_SIZE_GB}GB → Estimated final: ~${ESTIMATED_FINAL_GB}GB"
echo "=========================================="
echo ""
update_status "extracting" "Downloading and extracting backup..."

# Show progress by monitoring extracted files in background
(
    sleep 5
    while [ ! -f "${VOLUME_PATH}/.extraction_done" ]; do
        FILE_COUNT=$(find "$VOLUME_PATH" -type f 2>/dev/null | wc -l)
        DIR_SIZE_RAW=$(du -sb "$VOLUME_PATH" 2>/dev/null | awk '{print $1}')
        DIR_SIZE=$(du -sh "$VOLUME_PATH" 2>/dev/null | awk '{print $1}')
        PERCENT=$((DIR_SIZE_RAW * 100 / (BACKUP_SIZE_GB * 1024 * 1024 * 1024)))
        echo "[$(date +%H:%M:%S)] Extracting: ${DIR_SIZE} / ~${ESTIMATED_FINAL_GB}GB (${PERCENT}% of compressed backup extracted)"
        sleep 10
    done
) &
PROGRESS_PID=$!

# Stream directly from GCS and extract to volume - no temp directory
gsutil cat "$BACKUP_FILE" | xbstream -x -C "$VOLUME_PATH"

# Signal extraction is done
touch "${VOLUME_PATH}/.extraction_done"
kill $PROGRESS_PID 2>/dev/null || true

echo ""
echo "✅ Extraction complete!"
FINAL_COUNT=$(find "$VOLUME_PATH" -type f 2>/dev/null | wc -l)
FINAL_SIZE=$(du -sh "$VOLUME_PATH" 2>/dev/null | awk '{print $1}')
echo "Total extracted: $FINAL_COUNT files, ${FINAL_SIZE}"
echo ""

echo "=========================================="
echo "Decompressing zstd files..."
echo "=========================================="
echo ""
update_status "decompressing" "Decompressing database files..."

# Get number of CPU cores for parallel decompression
CORES=$(nproc)
echo "Using $CORES parallel processes for decompression"

# Decompress all .zst files in parallel using xargs
(
    ZST_COUNT=0
    while true; do
        CURRENT_ZST=$(find "$VOLUME_PATH" -type f -name "*.zst" 2>/dev/null | wc -l)
        if [ $CURRENT_ZST -eq 0 ]; then
            break
        fi
        if [ $ZST_COUNT -ne $CURRENT_ZST ]; then
            echo "[$(date +%H:%M:%S)] Decompressing... $CURRENT_ZST .zst files remaining"
            update_status "decompressing" "Decompressing database files ($CURRENT_ZST files remaining)" "$CURRENT_ZST"
            ZST_COUNT=$CURRENT_ZST
        fi
        sleep 10
    done
) &
ZSTD_PID=$!

find "$VOLUME_PATH" -type f -name "*.zst" -print0 | xargs -0 -P $CORES -I {} zstd -d --rm {}

kill $ZSTD_PID 2>/dev/null || true

echo ""
echo "✅ Decompression complete!"
echo ""

# Extract Percona version from backup and update docker-compose.yml
echo "=========================================="
echo "Detecting and configuring Percona version..."
echo "=========================================="

BACKUP_SERVER_VERSION=$(grep "^server_version" "$VOLUME_PATH/xtrabackup_info" | awk '{print $3}')
echo "Backup was created with Percona version: $BACKUP_SERVER_VERSION"

# Extract major.minor.patch-build format (e.g., 8.0.42-33)
PERCONA_VERSION=$(echo "$BACKUP_SERVER_VERSION" | sed 's/\([0-9]\+\.[0-9]\+\.[0-9]\+-[0-9]\+\).*/\1/')
echo "Using Percona Docker image: percona:$PERCONA_VERSION"

# Create my.cnf with InnoDB parameters from backup
MYCNF_FILE="/var/www/FreegleDocker/conf/percona-my.cnf"
cat > "$MYCNF_FILE" << EOF
[mysqld]
max_connections = 500
innodb_data_file_path=$(grep "^innodb_data_file_path" "$VOLUME_PATH/backup-my.cnf" | cut -d= -f2)
innodb_page_size=$(grep "^innodb_page_size" "$VOLUME_PATH/backup-my.cnf" | cut -d= -f2)
innodb_undo_tablespaces=$(grep "^innodb_undo_tablespaces" "$VOLUME_PATH/backup-my.cnf" | cut -d= -f2)
skip-log-bin
skip-log-slave-updates
sql_mode=STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
EOF

echo "Created MySQL config file: $MYCNF_FILE"

# Generate MySQL client configs for containers
echo "Generating MySQL client configs..."
/var/www/FreegleDocker/scripts/generate-mysql-configs.sh

# Update docker-compose.yml with the correct version and config file mount
sed -i "s|image: percona:.*|image: percona:$PERCONA_VERSION|g" "$COMPOSE_FILE"

# Remove old command-line parameters and add config file mount
sed -i '/^    image: percona:/,/^    volumes:/ {
  /command:/d
}' "$COMPOSE_FILE"

# Add config file mount if not already present
if ! grep -q "percona-my.cnf:/etc/my.cnf" "$COMPOSE_FILE"; then
  sed -i '/freegle_db:\/var\/lib\/mysql/a\      - ./conf/percona-my.cnf:/etc/my.cnf:ro' "$COMPOSE_FILE"
fi

# Update health check to work with restored databases
sed -i 's|mysql -h localhost -u root -piznik -e .SELECT 1.|mysqladmin ping -h localhost|g' "$COMPOSE_FILE"

echo "✅ Updated docker-compose.yml with correct Percona version, config file, and health check"
echo ""

echo "=========================================="
echo "Preparing backup (applying transaction logs)..."
echo "=========================================="
echo ""
update_status "preparing" "Preparing database..."

# Show that preparation is running
(
    while ps aux | grep -q "[x]trabackup --prepare"; do
        echo "[$(date +%H:%M:%S)] Still preparing backup..."
        sleep 15
    done
) &
PREPARE_PID=$!

# First prepare with --apply-log-only to apply redo logs
xtrabackup --prepare --apply-log-only --target-dir="$VOLUME_PATH"

# Final prepare without --apply-log-only to complete the restore and roll back uncommitted transactions
echo ""
echo "=========================================="
echo "Final prepare (rolling back uncommitted transactions)..."
echo "This will take a few minutes..."
echo "=========================================="
echo ""
xtrabackup --prepare --target-dir="$VOLUME_PATH"

kill $PREPARE_PID 2>/dev/null || true
echo ""
echo "✅ Preparation complete!"
echo ""

echo "Detecting MySQL user ID from Percona image..."
MYSQL_UID=$(docker run --rm percona:$PERCONA_VERSION id -u mysql)
MYSQL_GID=$(docker run --rm percona:$PERCONA_VERSION id -g mysql)
echo "MySQL runs as UID:GID ${MYSQL_UID}:${MYSQL_GID} in this Percona version"

echo "Setting ownership..."
sudo chown -R ${MYSQL_UID}:${MYSQL_GID} "${VOLUME_PATH}"
rm -f "${VOLUME_PATH}/.extraction_done"
echo "✅ Volume ready"

echo "Verifying ownership..."
ACTUAL_UID=$(sudo stat -c '%u' "${VOLUME_PATH}/ibdata1")
ACTUAL_GID=$(sudo stat -c '%g' "${VOLUME_PATH}/ibdata1")
if [ "$ACTUAL_UID" = "$MYSQL_UID" ] && [ "$ACTUAL_GID" = "$MYSQL_GID" ]; then
    echo "✅ Ownership verified: ${ACTUAL_UID}:${ACTUAL_GID}"
else
    echo "❌ Ownership mismatch: expected ${MYSQL_UID}:${MYSQL_GID}, got ${ACTUAL_UID}:${ACTUAL_GID}"
    exit 1
fi

# Function to wait for container to be healthy
wait_for_container_health() {
    local container=$1
    local max_wait=${2:-180}  # Default 3 minutes
    local waited=0

    echo "Waiting for $container to become healthy..."
    while [ $waited -lt $max_wait ]; do
        if docker compose ps "$container" 2>/dev/null | grep -q "healthy"; then
            echo "✅ $container is healthy"
            return 0
        fi

        if docker compose ps "$container" 2>/dev/null | grep -q "Up"; then
            # Container is up but may not have health check or not healthy yet
            sleep 5
            waited=$((waited + 5))
        else
            echo "⚠️  $container not running yet, waiting..."
            sleep 5
            waited=$((waited + 5))
        fi
    done

    echo "❌ Timeout waiting for $container to become healthy"
    return 1
}

# Function to check if all expected containers are running
verify_all_containers() {
    echo ""
    echo "Verifying all containers are running..."

    local failed_containers=()
    local created_containers=()

    # Get list of containers in Created state (not started)
    while IFS= read -r container; do
        if [ -n "$container" ]; then
            created_containers+=("$container")
        fi
    done < <(docker-compose ps -a 2>/dev/null | grep "Created" | awk '{print $1}' | sed "s/${PROJECT_NAME}-//")

    # Get list of exited/unhealthy containers
    while IFS= read -r container; do
        if [ -n "$container" ]; then
            failed_containers+=("$container")
        fi
    done < <(docker-compose ps 2>/dev/null | grep -E "(Exit|unhealthy)" | awk '{print $1}' | sed "s/${PROJECT_NAME}-//")

    if [ ${#created_containers[@]} -gt 0 ]; then
        echo "⚠️  Found containers in Created state (not started): ${created_containers[*]}"
        echo "Attempting to start them..."
        docker compose up -d
        sleep 10
    fi

    if [ ${#failed_containers[@]} -gt 0 ]; then
        echo "⚠️  Found failed containers: ${failed_containers[*]}"
        return 1
    fi

    echo "✅ All containers are running"
    return 0
}

echo ""
echo "=========================================="
echo "Restoring Loki logs backup..."
echo "=========================================="
update_status "restoring_loki" "Restoring Loki logs..."

# Find most recent Loki backup ON OR BEFORE the database backup date
# This ensures log consistency - no logs for events after the DB snapshot
echo "Looking for Loki backup on or before ${BACKUP_DATE}..."
LOKI_BACKUP=$(gsutil ls "$BACKUP_BUCKET/loki/" 2>/dev/null | while read backup; do
    # Extract date from filename (loki-backup-YYYYMMDD_HHMMSS.tar.gz)
    loki_date=$(basename "$backup" | grep -oE '[0-9]{8}' | head -1)
    if [ -n "$loki_date" ] && [ "$loki_date" -le "$BACKUP_DATE" ]; then
        echo "$backup"
    fi
done | sort -r | head -1)

if [ -n "$LOKI_BACKUP" ]; then
    LOKI_BACKUP_DATE=$(basename "$LOKI_BACKUP" | grep -oE '[0-9]{8}' | head -1)
    echo "Found Loki backup: $LOKI_BACKUP (date: $LOKI_BACKUP_DATE)"
    LOKI_SIZE=$(gsutil ls -l "$LOKI_BACKUP" | grep -v TOTAL | awk '{print $1}')
    LOKI_SIZE_GB=$((LOKI_SIZE / 1024 / 1024 / 1024))
    echo "Loki backup size: ${LOKI_SIZE_GB}GB"

    # Create loki-data volume if it doesn't exist
    if ! docker volume inspect loki-data >/dev/null 2>&1; then
        echo "Creating loki-data volume..."
        docker volume create loki-data
    fi

    # Get volume path and clear it
    LOKI_VOLUME_PATH=$(docker volume inspect loki-data -f '{{.Mountpoint}}')
    echo "Clearing existing Loki data..."
    rm -rf "${LOKI_VOLUME_PATH}"/*

    # Check disk space before downloading (need ~2x Loki size: download + extraction)
    AVAIL_GB=$(df --output=avail / | tail -1 | awk '{print int($1/1024/1024)}')
    LOKI_NEEDED_GB=$(( LOKI_SIZE_GB * 3 ))
    echo "Available disk: ${AVAIL_GB}GB, estimated need: ${LOKI_NEEDED_GB}GB (download + extraction)"
    if [ "$AVAIL_GB" -lt "$LOKI_NEEDED_GB" ]; then
        echo "⚠️  Insufficient disk space for Loki restore (${AVAIL_GB}GB < ${LOKI_NEEDED_GB}GB) - skipping"
        echo "   Loki will start with empty data. Free disk space and restore manually."
    else
        # Download Loki backup to local file first, then extract
        # gsutil cp has built-in retries and resumable downloads, unlike gsutil cat which
        # streams through a single SSL connection that fails on large files (20GB+)
        LOKI_LOCAL="/tmp/loki-backup.tar.gz"
        echo "Downloading Loki backup to local file..."
        rm -f "$LOKI_LOCAL"
        gsutil -o 'GSUtil:resumable_threshold=1048576' cp "$LOKI_BACKUP" "$LOKI_LOCAL"
        echo "Extracting Loki backup to volume..."
        tar -xzf "$LOKI_LOCAL" -C "${LOKI_VOLUME_PATH}" --strip-components=1
        rm -f "$LOKI_LOCAL"

        # Set ownership for Loki (runs as UID 10001)
        chown -R 10001:10001 "${LOKI_VOLUME_PATH}"

        LOKI_FINAL_SIZE=$(du -sh "${LOKI_VOLUME_PATH}" | awk '{print $1}')
        echo "✅ Loki backup restored: ${LOKI_FINAL_SIZE}"

        # Flush disk buffers and pause to let I/O settle
        # After extracting 26GB of data, the system is under heavy I/O load.
        # Without this pause, containers (especially traefik) may fail healthchecks
        # due to slow disk access when reading config files.
        echo "Syncing disk buffers after Loki restore..."
        sync
        echo "Waiting for I/O to settle..."
        sleep 15
    fi
else
    echo "⚠️  No Loki backup found in $BACKUP_BUCKET/loki/ - skipping Loki restore"
    echo "   Loki will start with empty data (no historical logs)"
    # Still create the volume so Loki can start
    if ! docker volume inspect loki-data >/dev/null 2>&1; then
        docker volume create loki-data
    fi
fi

echo ""
echo "=========================================="
echo "Pre-flight volume check..."
echo "=========================================="
# Ensure all required volumes exist before starting containers
# This prevents "external volume not found" errors
for vol in freegle_db loki-data; do
    if ! docker volume inspect "$vol" >/dev/null 2>&1; then
        echo "Creating missing volume: $vol"
        docker volume create "$vol"
    else
        echo "✅ Volume exists: $vol"
    fi
done

echo ""
echo "=========================================="
echo "Building containers from scratch..."
echo "=========================================="
update_status "building" "Building containers..."
# Build containers to pick up code changes (new Go dependencies, Nuxt changes, etc.)
# Without this, docker compose up -d reuses stale images and containers may crash
# on missing modules or run outdated code.
# Only build services that Yesterday actually uses and that compile code into the image:
# - base: shared base image for apiv1
# - apiv1: PHP API (rarely changes but uses base)
# - apiv2: Go API (compiles at startup, needs go modules in image)
# - freegle-dev-local/modtools-dev-local: Nuxt dev containers (need npm deps in image)
# - status: Status page
echo "Building base image..."
docker compose build base 2>&1 | tail -3
echo "Building application containers..."
docker compose build apiv1 apiv2 freegle-dev-local modtools-dev-local status 2>&1 | tail -5
echo "✅ Container build complete"

echo ""
echo "=========================================="
echo "Starting infrastructure containers first..."
echo "=========================================="
update_status "starting_infra" "Starting infrastructure containers..."
# Start infrastructure services individually, NOT with 'docker compose up -d' which
# enforces depends_on health check constraints. After restoring 22GB+ Loki backup,
# disk I/O is saturated and containers may take longer than their health check windows
# to become healthy. Starting individually + manual waiting avoids the cascade failure
# where Docker Compose skips dependent containers when any dependency times out.
docker compose up -d percona redis postgres loki beanstalkd spamassassin-app rspamd mailpit mjml 2>&1 || true

echo ""
echo "Waiting for critical infrastructure containers..."

# Wait for Percona to be healthy - restored production DB needs InnoDB init (60-90s)
wait_for_container_health "percona" 240 || {
    echo "❌ Percona failed to start. Checking logs..."
    docker logs ${PROJECT_NAME}-percona --tail 20
    exit 1
}

# Reset MySQL root password to 'iznik' for local container access.
# The restored production backup has a different root password baked into the data volume.
# We use --skip-grant-tables to bypass auth, reset the password, then restart normally.
echo "Resetting MySQL root password for local container access..."
docker compose stop percona
echo "skip-grant-tables" >> /var/www/FreegleDocker/conf/percona-my.cnf
docker compose start percona
# MySQL 8.0: skip-grant-tables implies skip-networking (no TCP port).
# The healthcheck uses TCP, so it will never pass. Wait for the socket instead.
echo "Waiting for MySQL socket (skip-grant-tables disables TCP)..."
for i in $(seq 1 60); do
    if docker exec ${PROJECT_NAME}-percona mysqladmin ping --socket=/var/lib/mysql/mysql.sock 2>/dev/null; then
        echo "✅ MySQL ready via socket"
        break
    fi
    sleep 2
done
# Use 'if' to prevent set -e from killing the script on failure
if docker exec ${PROJECT_NAME}-percona mysql --socket=/var/lib/mysql/mysql.sock -u root -e "FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED BY 'iznik'; ALTER USER 'root'@'%' IDENTIFIED BY 'iznik'; FLUSH PRIVILEGES;" 2>/dev/null; then
    echo "✅ MySQL root password reset to 'iznik'"
else
    echo "⚠️ Failed to reset MySQL root password - containers may not connect to DB"
fi
sed -i '/skip-grant-tables/d' /var/www/FreegleDocker/conf/percona-my.cnf
docker compose restart percona
wait_for_container_health "percona" 120 || {
    echo "❌ Percona failed to restart after password reset"
    exit 1
}
echo "✅ Percona restarted with normal authentication"

wait_for_container_health "redis" 60
wait_for_container_health "postgres" 120
wait_for_container_health "beanstalkd" 60
wait_for_container_health "loki" 120

echo ""
echo "=========================================="
echo "Starting all remaining containers..."
echo "=========================================="
update_status "starting_apps" "Starting application containers..."
# Now that infrastructure is healthy, start everything else.
# Docker Compose will see the infrastructure containers already running+healthy
# and start the dependent application containers without timeout issues.
docker compose up -d

echo "Configuring PHP-FPM for production load..."
# Wait for API v1 container to be running
sleep 5
docker exec ${PROJECT_NAME}-apiv1 sed -i 's/^pm.max_children = 5$/pm.max_children = 20/' /etc/php/8.1/fpm/pool.d/www.conf
docker exec ${PROJECT_NAME}-apiv1 sed -i 's/^pm.start_servers = 2$/pm.start_servers = 5/' /etc/php/8.1/fpm/pool.d/www.conf
docker exec ${PROJECT_NAME}-apiv1 sed -i 's/^pm.min_spare_servers = 1$/pm.min_spare_servers = 3/' /etc/php/8.1/fpm/pool.d/www.conf
docker exec ${PROJECT_NAME}-apiv1 sed -i 's/^pm.max_spare_servers = 3$/pm.max_spare_servers = 10/' /etc/php/8.1/fpm/pool.d/www.conf
docker exec ${PROJECT_NAME}-apiv1 /etc/init.d/php8.1-fpm restart
echo "✅ PHP-FPM configured with increased worker pool"

echo ""
echo "Infrastructure containers ready. Waiting for application containers..."
sleep 10

wait_for_container_health "reverse-proxy" 120 || {
    echo "❌ Traefik failed to start. Checking logs..."
    docker logs ${PROJECT_NAME}-traefik --tail 20
    exit 1
}

# Verify all containers are running, retry if needed
MAX_RETRIES=3
RETRY=0
while [ $RETRY -lt $MAX_RETRIES ]; do
    if verify_all_containers; then
        break
    fi

    RETRY=$((RETRY + 1))
    if [ $RETRY -lt $MAX_RETRIES ]; then
        echo "Retry $RETRY/$MAX_RETRIES: Restarting failed containers..."
        docker compose up -d
        sleep 15
    fi
done

if [ $RETRY -eq $MAX_RETRIES ]; then
    echo "❌ Failed to start all containers after $MAX_RETRIES attempts"
    echo "Current container status:"
    docker compose ps
    exit 1
fi

# Wait for apiv2 to be healthy (it takes time to compile)
echo ""
echo "Waiting for API v2 to compile and start (this may take 60-90 seconds)..."
wait_for_container_health "apiv2" 180

echo ""
echo "Verifying database..."

# Load MySQL password from .env
MYSQL_PASSWORD=$(grep "^MYSQL_PRODUCTION_ROOT_PASSWORD=" /var/www/FreegleDocker/.env | cut -d= -f2)
if [ -z "$MYSQL_PASSWORD" ]; then
    echo "⚠️ MYSQL_PRODUCTION_ROOT_PASSWORD not found in .env, trying default password"
    MYSQL_PASSWORD="iznik"
fi

if docker exec ${PROJECT_NAME}-percona mysql -uroot -p"${MYSQL_PASSWORD}" -e "SHOW DATABASES;" 2>/dev/null | grep -q "iznik"; then
    echo "✅ Database verification successful!"

    TABLE_COUNT=$(docker exec ${PROJECT_NAME}-percona mysql -uroot -p"${MYSQL_PASSWORD}" iznik -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='iznik';" 2>/dev/null | tail -1)
    echo "Database contains ${TABLE_COUNT} tables"
else
    echo "❌ Database verification failed!"
    exit 1
fi

# Update current backup tracker with date and time
/var/www/FreegleDocker/yesterday/scripts/set-current-backup.sh "${BACKUP_DATE}" "${BACKUP_TIME}"

echo ""
if systemctl list-units --full --all | grep -q "docker-compose@freegle.service"; then
    echo "Restarting main Docker stack systemd service..."
    systemctl restart docker-compose@freegle
    sleep 10
    echo "✅ Main stack restarted"
else
    echo "ℹ️  No systemd service found (Yesterday deployment) - skipping systemctl restart"
fi

echo ""
echo "Restarting Yesterday services (API, 2FA gateway, Traefik)..."
docker compose -f /var/www/FreegleDocker/yesterday/docker-compose.yesterday-services.yml up -d
echo "✅ Yesterday services restarted"

echo ""
echo "=== Yesterday Restoration Completed: $(date) ==="
echo "✅ Backup from ${FORMATTED_DATE} restored successfully"
update_status "completed" "Restore completed successfully"
echo ""
echo "Access the system at:"
echo "  - Freegle: http://localhost:3000"
echo "  - ModTools: http://localhost:3001"
echo "  - Mailhog: http://localhost:8025"
