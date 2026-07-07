# iznik-batch

**Work in Progress**

This is a Laravel-based replacement for the bulk/batch PHP code in `iznik-server`. It handles background tasks like email notifications, digests, and data cleanup that previously ran as cron jobs or background scripts.

## Status

This project is under active development. The goal is to migrate batch processing from the legacy PHP codebase to a modern Laravel application with proper testing, queue support, and maintainability.

## What This Replaces

The batch jobs in `iznik-server/scripts/cron/` and similar bulk operations are being reimplemented here using:
- Laravel's queue system for job processing
- Eloquent models mapped to the existing `iznik` database schema
- MJML templates for email rendering
- PHPUnit tests to ensure correctness

## Architecture

- **Laravel 12** application with PHP 8.3+
- Connects to two databases:
  - `iznik_laravel` - Laravel's own database for testing
  - `iznik` - Read-only access to main Freegle database
- Container name: `freegle-batch`

## Getting Started

See `CLAUDE.md` for detailed documentation on:
- Configuration options
- Running tests
- Code style guidelines
- Container commands

## Deploying to Live Servers

For SFTP/SCP deployments (non-Docker environments):

1. **Upload the code** to the server via SFTP/SCP
2. **Wait for automatic refresh** - The `deploy:watch` command runs every minute via Laravel's scheduler. When it detects `version.txt` has changed, it waits 5 minutes (settle time) for uploads to complete, then automatically runs `deploy:refresh`
3. **Or trigger manually** if needed:
   ```bash
   php artisan deploy:refresh
   ```

### What deploy:refresh does

- Clears configuration cache
- Clears route cache
- Clears compiled views
- Recompiles views
- Restarts supervisor-managed daemons gracefully

### For Docker environments

In Docker Compose setups (like FreegleDocker), the container handles deployment automatically. Code changes are synced to the container, and you can trigger a refresh with:

```bash
docker exec freegle-batch php artisan deploy:refresh
```

### Bootstrap cache files

The `bootstrap/cache/services.php` and `packages.php` files are generated during deployment and should not be modified manually. If you encounter issues with these files, running `deploy:refresh` will regenerate them.

## License

Part of the Freegle project.
