# User Backup Index & Self-Service Restore

## Problem

When a support user needs to restore a deleted/forgotten user from a backup, they face two problems:

1. **Blind search**: No way to know which backup contains the user in the desired state without restoring it (1-2 hours per attempt).
2. **No self-service**: The process requires developer intervention — support users can't do it from ModTools.

## Solution Overview

Build a **user snapshot index** that captures metadata from each nightly backup restoration, and expose it via the yesterday-api so ModTools support users can browse backup history and request restores without developer help.

### Components

1. **Snapshot indexer** — Laravel artisan command that extracts user metadata after each nightly restore
2. **SQLite index** — Lightweight database on a persistent volume surviving MySQL restores
3. **Yesterday-api endpoints** — Public endpoints for querying the index and submitting signed restore requests
4. **Job queue** — Yesterday-api manages sequential restore processing
5. **ModTools UI** — Support interface for lookup and restore requests

## 1. Snapshot Index

### When it runs

After each nightly backup restoration, `restore-backup.sh` calls:

```bash
php artisan user:index-snapshot --backup-date=2026-03-29
```

### What it captures

Users with `lastaccess` within the last 90 days from the currently restored backup.

### Storage

SQLite file at `/data/user-snapshots.db` on a host-mounted volume separate from the MySQL data volume.

### Schema

```sql
CREATE TABLE snapshots (
    backup_date TEXT NOT NULL,       -- '2026-03-29'
    userid INTEGER NOT NULL,
    email TEXT,                       -- primary email from users_emails
    fullname TEXT,
    deleted TEXT,                     -- ISO timestamp or NULL
    forgotten TEXT,                   -- ISO timestamp or NULL
    lastaccess TEXT,                  -- ISO timestamp
    engagement TEXT,                  -- New/Occasional/Frequent/Obsessed/Inactive/Dormant
    systemrole TEXT,                  -- User/Moderator/Support/Admin
    message_count INTEGER,
    chat_count INTEGER,
    membership_count INTEGER,
    PRIMARY KEY (backup_date, userid)
);

CREATE INDEX idx_snapshots_email ON snapshots(email);
CREATE INDEX idx_snapshots_fullname ON snapshots(fullname);
```

### Retention

Snapshots older than 90 days are pruned during each indexing run. This matches the GCS backup retention and the "sometimes weeks" restore window.

### Performance

With ~90 days of snapshots and only recently-active users indexed, the database stays small (tens of MB). Queries by email are indexed and fast.

## 2. Yesterday-API Endpoints

### Existing public endpoint pattern

The 2FA gateway already exposes `/api/restore-status` and `/api/current-backup` as unauthenticated public endpoints. New endpoints follow this pattern.

### New endpoints

#### `GET /api/user-snapshots?email=<email>`

Returns the snapshot history for a user across all indexed backup dates.

**Response:**
```json
{
  "user": {
    "userid": 12345,
    "fullname": "Jane Smith",
    "email": "jane@example.com"
  },
  "snapshots": [
    {
      "backup_date": "2026-03-29",
      "deleted": "2026-03-28T14:30:00Z",
      "forgotten": null,
      "lastaccess": "2026-03-27T09:15:00Z",
      "engagement": "Frequent",
      "message_count": 45,
      "chat_count": 12,
      "membership_count": 3
    },
    {
      "backup_date": "2026-03-27",
      "deleted": null,
      "forgotten": null,
      "lastaccess": "2026-03-27T09:15:00Z",
      "engagement": "Frequent",
      "message_count": 45,
      "chat_count": 12,
      "membership_count": 3
    }
  ],
  "recommended_restore_date": "2026-03-27"
}
```

The `recommended_restore_date` is the most recent backup where the user was not deleted and not forgotten.

#### `POST /api/restore-request`

Submits a signed restore request.

**Request body:**
```json
{
  "email": "jane@example.com",
  "backup_date": "2026-03-27",
  "requested_by": {
    "userid": 999,
    "displayname": "Support Person",
    "systemrole": "Support"
  },
  "timestamp": "2026-03-30T10:00:00Z",
  "signature": "<HMAC-SHA256 of canonical request>"
}
```

**Signature verification:**
- HMAC-SHA256 using a shared secret configured on both ModTools backend and yesterday-api
- Canonical string: `email|backup_date|requested_by.userid|requested_by.systemrole|timestamp`
- Requests older than 5 minutes are rejected (replay protection)
- Only `systemrole` of Support or Admin is accepted

**Response:**
```json
{
  "job_id": "abc123",
  "status": "queued",
  "position": 2
}
```

#### `GET /api/restore-request/:job_id`

Returns status of a restore job.

**Response:**
```json
{
  "job_id": "abc123",
  "status": "in_progress",
  "backup_date": "2026-03-27",
  "email": "jane@example.com",
  "requested_by": "Support Person",
  "requested_at": "2026-03-30T10:00:00Z",
  "started_at": "2026-03-30T10:05:00Z",
  "progress": "Restoring backup...",
  "completed_at": null,
  "result_url": null
}
```

When complete, the dump file is available for download via `GET /api/restore-request/:job_id/dump`.

#### `GET /api/restore-requests`

Lists recent/active restore requests (for the ModTools queue view). Authenticated via signature on query params.

## 3. Job Queue

The yesterday-api manages the queue internally (in-memory + persisted to SQLite).

### Queue table (in user-snapshots.db)

```sql
CREATE TABLE restore_jobs (
    job_id TEXT PRIMARY KEY,
    email TEXT NOT NULL,
    backup_date TEXT NOT NULL,
    requested_by_userid INTEGER NOT NULL,
    requested_by_name TEXT,
    requested_by_role TEXT NOT NULL,
    requested_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',  -- queued/loading_backup/dumping_user/restoring_user/completed/failed
    started_at TEXT,
    completed_at TEXT,
    progress TEXT,
    error TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
```

### Queue logic

1. **Combining**: If two requests need the same `backup_date`, they share a single backup restore. The backup is loaded once, then both users are dumped.
2. **Current backup optimization**: If the requested `backup_date` matches the currently loaded backup, skip straight to the dump step.
3. **Sequential processing**: Only one backup restore runs at a time. Dump operations can run concurrently against the same loaded backup.
4. **Cancellation**: A pending job can be cancelled via `DELETE /api/restore-request/:job_id` (signed).
5. **Requeue on nightly restore**: If a nightly auto-restore begins while a job is in progress, the job is marked as failed and requeued for after the nightly restore completes.

### Job execution steps

1. **Load backup** (if different from current): Call `restore-backup.sh` with the target date. ~1-2 hours.
2. **Dump user**: Run `php artisan user:dump --email=<email> --output=/data/dumps/<job_id>.json`. Seconds.
3. **Restore user to live**: Run `php artisan user:restore --input=/data/dumps/<job_id>.json` against the live database. This requires the batch container to have live DB connectivity — or the dump file is transferred to the live system and the command runs there.
4. **Mark complete**: Update job status, clean up dump file.

### Live restore execution

Once the dump is ready, ModTools backend downloads it from yesterday-api via `GET /api/restore-request/:job_id/dump` and runs `php artisan user:restore` locally via the batch container. This keeps yesterday read-only with respect to the live database — it never needs live DB credentials.

## 4. Authentication Flow

### Shared secret

A single HMAC secret is configured in:
- ModTools backend (environment variable `YESTERDAY_API_SECRET`)
- Yesterday-api (environment variable `MODTOOLS_API_SECRET`)

### Request signing

ModTools backend constructs the canonical string from request parameters, computes HMAC-SHA256, and includes the signature in the request. Yesterday-api recomputes and compares.

### Authorization

Yesterday-api checks:
1. Signature is valid (shared secret)
2. Timestamp is within 5 minutes (replay protection)
3. `systemrole` is Support or Admin
4. Rate limit: max 10 requests per hour per requesting user

### No user sessions on yesterday

Yesterday does not maintain sessions for ModTools users. Each request is independently verified via its signature. This is stateless and simple.

## 5. ModTools UI

A new section in ModTools support tools (accessible to Support/Admin only):

### User Backup Lookup

- Search by email address
- Shows table of backup dates with user state at each point
- Highlights recommended restore point (most recent non-deleted, non-forgotten)
- Shows message count, chat count, group count to help pick the right point

### Restore Request

- "Restore from this backup" button on each row
- Confirmation dialog showing what will happen
- Submits signed request to yesterday-api

### Queue Status

- Shows active and recent restore requests
- Progress indicator for in-progress jobs
- "Download dump" / "Apply restore" when complete
- Cancel button for queued jobs

## 6. Integration with restore-backup.sh

Add to the post-restore section of `restore-backup.sh`:

```bash
# Index user snapshots after successful restore
echo "Indexing user snapshots..."
docker exec batch php artisan user:index-snapshot --backup-date="$BACKUP_DATE"
echo "User snapshot indexing complete."
```

The nightly restore must also:
- Check for queued restore jobs before starting
- Notify the queue that the nightly restore is beginning (so jobs can be paused/requeued)
- After completion, check if any queued jobs can now be served from the freshly loaded backup

## 7. Error Handling

- **Index failure**: Non-fatal. Log and continue. Missing snapshots are just gaps in history.
- **Signature invalid**: Return 403. Log the attempt.
- **Backup not available in GCS**: Return 404 with message. Support user sees "Backup not available."
- **Restore fails mid-way**: Mark job as failed with error message. Support user can retry.
- **User not found in backup**: Mark job as failed. "User not found in backup for this date."
- **Nightly restore interrupts job**: Requeue with priority.

## 8. Testing

- **Snapshot indexer**: Unit test creating test users with various states, verifying correct extraction to SQLite
- **API endpoints**: Integration tests with signed requests, signature rejection, rate limiting
- **Queue logic**: Unit tests for combining, cancellation, current-backup optimization
- **End-to-end**: Test the full flow from lookup to dump file generation (restore to live tested separately)
