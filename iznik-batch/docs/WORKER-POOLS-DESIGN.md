# Worker Pools Design for iznik-batch

## Overview

This document describes the architecture for a worker pool system in iznik-batch that provides:

1. **Back pressure** - When downstream workers are busy, upstream producers block/slow down
2. **Bounded queues** - Never use unbounded queues in production
3. **Generic design** - Usable for MJML compilation, digest generation, and future tasks
4. **Docker deployment** - Containerized services with git-based code updates
5. **Sentry alerting** - Throttled alerts when hitting max pool capacity

## Architecture Decisions

### Why Not Laravel Queues?

Standard Laravel queue mechanisms (database, Redis list-based) don't provide back pressure:

- Producers can enqueue indefinitely without blocking
- Queue grows without bound until memory/disk exhausted
- No signal to producers that consumers are overwhelmed

We need **blocking back pressure**: when MJML workers are all busy, digest processes should block until a worker is available. This naturally throttles the entire pipeline.

### Redis BLPOP Semaphore Pattern

We use Redis `BLPOP` (blocking list pop) to implement a bounded semaphore:

```
┌─────────────┐    acquire()    ┌──────────────────┐
│  Producer   │────BLPOP───────▶│  Redis Permits   │
│  (Digest)   │                 │  [1][1][1][1]    │
└─────────────┘                 └──────────────────┘
       │                               │
       │ work with permit              │ release()
       ▼                               │ RPUSH
┌─────────────┐                        │
│   Worker    │◀───────────────────────┘
│   (MJML)    │
└─────────────┘
```

- Pool initialized with N permits (tokens in a Redis list)
- `acquire()` uses `BLPOP` - blocks until permit available
- `release()` uses `RPUSH` - returns permit to pool
- When all permits taken, new requests block at `acquire()`

### MJML Service Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    iznik-batch Container                         │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────┐         │
│  │   Digest    │    │   Digest    │    │   Digest    │         │
│  │  Worker 1   │    │  Worker 2   │    │  Worker N   │         │
│  └──────┬──────┘    └──────┬──────┘    └──────┬──────┘         │
│         │                  │                  │                 │
│         └──────────────────┼──────────────────┘                 │
│                            │                                    │
│                    ┌───────▼───────┐                            │
│                    │  BoundedPool  │◀──── Redis BLPOP           │
│                    │  (max: 20)    │      (back pressure)       │
│                    └───────┬───────┘                            │
│                            │                                    │
│                    ┌───────▼───────┐                            │
│                    │ MjmlCompiler- │                            │
│                    │    Service    │                            │
│                    └───────┬───────┘                            │
└────────────────────────────┼────────────────────────────────────┘
                             │ HTTP
                     ┌───────▼───────┐
                     │    Redis      │
                     │  (semaphore)  │
                     └───────────────┘
                     ┌───────▼───────┐
                     │  MJML Server  │
                     │  (Node.js)    │
                     └───────────────┘
```

## Docker Compose Production Architecture

### Batch Profile

For production servers that only need batch processing (not the full Freegle stack):

```yaml
services:
  # Core services only started with: docker-compose --profile batch up -d

  redis:
    image: redis:alpine
    profiles: [batch]
    volumes:
      - redis-data:/data
    restart: unless-stopped

  mjml:
    image: adrianrudnik/mjml-server:latest
    profiles: [batch]
    restart: unless-stopped
    # Node.js single-threaded but event loop handles concurrency well
    # BoundedPool provides the back pressure, MJML handles I/O
```

### Code Deployment Strategy

The production Docker setup uses **git-based deployment** rather than image rebuilds:

```
┌─────────────────────────────────────────────────────────────────┐
│                     GitHub Repository                            │
│  ┌──────────┐    auto-merge    ┌────────────────┐              │
│  │  master  │──────────────────▶│   production   │              │
│  └──────────┘   (after tests)   └───────┬────────┘              │
└─────────────────────────────────────────┼───────────────────────┘
                                          │
                    ┌─────────────────────▼─────────────────────┐
                    │          Production Server                 │
                    │  ┌─────────────────────────────────────┐  │
                    │  │  git-sync container (every minute)  │  │
                    │  │  - git fetch origin production      │  │
                    │  │  - git reset --hard                 │  │
                    │  │  - touch version.txt if changed     │  │
                    │  └────────────────┬────────────────────┘  │
                    │                   │                        │
                    │  ┌────────────────▼────────────────────┐  │
                    │  │  deploy:watch (existing command)    │  │
                    │  │  - Detects version.txt change       │  │
                    │  │  - Waits 5 min settle time          │  │
                    │  │  - Triggers deploy:refresh          │  │
                    │  └─────────────────────────────────────┘  │
                    └────────────────────────────────────────────┘
```

**Why git-based over image rebuilds?**

1. **Faster updates** - No image build/push/pull cycle (seconds vs minutes)
2. **Existing infrastructure** - deploy:watch/deploy:refresh already works
3. **Simpler CI/CD** - Tests pass → merge to production → done
4. **No registry needed** - No Docker Hub/GHCR costs or complexity

### Git-Sync Container

A lightweight container that polls the git repository:

```yaml
git-sync:
  image: alpine/git
  profiles: [batch]
  volumes:
    - ./:/app
    - git-sync-ssh:/root/.ssh:ro  # SSH key for private repo
  working_dir: /app
  command: |
    while true; do
      git fetch origin production
      LOCAL=$(git rev-parse HEAD)
      REMOTE=$(git rev-parse origin/production)
      if [ "$LOCAL" != "$REMOTE" ]; then
        echo "New version detected, pulling..."
        git reset --hard origin/production
        # Touch version.txt to trigger deploy:watch
        touch version.txt
      fi
      sleep 60
    done
  restart: unless-stopped
```

Alternatively, use the established [kubernetes-sigs/git-sync](https://github.com/kubernetes-sigs/git-sync) image which is production-tested.

## Persistent Volumes

### What Needs Persistence

| Volume | Purpose | Recovery Impact |
|--------|---------|-----------------|
| `redis-data` | Semaphore state, cache | Low - permits auto-initialize |
| `spool-data` | Email spool (pending/sent/failed) | High - emails could be lost |
| `logs` | Application logs | Medium - historical data |
| `sqlite-dbs` | Tracking databases | Medium - Sentry dedup, etc. |

### Volume Configuration

```yaml
volumes:
  redis-data:
    driver: local
  spool-data:
    driver: local
  batch-logs:
    driver: local

services:
  batch:
    volumes:
      - spool-data:/app/storage/spool
      - batch-logs:/app/storage/logs
```

### Backup Considerations

- **spool-data**: Critical - backup pending/failed directories
- **redis-data**: Optional - permits self-heal on restart
- **logs**: Archive periodically, not critical for operation

## BoundedPool Implementation

### Core Class

```php
<?php

namespace App\Services\WorkerPool;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class BoundedPool
{
    private string $permitsKey;
    private string $statsKey;

    public function __construct(
        private string $name,
        private int $maxConcurrency,
        private int $timeoutSeconds = 0,  // 0 = block forever
        private int $sentryThrottleSeconds = 300
    ) {
        $this->permitsKey = "pool:{$name}:permits";
        $this->statsKey = "pool:{$name}:stats";
    }

    /**
     * Initialize the pool with permits.
     * Safe to call multiple times - only adds missing permits.
     */
    public function initialize(): void
    {
        $current = Redis::llen($this->permitsKey);
        $needed = $this->maxConcurrency - $current;

        if ($needed > 0) {
            for ($i = 0; $i < $needed; $i++) {
                Redis::rpush($this->permitsKey, '1');
            }
            Log::info("BoundedPool[{$this->name}]: Initialized {$needed} permits");
        }
    }

    /**
     * Acquire a permit. Blocks until one is available.
     * Returns false only if timeout is set and exceeded.
     */
    public function acquire(): bool
    {
        $result = Redis::blpop($this->permitsKey, $this->timeoutSeconds);

        if ($result === null) {
            $this->recordTimeout();
            return false;
        }

        $this->recordAcquire();
        return true;
    }

    /**
     * Release a permit back to the pool.
     */
    public function release(): void
    {
        Redis::rpush($this->permitsKey, '1');
        $this->recordRelease();
    }

    /**
     * Execute callback with a permit, ensuring release on completion.
     */
    public function withPermit(callable $callback): mixed
    {
        if (!$this->acquire()) {
            throw new PoolTimeoutException(
                "Could not acquire permit for pool: {$this->name}"
            );
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /**
     * Get current pool statistics.
     */
    public function getStats(): array
    {
        $available = Redis::llen($this->permitsKey);

        return [
            'name' => $this->name,
            'max_concurrency' => $this->maxConcurrency,
            'available' => $available,
            'in_use' => $this->maxConcurrency - $available,
            'timeouts' => (int) Redis::hget($this->statsKey, 'timeouts') ?? 0,
            'total_acquired' => (int) Redis::hget($this->statsKey, 'acquired') ?? 0,
        ];
    }

    /**
     * Check if pool is at capacity.
     */
    public function isAtCapacity(): bool
    {
        return Redis::llen($this->permitsKey) === 0;
    }

    private function recordAcquire(): void
    {
        Redis::hincrby($this->statsKey, 'acquired', 1);
    }

    private function recordRelease(): void
    {
        // Could track release count if needed
    }

    private function recordTimeout(): void
    {
        $count = Redis::hincrby($this->statsKey, 'timeouts', 1);

        // Throttled Sentry alert - only alert every N seconds
        $lastAlertKey = "pool:{$this->name}:last_alert";
        $lastAlert = Redis::get($lastAlertKey);

        if (!$lastAlert || (time() - (int)$lastAlert) > $this->sentryThrottleSeconds) {
            Redis::set($lastAlertKey, time());

            Log::error("BoundedPool[{$this->name}] at max capacity", [
                'pool' => $this->name,
                'max_concurrency' => $this->maxConcurrency,
                'total_timeouts' => $count,
            ]);

            // Sentry will capture this via the Laravel Sentry integration
            report(new PoolCapacityException(
                "Pool '{$this->name}' at maximum capacity ({$this->maxConcurrency})"
            ));
        }
    }
}
```

### Exception Classes

```php
<?php

namespace App\Services\WorkerPool;

class PoolTimeoutException extends \RuntimeException {}
class PoolCapacityException extends \RuntimeException {}
```

## MjmlCompilerService Implementation

```php
<?php

namespace App\Services;

use App\Services\WorkerPool\BoundedPool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for compiling MJML templates to HTML.
 *
 * Uses the freegle-mjml container with BoundedPool for back pressure
 * when the compiler is under load.
 */
class MjmlCompilerService
{
    private BoundedPool $pool;

    public function __construct()
    {
        $this->pool = new BoundedPool(
            name: 'mjml',
            maxConcurrency: config('pools.mjml.max', 20),
            timeoutSeconds: config('pools.mjml.timeout', 30),
            sentryThrottleSeconds: config('pools.mjml.sentry_throttle', 300)
        );
        $this->pool->initialize();
    }

    /**
     * Compile MJML to HTML.
     * Blocks if all workers are busy (back pressure).
     */
    public function compile(string $mjml): string
    {
        return $this->pool->withPermit(function () use ($mjml) {
            $url = config('services.mjml.url');

            $response = Http::timeout(config('services.mjml.http_timeout', 30))
                ->post($url, ['mjml' => $mjml]);

            if (!$response->successful()) {
                Log::error('MJML compilation failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException(
                    'MJML compilation failed: ' . $response->body()
                );
            }

            $html = $response->json()['html'] ?? '';

            if (empty(trim($html))) {
                throw new \RuntimeException('MJML compilation returned empty HTML');
            }

            return $html;
        });
    }

    /**
     * Get pool statistics for monitoring.
     */
    public function getPoolStats(): array
    {
        return $this->pool->getStats();
    }
}
```

## Configuration

### config/pools.php

```php
<?php

return [
    'mjml' => [
        'max' => env('POOL_MJML_MAX', 20),
        'timeout' => env('POOL_MJML_TIMEOUT', 30),
        'sentry_throttle' => env('POOL_MJML_SENTRY_THROTTLE', 300),
    ],

    'digest' => [
        'max' => env('POOL_DIGEST_MAX', 10),
        'timeout' => env('POOL_DIGEST_TIMEOUT', 0),  // Block forever
        'sentry_throttle' => env('POOL_DIGEST_SENTRY_THROTTLE', 300),
    ],
];
```

### config/services.php

```php
'mjml' => [
    // MJML server URL (freegle-mjml container)
    'url' => env('MJML_URL', 'http://mjml:3000/v1/render'),

    // HTTP request timeout in seconds
    'http_timeout' => env('MJML_HTTP_TIMEOUT', 30),
],
```

## Docker Compose Configuration

### docker-compose.yml (batch profile)

```yaml
version: '3.8'

services:
  # Redis for semaphores and caching
  redis:
    image: redis:7-alpine
    profiles: [batch]
    volumes:
      - redis-data:/data
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

  # MJML compilation server
  mjml:
    image: adrianrudnik/mjml-server:latest
    profiles: [batch]
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "wget", "-q", "--spider", "http://localhost:3000/health"]
      interval: 30s
      timeout: 10s
      retries: 3

  # Git sync for code updates
  git-sync:
    image: registry.k8s.io/git-sync/git-sync:v4.2.1
    profiles: [batch]
    volumes:
      - code:/app
      - git-ssh:/etc/git-secret:ro
    environment:
      - GITSYNC_REPO=git@github.com:Freegle/iznik-batch.git
      - GITSYNC_BRANCH=production
      - GITSYNC_ROOT=/app
      - GITSYNC_PERIOD=60s
      - GITSYNC_ONE_TIME=false
    restart: unless-stopped

  # Main batch application
  batch:
    build: .
    profiles: [batch]
    depends_on:
      redis:
        condition: service_healthy
      mjml:
        condition: service_healthy
    volumes:
      - code:/app:ro  # Code mounted read-only
      - spool-data:/app/storage/spool
      - batch-logs:/app/storage/logs
    environment:
      - REDIS_HOST=redis
      - MJML_URL=http://mjml:3000/v1/render
      - DB_HOST=${DB_HOST}  # External database
      - DB_DATABASE=${DB_DATABASE}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
    restart: unless-stopped

volumes:
  redis-data:
  spool-data:
  batch-logs:
  code:
  git-ssh:
```

### Production Startup

```bash
# Start only batch services (not full Freegle stack)
docker-compose --profile batch up -d

# View logs
docker-compose --profile batch logs -f

# Manual refresh after deployment
docker-compose exec batch php artisan deploy:refresh
```

## Scaling Strategy

### MJML Workers

The MJML server uses Node.js which is single-threaded but highly efficient for I/O:

- Node event loop handles concurrent HTTP requests well
- BoundedPool limits concurrent compilations to `POOL_MJML_MAX`
- If more throughput needed, increase `POOL_MJML_MAX` (not more containers)
- Node can handle 20+ concurrent MJML compilations easily

### Digest Workers

For CPU-bound digest generation:

```yaml
# In production, scale digest workers based on CPU
batch-digest:
  build: .
  profiles: [batch]
  command: php artisan mail:digest:unified --daemon
  deploy:
    replicas: ${DIGEST_WORKERS:-4}  # Scale based on CPU cores
```

Or use Supervisor inside the container:

```ini
[program:digest-worker]
command=php /app/artisan mail:digest:unified --daemon
numprocs=4
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
```

## Monitoring

### Artisan Command for Pool Status

```php
<?php

namespace App\Console\Commands;

use App\Services\MjmlCompilerService;
use Illuminate\Console\Command;

class PoolStatusCommand extends Command
{
    protected $signature = 'pool:status';
    protected $description = 'Show worker pool status';

    public function handle(MjmlCompilerService $mjml): int
    {
        $stats = $mjml->getPoolStats();

        $this->table(
            ['Pool', 'Max', 'Available', 'In Use', 'Timeouts'],
            [[
                $stats['name'],
                $stats['max_concurrency'],
                $stats['available'],
                $stats['in_use'],
                $stats['timeouts'],
            ]]
        );

        return Command::SUCCESS;
    }
}
```

## Security Considerations

1. **Network isolation** - Redis and MJML only accessible within Docker network
2. **No external ports** - Batch services don't need external access
3. **SSH key for git** - Use deploy keys, not personal tokens
4. **Read-only code mount** - Prevent container from modifying code

## Migration Path

1. **Phase 1**: Implement BoundedPool and MjmlCompilerService locally
2. **Phase 2**: Test with Docker Compose on dev environment
3. **Phase 3**: Set up production branch auto-merge in CI
4. **Phase 4**: Deploy Docker Compose to production server
5. **Phase 5**: Switch from "naked" install to containerized

## References

- [Redis BLPOP Documentation](https://redis.io/commands/blpop/)
- [Docker Compose Profiles](https://docs.docker.com/compose/profiles/)
- [git-sync Container](https://github.com/kubernetes-sigs/git-sync)
- [adrianrudnik/mjml-server](https://github.com/adrianrudnik/mjml-server)
- [Watchtower Alternatives 2025](https://github.com/nocodb/nocodb/issues/12668)

Sources:
- [containrrr/watchtower](https://github.com/containrrr/watchtower) - Original but now outdated
- [Better Stack Watchtower Guide](https://betterstack.com/community/guides/scaling-docker/watchtower-docker/)
- [Dokploy Blog](https://dokploy.com/blog/how-to-deploy-apps-with-docker-compose-in-2025)
- [Automated Docker Compose Deployment](https://ecostack.dev/posts/automated-docker-compose-deployment-github-actions/)
