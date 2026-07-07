# iznik-batch Production Deployment Guide

This guide explains how to deploy iznik-batch with the worker pool system on a production server, replacing a "naked" (non-containerized) deployment.

## Deployment Options

There are two ways to deploy iznik-batch:

### Option 1: FreegleDocker Integration (Recommended for development/CI)

Use the `batch-prod` service in FreegleDocker's `docker-compose.yml`. This:
- Integrates with other Freegle services (Loki, monitoring)
- Uses `.env.background` for configuration
- Shares volumes with other Freegle containers
- Is what CircleCI and local development use

To enable: Set `COMPOSE_PROFILES=monitoring,production` in FreegleDocker's `.env`

### Option 2: Standalone Deployment (For isolated production servers)

Use the standalone `docker-compose.batch.yml` described in this guide. This:
- Runs completely independently of FreegleDocker
- Includes its own git-sync for automatic updates
- Has dedicated Redis and MJML containers
- Is suitable for production servers that only run batch processing

**Note**: If migrating from bulk3-internal, the FreegleDocker `batch-prod` service is the recommended replacement. See FreegleDocker's CLAUDE.md for migration instructions.

---

## Standalone Deployment Guide

The following sections describe the standalone deployment option.

## Overview

The worker pool deployment includes:
- **batch-app**: Main Laravel batch application
- **batch-scheduler**: Laravel scheduler (cron replacement)
- **batch-mail-spooler**: Mail delivery daemon
- **batch-redis**: Redis for worker pool semaphores and caching
- **batch-mjml**: MJML compilation server (HTTP mode for back pressure)
- **batch-git-sync**: Automatic code updates from production branch

## Prerequisites

- Docker and Docker Compose v2.20+ installed
- SSH key with read access to the iznik-batch repository
- Access to the production MySQL database
- SMTP credentials for mail delivery
- (Optional) Sentry DSN for error tracking

## Directory Structure

```
/var/www/iznik-batch/
├── docker/
│   ├── docker-compose.batch.yml    # Main compose file
│   └── .env.batch                   # Environment configuration
├── ssh/
│   └── id_rsa                       # Git deploy key (read-only)
├── data/
│   ├── redis/                       # Redis persistence
│   ├── spool/                       # Mail spool (BACKUP CRITICAL)
│   ├── logs/                        # Application logs
│   └── databases/                   # SQLite DBs (Sentry tracking)
└── code/                            # Git-synced code (auto-managed)
```

## Quick Start

### 1. Create deployment directory

```bash
sudo mkdir -p /var/www/iznik-batch/{docker,ssh,data/{redis,spool,logs,databases}}
sudo chown -R $(whoami):$(whoami) /var/www/iznik-batch
cd /var/www/iznik-batch
```

### 2. Copy deployment files

```bash
# From a clone of FreegleDocker
cp iznik-batch/docker/docker-compose.batch.yml docker/
cp iznik-batch/docker/.env.batch.example docker/.env.batch
```

### 3. Configure environment

Edit `docker/.env.batch`:

```bash
# Database (external - your existing MySQL server)
DB_HOST=your-mysql-host.example.com
DB_PORT=3306
DB_DATABASE=iznik
DB_USERNAME=iznik_batch
DB_PASSWORD=secure_password_here

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your_smtp_user
MAIL_PASSWORD=your_smtp_password
MAIL_FROM_ADDRESS=noreply@ilovefreegle.org
MAIL_FROM_NAME=Freegle

# Application key (generate with: php artisan key:generate --show)
APP_KEY=base64:your_generated_key_here

# Sentry (optional but recommended)
SENTRY_LARAVEL_DSN=https://xxx@sentry.io/yyy
SENTRY_ENVIRONMENT=production

# Worker pool tuning (optional - defaults shown)
POOL_MJML_MAX=20       # Max concurrent MJML compilations
POOL_MJML_TIMEOUT=30   # MJML timeout in seconds
POOL_DIGEST_MAX=10     # Max concurrent digest generations

# Git configuration
GIT_REPO=git@github.com:Freegle/iznik-batch.git
GIT_BRANCH=production
GIT_SSH_KEY_PATH=./ssh
```

### 4. Set up Git deploy key

```bash
# Generate a deploy key (or use existing)
ssh-keygen -t ed25519 -f ssh/id_rsa -N ""

# Add the public key to GitHub repository as a deploy key
cat ssh/id_rsa.pub
# Copy output and add to: GitHub > iznik-batch > Settings > Deploy keys
```

### 5. Start services

```bash
cd /var/www/iznik-batch/docker
docker-compose -f docker-compose.batch.yml up -d
```

### 6. Verify deployment

```bash
# Check all services are running
docker-compose -f docker-compose.batch.yml ps

# Check logs
docker logs batch-app --tail 50
docker logs batch-scheduler --tail 50
docker logs batch-mail-spooler --tail 50

# Verify Redis is healthy
docker exec batch-redis redis-cli ping

# Verify MJML is healthy
docker exec batch-mjml wget -q -O- http://localhost:3000/health
```

## Migrating from Naked Deployment

If you currently run iznik-batch directly on the server (without Docker):

### 1. Stop existing services

```bash
# Stop any existing cron jobs
crontab -l | grep iznik-batch  # Note what's running
crontab -e  # Comment out iznik-batch entries

# Stop any running daemons (if using supervisor)
sudo supervisorctl stop iznik-batch:*
```

### 2. Preserve mail spool

**CRITICAL**: The mail spool contains unsent emails. Back it up!

```bash
# Find existing spool location
ls -la /var/www/iznik-batch/storage/spool/mail/pending/

# Copy to new location
cp -r /var/www/iznik-batch/storage/spool/mail/* /var/www/iznik-batch/data/spool/
```

### 3. Deploy containerized version

Follow the Quick Start steps above.

### 4. Verify emails are processing

```bash
# Check mail spooler is processing
docker logs batch-mail-spooler --tail 20

# Check spool directories
docker exec batch-mail-spooler ls -la /app/storage/spool/mail/pending/
```

## Automatic Code Updates

The deployment uses git-sync to automatically pull code changes:

1. CI runs on push to master
2. Tests pass → auto-merge to production branch
3. git-sync container pulls changes (every 60 seconds)
4. `deploy:watch` detects version change in `version.txt`
5. After 5-minute settle time, `deploy:refresh` triggers
6. Daemons gracefully restart to pick up new code

### Monitoring deployments

```bash
# Check git-sync status
docker logs batch-git-sync --tail 20

# Check current deployed version
docker exec batch-app cat /app/version.txt

# Manually trigger refresh (if needed)
docker exec batch-app php artisan deploy:refresh
```

## Worker Pool Configuration

### MJML Pool

Controls concurrent email template compilations. Higher values = more throughput but more memory.

```bash
POOL_MJML_MAX=20      # Max concurrent compilations
POOL_MJML_TIMEOUT=30  # Seconds before timeout
```

### Digest Pool

Controls concurrent digest generation. Memory-intensive operation.

```bash
POOL_DIGEST_MAX=10    # Max concurrent digest builds
```

### Monitoring pool usage

```bash
# Check pool status
docker exec batch-app php artisan pool:status

# Initialize pools (usually automatic)
docker exec batch-app php artisan pool:init
```

## Backup Strategy

### Critical data (backup daily)

```bash
# Mail spool - contains unsent emails
/var/www/iznik-batch/data/spool/
```

### Important data (backup weekly)

```bash
# SQLite databases (Sentry issue tracking)
/var/www/iznik-batch/data/databases/
```

### Low priority (can recreate)

```bash
# Redis data (semaphores, cache - auto-initializes)
/var/www/iznik-batch/data/redis/

# Logs
/var/www/iznik-batch/data/logs/
```

## Troubleshooting

### Service won't start

```bash
# Check logs
docker-compose -f docker-compose.batch.yml logs --tail 50

# Check environment
docker-compose -f docker-compose.batch.yml config
```

### Database connection issues

```bash
# Test database connectivity from container
docker exec batch-app php artisan db:show

# Check MySQL allows connections from Docker network
# You may need to whitelist the Docker host IP
```

### MJML compilation failures

```bash
# Check MJML server health
docker exec batch-mjml wget -q -O- http://localhost:3000/health

# Test MJML compilation
docker exec batch-app php artisan tinker
>>> app(\App\Services\MjmlCompilerService::class)->compile('<mjml><mj-body></mj-body></mjml>')
```

### Mail not sending

```bash
# Check mail spooler logs
docker logs batch-mail-spooler --tail 50

# Check spool directories
docker exec batch-mail-spooler ls -la /app/storage/spool/mail/failed/

# Process failed emails manually
docker exec batch-mail-spooler php artisan mail:spool:retry
```

## Scaling

For high-volume deployments, you can run multiple instances:

```yaml
# In docker-compose.batch.yml, scale the batch-app service
services:
  batch:
    deploy:
      replicas: 3
```

Note: The scheduler and mail-spooler should remain single instances to avoid duplicate processing.

## Rolling Back

If a deployment causes issues:

```bash
# Stop git-sync to prevent further updates
docker-compose -f docker-compose.batch.yml stop git-sync

# Manually checkout previous version
docker exec batch-git-sync git -C /app checkout HEAD~1

# Restart services
docker-compose -f docker-compose.batch.yml restart batch scheduler mail-spooler
```

## Security Notes

- The batch container connects to an **external** MySQL database (not included in this compose file)
- Redis is internal-only (not exposed outside Docker network)
- MJML server is internal-only
- Git SSH key should be read-only deploy key
- Consider using Docker secrets for sensitive environment variables in production
