# iznik-batch (Laravel Batch Jobs Processor)

This is the Laravel-based batch job processor for Freegle. It handles background tasks like email notifications, digests, and data cleanup.

## Architecture

- **Laravel 12** application with PHP 8.3+
- Uses the main `iznik` database directly (same as iznik-server)
- PHPUnit tests use `iznik_batch_test` database
- Uses MJML for email templates via the freegle-mjml HTTP server
- Container name: `freegle-batch`

## Configuration

All domain-specific configuration is in `config/freegle.php` and can be overridden via environment variables:

- `FREEGLE_USER_SITE` - User-facing website URL (default: https://www.ilovefreegle.org)
- `FREEGLE_MOD_SITE` - Moderator tools URL (default: https://modtools.org)
- `FREEGLE_SITE_NAME` - Site name for branding (default: Freegle)
- `FREEGLE_LOGO_URL` - Logo image URL
- `FREEGLE_WALLPAPER_URL` - Email header background image
- `FREEGLE_SRID` - Geospatial SRID (default: 3857, Web Mercator)

## Running Tests

```bash
# Run all unit and feature tests (uses iznik_test database)
docker exec freegle-batch php artisan test --testsuite=Unit,Feature

# Run integration tests (requires Mailpit)
docker exec freegle-batch php artisan test --testsuite=Integration

# Run specific test
docker exec freegle-batch php artisan test --filter="test_method_name"
```

## Test Suites

- **Unit** - Unit tests using iznik_batch_test database
- **Feature** - Feature tests using iznik_batch_test database
- **Integration** - Tests that require external services (Mailpit)

## Database Migrations

Migrations are generated from the existing `iznik` database schema using `kitloong/laravel-migrations-generator`:

```bash
# Re-generate migrations from iznik database (if schema changes)
docker exec freegle-batch php artisan migrate:generate
```

## Code Style

- Follow Laravel conventions
- Use `config()` helper for all configurable values - never hardcode domains or magic numbers
- Use MJML templates in `resources/views/emails/mjml/`
- All Mailable classes must extend `MjmlMailable` and implement `getSubject()`
- Whenever possible, replace DB facade query builder code with equivalent Eloquent model usage. Use the class-based methods like setting properties and calling `save()` on the models to trigger model events like `saving`, `saved`, `updating`, and `updated` since these events need to fire for Laravel Auditing to work

## Email Guidelines

**IMPORTANT:** Before migrating any email from iznik-server, read [EMAIL-MIGRATION-GUIDE.md](./EMAIL-MIGRATION-GUIDE.md) for lessons learned from previous migrations including common mistakes to avoid.

- **Never use base64 data URIs in emails** - Gmail and most email clients strip them for security reasons. Use hosted image URLs instead.
- Email images should be hosted on a CDN or web server and referenced via HTTPS URLs.
- Configure image URLs in `config/freegle.php` under the `images` key.

## AMP Email Restrictions

AMP for Email has strict validation requirements. When editing AMP templates in `resources/views/emails/amp/`:

- **Forbidden CSS properties**: `pointer-events`, `filter`, `clip-path`, and many others. See [AMP Email CSS spec](https://amp.dev/documentation/guides-and-tutorials/learn/email-spec/amp-email-css/).
- **No external stylesheets** - All CSS must be inline in `<style amp-custom>`.
- **No JavaScript** - Only AMP components are allowed.
- **Image restrictions** - Must use `<amp-img>` not `<img>`.
- **Form restrictions** - Must use `<amp-form>` with specific action-xhr endpoints.

Validate AMP HTML at: https://amp.gmail.dev/playground/

The `mail:test` command saves AMP HTML to `/tmp/amp-email-*.html` for validation testing.

## Email Tracking Headers

All emails extending `MjmlMailable` automatically include these headers for tracking and debugging:

- **`X-Freegle-Trace-Id`** - Unique ID for correlating email with Loki logs. Format: `freegle-{timestamp}_{random}`.
- **`X-Freegle-Email-Type`** - The mailable class name (e.g., `ChatNotification`, `WelcomeMail`).
- **`X-Freegle-Timestamp`** - ISO 8601 timestamp when the email was created.
- **`X-Freegle-User-Id`** - Recipient's Freegle user ID (if available). Enables support tool lookups.

These headers are also logged when spooling and sending, enabling searches like:

- Find all emails sent to a specific user (by user ID)
- Trace an email's journey from creation to delivery (by trace ID)
- Filter emails by type in dashboards

To add user ID tracking to a new mailable, override `getRecipientUserId()`:

```php
protected function getRecipientUserId(): ?int
{
    return $this->user->id ?? null;
}
```

## Email Spooler

Emails are sent via a file-based spooler (`EmailSpoolerService`) for resilience. The spooler uses a "capturing transport" design that guarantees ALL headers survive spooling:

1. When spooling, the mailable is run through Laravel's complete mail pipeline.
2. A custom transport intercepts the fully-built Symfony Email (with all headers).
3. The complete message is serialized to JSON in the spool directory.
4. When processing the spool, headers are re-applied to the outgoing message.

This means any header added via `withSymfonyMessage()` callbacks automatically survives through the spool - no special handling needed for new headers.

Spool directories:

- `storage/spool/mail/pending/` - Queued for sending
- `storage/spool/mail/sending/` - Currently being sent
- `storage/spool/mail/sent/` - Successfully sent (cleaned up after 7 days)
- `storage/spool/mail/failed/` - Failed permanently (manual retry available)

## Reference Material

When implementing services, check the PHPUnit tests in `iznik-server/test/ut/php` for the original business logic and schema usage. This helps understand the intention of the original code.

## Migration Rules

**CRITICAL: Tests are specifications, not suggestions.**

When migrating code from iznik-server to this Laravel application:

1. **Never change test assertions to make tests pass.** When a test fails, the test is telling you what the code _should_ do. The implementation must be fixed to match the test, not the other way around.

2. **Always verify against iznik-server first.** Before changing any constant, enum value, or business logic, grep `iznik-server` to see what the original values/behavior are. The source of truth is always the PHP code in iznik-server, not database introspection or generated migrations.

3. **Fix the source, not the symptom.** If a migration is missing an enum value that iznik-server defines, fix the migration - don't remove the constant from the model.

4. **Red flag: changing test expectations.** Any time you're about to change what a test _expects_ (not how it sets up data), stop and verify the expected behavior against iznik-server before proceeding.

5. **Document discrepancies.** If you find the database schema differs from iznik-server constants, document it and fix the migration rather than silently changing behavior.

See `MIGRATION-STATUS.md` for progress tracking on migrating cron scripts.

## Container Commands

```bash
# Rebuild container after Dockerfile changes
docker-compose build batch && docker-compose up -d batch

# View logs
docker logs freegle-batch

# Run artisan commands
docker exec freegle-batch php artisan <command>
```

## Deployment Commands

The application includes automatic deployment detection for environments where code is uploaded via SFTP/SCP rather than git.

### How It Works

1. **Version File**: A `version.txt` file is updated on each commit via GitHub Actions, containing an incrementing version number and commit info.

2. **Automatic Detection**: The `deploy:watch` command runs every minute via the scheduler. It compares the current `version.txt` to the last deployed version stored in cache.

3. **Settle Time**: When a version change is detected, the system waits 5 minutes to ensure file uploads are complete before triggering a refresh.

4. **Graceful Restart**: Daemons use signal handlers (SIGTERM/SIGINT) to shut down gracefully at safe points in their processing loops.

### Commands

```bash
# Manually refresh the application after deployment
docker exec freegle-batch php artisan deploy:refresh

# Force a refresh check (bypass version comparison)
docker exec freegle-batch php artisan deploy:watch --force

# Check deployment status
docker exec freegle-batch php artisan deploy:watch
```

### Adding New Daemons

When adding new supervisor-managed daemons:

1. Add the program name to the `$supervisorPrograms` array in `RefreshCommand.php`
2. Use the `GracefulShutdown` trait in your command for graceful shutdown support
3. Call `$this->registerShutdownHandlers()` at the start of your daemon loop
4. Check `$this->shouldStop()` at safe points in your loop
