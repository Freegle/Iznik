# User Backup Index & Self-Service Restore — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let ModTools support users browse user state across backup dates and request self-service restores via the yesterday-api, backed by a SQLite snapshot index built nightly.

**Architecture:** A Laravel artisan command (`user:index-snapshot`) runs after each nightly backup restore, extracting recently-active user metadata into a SQLite file on a persistent volume. The yesterday-api (Node.js/Express) exposes new endpoints for querying snapshots and submitting HMAC-signed restore requests. A job queue in the API processes restores sequentially, combining requests for the same backup date. ModTools downloads completed dump files and runs `user:restore` locally.

**Tech Stack:** Laravel 12 (artisan command, PDO SQLite), Node.js/Express (yesterday-api), better-sqlite3 (Node.js SQLite driver), HMAC-SHA256 (request signing), existing `user:dump`/`user:restore` commands.

**Spec:** `docs/superpowers/specs/2026-03-30-user-backup-index-design.md`

---

## File Structure

### New files

| File | Responsibility |
|------|---------------|
| `iznik-batch/app/Console/Commands/User/IndexSnapshotCommand.php` | Artisan command: extract user metadata from MySQL into SQLite |
| `iznik-batch/tests/Feature/User/IndexSnapshotCommandTest.php` | Tests for the indexer |
| `yesterday/api/lib/snapshot-db.js` | SQLite database wrapper: schema init, query, insert, prune |
| `yesterday/api/lib/job-queue.js` | Job queue: create, combine, process, cancel, status |
| `yesterday/api/lib/auth.js` | HMAC signature verification + rate limiting |
| `yesterday/api/routes/snapshots.js` | Express routes for `/api/user-snapshots` |
| `yesterday/api/routes/restore-requests.js` | Express routes for `/api/restore-request*` |
| `yesterday/api/test/snapshot-db.test.js` | Tests for SQLite wrapper |
| `yesterday/api/test/job-queue.test.js` | Tests for queue logic |
| `yesterday/api/test/auth.test.js` | Tests for HMAC verification |

### Modified files

| File | Change |
|------|--------|
| `yesterday/api/server.js` | Mount new route modules |
| `yesterday/api/package.json` | Add `better-sqlite3` dependency |
| `yesterday/2fa-gateway/server.js` | Add public endpoint whitelist entries for new routes |
| `yesterday/scripts/restore-backup.sh` | Add post-restore indexing hook |
| `yesterday/docker-compose.yesterday-services.yml` | Ensure `/data` volume persists (already does) |

---

## Task 1: SQLite Snapshot Database Wrapper (Node.js)

**Files:**
- Create: `yesterday/api/lib/snapshot-db.js`
- Create: `yesterday/api/test/snapshot-db.test.js`
- Modify: `yesterday/api/package.json`

- [ ] **Step 1: Install better-sqlite3 and test runner**

```bash
cd /home/edward/FreegleDockerWSL/yesterday/api
npm install better-sqlite3
npm install --save-dev jest
```

Add to `package.json` scripts:
```json
"test": "jest --verbose"
```

- [ ] **Step 2: Write failing test for schema initialization**

Create `yesterday/api/test/snapshot-db.test.js`:

```javascript
const path = require('path');
const fs = require('fs');
const os = require('os');
const SnapshotDb = require('../lib/snapshot-db');

describe('SnapshotDb', () => {
  let dbPath;
  let db;

  beforeEach(() => {
    dbPath = path.join(os.tmpdir(), `test-snapshots-${Date.now()}.db`);
    db = new SnapshotDb(dbPath);
  });

  afterEach(() => {
    db.close();
    if (fs.existsSync(dbPath)) fs.unlinkSync(dbPath);
  });

  test('creates snapshots and restore_jobs tables on init', () => {
    const tables = db.db.prepare(
      "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
    ).all().map(r => r.name);
    expect(tables).toContain('snapshots');
    expect(tables).toContain('restore_jobs');
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

```bash
cd /home/edward/FreegleDockerWSL/yesterday/api
npx jest test/snapshot-db.test.js --verbose
```

Expected: FAIL — `Cannot find module '../lib/snapshot-db'`

- [ ] **Step 4: Write minimal SnapshotDb class**

Create `yesterday/api/lib/snapshot-db.js`:

```javascript
const Database = require('better-sqlite3');

class SnapshotDb {
  constructor(dbPath) {
    this.db = new Database(dbPath);
    this.db.pragma('journal_mode = WAL');
    this._initSchema();
  }

  _initSchema() {
    this.db.exec(`
      CREATE TABLE IF NOT EXISTS snapshots (
        backup_date TEXT NOT NULL,
        userid INTEGER NOT NULL,
        email TEXT,
        fullname TEXT,
        deleted TEXT,
        forgotten TEXT,
        lastaccess TEXT,
        engagement TEXT,
        systemrole TEXT,
        message_count INTEGER,
        chat_count INTEGER,
        membership_count INTEGER,
        PRIMARY KEY (backup_date, userid)
      );

      CREATE INDEX IF NOT EXISTS idx_snapshots_email ON snapshots(email);
      CREATE INDEX IF NOT EXISTS idx_snapshots_fullname ON snapshots(fullname);

      CREATE TABLE IF NOT EXISTS restore_jobs (
        job_id TEXT PRIMARY KEY,
        email TEXT NOT NULL,
        backup_date TEXT NOT NULL,
        requested_by_userid INTEGER NOT NULL,
        requested_by_name TEXT,
        requested_by_role TEXT NOT NULL,
        requested_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'queued',
        started_at TEXT,
        completed_at TEXT,
        progress TEXT,
        error TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
    `);
  }

  close() {
    this.db.close();
  }
}

module.exports = SnapshotDb;
```

- [ ] **Step 5: Run test to verify it passes**

```bash
cd /home/edward/FreegleDockerWSL/yesterday/api
npx jest test/snapshot-db.test.js --verbose
```

Expected: PASS

- [ ] **Step 6: Write failing tests for snapshot insert and query**

Add to `test/snapshot-db.test.js`:

```javascript
  test('inserts and queries snapshots by email', () => {
    db.insertSnapshots('2026-03-29', [
      {
        userid: 123,
        email: 'jane@example.com',
        fullname: 'Jane Smith',
        deleted: null,
        forgotten: null,
        lastaccess: '2026-03-28T10:00:00Z',
        engagement: 'Frequent',
        systemrole: 'User',
        message_count: 45,
        chat_count: 12,
        membership_count: 3,
      },
    ]);

    const result = db.findByEmail('jane@example.com');
    expect(result).toHaveLength(1);
    expect(result[0].userid).toBe(123);
    expect(result[0].backup_date).toBe('2026-03-29');
    expect(result[0].message_count).toBe(45);
  });

  test('returns snapshots sorted by backup_date descending', () => {
    const user = {
      userid: 123,
      email: 'jane@example.com',
      fullname: 'Jane Smith',
      deleted: null,
      forgotten: null,
      lastaccess: '2026-03-28T10:00:00Z',
      engagement: 'Frequent',
      systemrole: 'User',
      message_count: 45,
      chat_count: 12,
      membership_count: 3,
    };

    db.insertSnapshots('2026-03-27', [user]);
    db.insertSnapshots('2026-03-29', [{ ...user, message_count: 50 }]);
    db.insertSnapshots('2026-03-28', [{ ...user, message_count: 47 }]);

    const result = db.findByEmail('jane@example.com');
    expect(result).toHaveLength(3);
    expect(result[0].backup_date).toBe('2026-03-29');
    expect(result[1].backup_date).toBe('2026-03-28');
    expect(result[2].backup_date).toBe('2026-03-27');
  });

  test('computes recommended restore date as most recent non-deleted non-forgotten', () => {
    const base = {
      userid: 123,
      email: 'jane@example.com',
      fullname: 'Jane Smith',
      forgotten: null,
      lastaccess: '2026-03-28T10:00:00Z',
      engagement: 'Frequent',
      systemrole: 'User',
      message_count: 45,
      chat_count: 12,
      membership_count: 3,
    };

    db.insertSnapshots('2026-03-27', [{ ...base, deleted: null }]);
    db.insertSnapshots('2026-03-28', [{ ...base, deleted: '2026-03-28T14:00:00Z' }]);
    db.insertSnapshots('2026-03-29', [{ ...base, deleted: '2026-03-28T14:00:00Z' }]);

    const recommended = db.getRecommendedRestoreDate('jane@example.com');
    expect(recommended).toBe('2026-03-27');
  });

  test('prunes snapshots older than retention days', () => {
    const user = {
      userid: 123,
      email: 'jane@example.com',
      fullname: 'Jane Smith',
      deleted: null,
      forgotten: null,
      lastaccess: '2026-01-01T10:00:00Z',
      engagement: 'Frequent',
      systemrole: 'User',
      message_count: 10,
      chat_count: 1,
      membership_count: 1,
    };

    db.insertSnapshots('2025-12-01', [user]);
    db.insertSnapshots('2026-03-29', [{ ...user, message_count: 50 }]);

    db.pruneOlderThan(90); // Prune snapshots > 90 days old from today

    const result = db.findByEmail('jane@example.com');
    expect(result).toHaveLength(1);
    expect(result[0].backup_date).toBe('2026-03-29');
  });
```

- [ ] **Step 7: Run tests to verify they fail**

```bash
cd /home/edward/FreegleDockerWSL/yesterday/api
npx jest test/snapshot-db.test.js --verbose
```

Expected: FAIL — `db.insertSnapshots is not a function`

- [ ] **Step 8: Implement insertSnapshots, findByEmail, getRecommendedRestoreDate, pruneOlderThan**

Add to `SnapshotDb` class in `yesterday/api/lib/snapshot-db.js`, before `close()`:

```javascript
  insertSnapshots(backupDate, users) {
    const stmt = this.db.prepare(`
      INSERT OR REPLACE INTO snapshots
        (backup_date, userid, email, fullname, deleted, forgotten,
         lastaccess, engagement, systemrole, message_count, chat_count, membership_count)
      VALUES
        (@backup_date, @userid, @email, @fullname, @deleted, @forgotten,
         @lastaccess, @engagement, @systemrole, @message_count, @chat_count, @membership_count)
    `);

    const insertMany = this.db.transaction((rows) => {
      for (const row of rows) {
        stmt.run({ backup_date: backupDate, ...row });
      }
    });

    insertMany(users);
  }

  findByEmail(email) {
    return this.db.prepare(`
      SELECT * FROM snapshots
      WHERE email = ?
      ORDER BY backup_date DESC
    `).all(email);
  }

  getRecommendedRestoreDate(email) {
    const row = this.db.prepare(`
      SELECT backup_date FROM snapshots
      WHERE email = ?
        AND deleted IS NULL
        AND forgotten IS NULL
      ORDER BY backup_date DESC
      LIMIT 1
    `).get(email);

    return row ? row.backup_date : null;
  }

  pruneOlderThan(days) {
    const cutoff = new Date();
    cutoff.setDate(cutoff.getDate() - days);
    const cutoffStr = cutoff.toISOString().slice(0, 10);

    this.db.prepare('DELETE FROM snapshots WHERE backup_date < ?').run(cutoffStr);
  }
```

- [ ] **Step 9: Run tests to verify they pass**

```bash
cd /home/edward/FreegleDockerWSL/yesterday/api
npx jest test/snapshot-db.test.js --verbose
```

Expected: PASS (all 5 tests)

- [ ] **Step 10: Commit**

```bash
cd /home/edward/FreegleDockerWSL
git add yesterday/api/lib/snapshot-db.js yesterday/api/test/snapshot-db.test.js yesterday/api/package.json yesterday/api/package-lock.json
git commit -m "feat(yesterday): add SQLite snapshot database wrapper with tests"
```

---

## Task 2: HMAC Auth Module (Node.js)

**Files:**
- Create: `yesterday/api/lib/auth.js`
- Create: `yesterday/api/test/auth.test.js`

- [ ] **Step 1: Write failing tests for signature verification**

Create `yesterday/api/test/auth.test.js`:

```javascript
const { createSignature, verifyRequest } = require('../lib/auth');

describe('auth', () => {
  const secret = 'test-secret-key-32-chars-long!!!';

  describe('createSignature', () => {
    test('produces consistent HMAC-SHA256 for same inputs', () => {
      const params = {
        email: 'jane@example.com',
        backup_date: '2026-03-27',
        userid: 999,
        systemrole: 'Support',
        timestamp: '2026-03-30T10:00:00Z',
      };

      const sig1 = createSignature(params, secret);
      const sig2 = createSignature(params, secret);
      expect(sig1).toBe(sig2);
      expect(sig1).toMatch(/^[a-f0-9]{64}$/);
    });

    test('produces different signature for different inputs', () => {
      const params1 = {
        email: 'jane@example.com',
        backup_date: '2026-03-27',
        userid: 999,
        systemrole: 'Support',
        timestamp: '2026-03-30T10:00:00Z',
      };
      const params2 = { ...params1, email: 'other@example.com' };

      expect(createSignature(params1, secret)).not.toBe(createSignature(params2, secret));
    });
  });

  describe('verifyRequest', () => {
    test('accepts valid signature within time window', () => {
      const now = new Date().toISOString();
      const params = {
        email: 'jane@example.com',
        backup_date: '2026-03-27',
        userid: 999,
        systemrole: 'Support',
        timestamp: now,
      };
      const signature = createSignature(params, secret);

      const result = verifyRequest({ ...params, signature }, secret);
      expect(result.valid).toBe(true);
    });

    test('rejects invalid signature', () => {
      const now = new Date().toISOString();
      const params = {
        email: 'jane@example.com',
        backup_date: '2026-03-27',
        userid: 999,
        systemrole: 'Support',
        timestamp: now,
      };

      const result = verifyRequest({ ...params, signature: 'bad' }, secret);
      expect(result.valid).toBe(false);
      expect(result.reason).toBe('invalid signature');
    });

    test('rejects expired timestamp (>5 minutes old)', () => {
      const old = new Date(Date.now() - 6 * 60 * 1000).toISOString();
      const params = {
        email: 'jane@example.com',
        backup_date: '2026-03-27',
        userid: 999,
        systemrole: 'Support',
        timestamp: old,
      };
      const signature = createSignature(params, secret);

      const result = verifyRequest({ ...params, signature }, secret);
      expect(result.valid).toBe(false);
      expect(result.reason).toBe('request expired');
    });

    test('rejects non-Support/Admin systemrole', () => {
      const now = new Date().toISOString();
      const params = {
        email: 'jane@example.com',
        backup_date: '2026-03-27',
        userid: 999,
        systemrole: 'Moderator',
        timestamp: now,
      };
      const signature = createSignature(params, secret);

      const result = verifyRequest({ ...params, signature }, secret);
      expect(result.valid).toBe(false);
      expect(result.reason).toBe('insufficient permissions');
    });

    test('accepts Admin systemrole', () => {
      const now = new Date().toISOString();
      const params = {
        email: 'jane@example.com',
        backup_date: '2026-03-27',
        userid: 999,
        systemrole: 'Admin',
        timestamp: now,
      };
      const signature = createSignature(params, secret);

      const result = verifyRequest({ ...params, signature }, secret);
      expect(result.valid).toBe(true);
    });
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /home/edward/FreegleDockerWSL/yesterday/api
npx jest test/auth.test.js --verbose
```

Expected: FAIL — `Cannot find module '../lib/auth'`

- [ ] **Step 3: Implement auth module**

Create `yesterday/api/lib/auth.js`:

```javascript
const crypto = require('crypto');

const ALLOWED_ROLES = ['Support', 'Admin'];
const MAX_AGE_MS = 5 * 60 * 1000; // 5 minutes

function createSignature(params, secret) {
  const canonical = [
    params.email,
    params.backup_date,
    params.userid,
    params.systemrole,
    params.timestamp,
  ].join('|');

  return crypto.createHmac('sha256', secret).update(canonical).digest('hex');
}

function verifyRequest(request, secret) {
  // Check role first (cheapest check)
  if (!ALLOWED_ROLES.includes(request.systemrole)) {
    return { valid: false, reason: 'insufficient permissions' };
  }

  // Check timestamp freshness
  const requestTime = new Date(request.timestamp).getTime();
  const age = Date.now() - requestTime;
  if (age > MAX_AGE_MS || age < -MAX_AGE_MS) {
    return { valid: false, reason: 'request expired' };
  }

  // Verify signature
  const expected = createSignature(
    {
      email: request.email,
      backup_date: request.backup_date,
      userid: request.userid,
      systemrole: request.systemrole,
      timestamp: request.timestamp,
    },
    secret
  );

  if (!crypto.timingSafeEqual(Buffer.from(request.signature, 'hex'), Buffer.from(expected, 'hex'))) {
    return { valid: false, reason: 'invalid signature' };
  }

  return { valid: true };
}

module.exports = { createSignature, verifyRequest };
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd /home/edward/FreegleDockerWSL/yesterday/api
npx jest test/auth.test.js --verbose
```

Expected: PASS (all 6 tests)

- [ ] **Step 5: Commit**

```bash
cd /home/edward/FreegleDockerWSL
git add yesterday/api/lib/auth.js yesterday/api/test/auth.test.js
git commit -m "feat(yesterday): add HMAC-SHA256 request signing and verification"
```

---

## Task 3: Job Queue Module (Node.js)

**Files:**
- Create: `yesterday/api/lib/job-queue.js`
- Create: `yesterday/api/test/job-queue.test.js`

- [ ] **Step 1: Write failing tests for job creation and querying**

Create `yesterday/api/test/job-queue.test.js`:

```javascript
const path = require('path');
const fs = require('fs');
const os = require('os');
const SnapshotDb = require('../lib/snapshot-db');
const JobQueue = require('../lib/job-queue');

describe('JobQueue', () => {
  let dbPath;
  let snapshotDb;
  let queue;

  beforeEach(() => {
    dbPath = path.join(os.tmpdir(), `test-queue-${Date.now()}.db`);
    snapshotDb = new SnapshotDb(dbPath);
    queue = new JobQueue(snapshotDb);
  });

  afterEach(() => {
    snapshotDb.close();
    if (fs.existsSync(dbPath)) fs.unlinkSync(dbPath);
  });

  test('creates a job and returns job_id', () => {
    const job = queue.createJob({
      email: 'jane@example.com',
      backup_date: '2026-03-27',
      requested_by_userid: 999,
      requested_by_name: 'Support Person',
      requested_by_role: 'Support',
    });

    expect(job.job_id).toBeDefined();
    expect(job.status).toBe('queued');
    expect(job.email).toBe('jane@example.com');
  });

  test('retrieves a job by job_id', () => {
    const created = queue.createJob({
      email: 'jane@example.com',
      backup_date: '2026-03-27',
      requested_by_userid: 999,
      requested_by_name: 'Support Person',
      requested_by_role: 'Support',
    });

    const found = queue.getJob(created.job_id);
    expect(found.email).toBe('jane@example.com');
    expect(found.backup_date).toBe('2026-03-27');
  });

  test('lists jobs ordered by creation time', () => {
    queue.createJob({
      email: 'a@example.com',
      backup_date: '2026-03-27',
      requested_by_userid: 1,
      requested_by_name: 'User A',
      requested_by_role: 'Support',
    });
    queue.createJob({
      email: 'b@example.com',
      backup_date: '2026-03-28',
      requested_by_userid: 2,
      requested_by_name: 'User B',
      requested_by_role: 'Admin',
    });

    const jobs = queue.listJobs();
    expect(jobs).toHaveLength(2);
  });

  test('cancels a queued job', () => {
    const job = queue.createJob({
      email: 'jane@example.com',
      backup_date: '2026-03-27',
      requested_by_userid: 999,
      requested_by_name: 'Support Person',
      requested_by_role: 'Support',
    });

    const cancelled = queue.cancelJob(job.job_id);
    expect(cancelled).toBe(true);

    const found = queue.getJob(job.job_id);
    expect(found).toBeNull();
  });

  test('cannot cancel a non-queued job', () => {
    const job = queue.createJob({
      email: 'jane@example.com',
      backup_date: '2026-03-27',
      requested_by_userid: 999,
      requested_by_name: 'Support Person',
      requested_by_role: 'Support',
    });

    queue.updateJobStatus(job.job_id, 'loading_backup', 'Loading backup...');

    const cancelled = queue.cancelJob(job.job_id);
    expect(cancelled).toBe(false);
  });

  test('getNextBatch returns jobs grouped by backup_date', () => {
    queue.createJob({
      email: 'a@example.com',
      backup_date: '2026-03-27',
      requested_by_userid: 1,
      requested_by_name: 'User A',
      requested_by_role: 'Support',
    });
    queue.createJob({
      email: 'b@example.com',
      backup_date: '2026-03-27',
      requested_by_userid: 2,
      requested_by_name: 'User B',
      requested_by_role: 'Support',
    });
    queue.createJob({
      email: 'c@example.com',
      backup_date: '2026-03-28',
      requested_by_userid: 3,
      requested_by_name: 'User C',
      requested_by_role: 'Support',
    });

    const batch = queue.getNextBatch();
    expect(batch.backup_date).toBe('2026-03-27');
    expect(batch.jobs).toHaveLength(2);
    expect(batch.jobs.map(j => j.email).sort()).toEqual(['a@example.com', 'b@example.com']);
  });

  test('getNextBatch prioritizes current backup date', () => {
    queue.createJob({
      email: 'a@example.com',
      backup_date: '2026-03-27',
      requested_by_userid: 1,
      requested_by_name: 'User A',
      requested_by_role: 'Support',
    });
    queue.createJob({
      email: 'b@example.com',
      backup_date: '2026-03-29',
      requested_by_userid: 2,
      requested_by_name: 'User B',
      requested_by_role: 'Support',
    });

    const batch = queue.getNextBatch('2026-03-29');
    expect(batch.backup_date).toBe('2026-03-29');
    expect(batch.jobs).toHaveLength(1);
  });

  test('getNextBatch returns null when no queued jobs', () => {
    const batch = queue.getNextBatch();
    expect(batch).toBeNull();
  });

  test('returns queue position for a job', () => {
    const job1 = queue.createJob({
      email: 'a@example.com',
      backup_date: '2026-03-27',
      requested_by_userid: 1,
      requested_by_name: 'User A',
      requested_by_role: 'Support',
    });
    const job2 = queue.createJob({
      email: 'b@example.com',
      backup_date: '2026-03-28',
      requested_by_userid: 2,
      requested_by_name: 'User B',
      requested_by_role: 'Support',
    });

    expect(queue.getPosition(job1.job_id)).toBe(1);
    expect(queue.getPosition(job2.job_id)).toBe(2);
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /home/edward/FreegleDockerWSL/yesterday/api
npx jest test/job-queue.test.js --verbose
```

Expected: FAIL — `Cannot find module '../lib/job-queue'`

- [ ] **Step 3: Implement JobQueue class**

Create `yesterday/api/lib/job-queue.js`:

```javascript
const crypto = require('crypto');

class JobQueue {
  constructor(snapshotDb) {
    this.db = snapshotDb.db;
  }

  createJob({ email, backup_date, requested_by_userid, requested_by_name, requested_by_role }) {
    const job_id = crypto.randomUUID();
    const requested_at = new Date().toISOString();

    this.db.prepare(`
      INSERT INTO restore_jobs
        (job_id, email, backup_date, requested_by_userid, requested_by_name, requested_by_role, requested_at, status)
      VALUES (?, ?, ?, ?, ?, ?, ?, 'queued')
    `).run(job_id, email, backup_date, requested_by_userid, requested_by_name, requested_by_role, requested_at);

    return { job_id, status: 'queued', email, backup_date, requested_at };
  }

  getJob(jobId) {
    return this.db.prepare('SELECT * FROM restore_jobs WHERE job_id = ? AND status != ?').get(jobId, 'cancelled') || null;
  }

  listJobs() {
    return this.db.prepare(
      "SELECT * FROM restore_jobs WHERE status != 'cancelled' ORDER BY created_at ASC"
    ).all();
  }

  cancelJob(jobId) {
    const job = this.db.prepare('SELECT status FROM restore_jobs WHERE job_id = ?').get(jobId);
    if (!job || job.status !== 'queued') return false;

    this.db.prepare("UPDATE restore_jobs SET status = 'cancelled' WHERE job_id = ?").run(jobId);
    return true;
  }

  updateJobStatus(jobId, status, progress) {
    const updates = { status, progress };
    if (status === 'loading_backup' || status === 'dumping_user') {
      updates.started_at = new Date().toISOString();
    }
    if (status === 'completed' || status === 'failed') {
      updates.completed_at = new Date().toISOString();
    }

    this.db.prepare(`
      UPDATE restore_jobs
      SET status = @status, progress = @progress,
          started_at = COALESCE(@started_at, started_at),
          completed_at = COALESCE(@completed_at, completed_at)
      WHERE job_id = @job_id
    `).run({ job_id: jobId, started_at: null, completed_at: null, ...updates });
  }

  getNextBatch(currentBackupDate) {
    // Prioritize jobs matching the currently loaded backup
    if (currentBackupDate) {
      const matching = this.db.prepare(
        "SELECT * FROM restore_jobs WHERE status = 'queued' AND backup_date = ? ORDER BY created_at ASC"
      ).all(currentBackupDate);

      if (matching.length > 0) {
        return { backup_date: currentBackupDate, jobs: matching };
      }
    }

    // Otherwise, pick the oldest queued job's backup_date and batch all jobs for it
    const oldest = this.db.prepare(
      "SELECT backup_date FROM restore_jobs WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1"
    ).get();

    if (!oldest) return null;

    const jobs = this.db.prepare(
      "SELECT * FROM restore_jobs WHERE status = 'queued' AND backup_date = ? ORDER BY created_at ASC"
    ).all(oldest.backup_date);

    return { backup_date: oldest.backup_date, jobs };
  }

  getPosition(jobId) {
    const jobs = this.db.prepare(
      "SELECT job_id FROM restore_jobs WHERE status = 'queued' ORDER BY created_at ASC"
    ).all();

    const idx = jobs.findIndex(j => j.job_id === jobId);
    return idx === -1 ? null : idx + 1;
  }
}

module.exports = JobQueue;
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd /home/edward/FreegleDockerWSL/yesterday/api
npx jest test/job-queue.test.js --verbose
```

Expected: PASS (all 8 tests)

- [ ] **Step 5: Commit**

```bash
cd /home/edward/FreegleDockerWSL
git add yesterday/api/lib/job-queue.js yesterday/api/test/job-queue.test.js
git commit -m "feat(yesterday): add restore job queue with batching and cancellation"
```

---

## Task 4: Snapshot API Routes (Node.js)

**Files:**
- Create: `yesterday/api/routes/snapshots.js`
- Modify: `yesterday/api/server.js`

- [ ] **Step 1: Create snapshot query route**

Create `yesterday/api/routes/snapshots.js`:

```javascript
const express = require('express');

function createSnapshotRoutes(snapshotDb) {
  const router = express.Router();

  router.get('/api/user-snapshots', (req, res) => {
    const { email } = req.query;

    if (!email) {
      return res.status(400).json({ error: 'email parameter is required' });
    }

    const snapshots = snapshotDb.findByEmail(email);

    if (snapshots.length === 0) {
      return res.json({ user: null, snapshots: [], recommended_restore_date: null });
    }

    const recommended = snapshotDb.getRecommendedRestoreDate(email);

    res.json({
      user: {
        userid: snapshots[0].userid,
        fullname: snapshots[0].fullname,
        email: snapshots[0].email,
      },
      snapshots: snapshots.map((s) => ({
        backup_date: s.backup_date,
        deleted: s.deleted,
        forgotten: s.forgotten,
        lastaccess: s.lastaccess,
        engagement: s.engagement,
        message_count: s.message_count,
        chat_count: s.chat_count,
        membership_count: s.membership_count,
      })),
      recommended_restore_date: recommended,
    });
  });

  return router;
}

module.exports = createSnapshotRoutes;
```

- [ ] **Step 2: Mount in server.js**

In `yesterday/api/server.js`, add near the top with other requires:

```javascript
const SnapshotDb = require('./lib/snapshot-db');
const createSnapshotRoutes = require('./routes/snapshots');
```

After the `app` is created and before existing routes, add:

```javascript
const snapshotDb = new SnapshotDb('/data/user-snapshots.db');
app.use(createSnapshotRoutes(snapshotDb));
```

- [ ] **Step 3: Commit**

```bash
cd /home/edward/FreegleDockerWSL
git add yesterday/api/routes/snapshots.js yesterday/api/server.js
git commit -m "feat(yesterday): add /api/user-snapshots query endpoint"
```

---

## Task 5: Restore Request API Routes (Node.js)

**Files:**
- Create: `yesterday/api/routes/restore-requests.js`
- Modify: `yesterday/api/server.js`

- [ ] **Step 1: Create restore request routes**

Create `yesterday/api/routes/restore-requests.js`:

```javascript
const express = require('express');
const { verifyRequest } = require('../lib/auth');

function createRestoreRoutes(jobQueue, secret) {
  const router = express.Router();

  // Rate limiting: track requests per userid
  const rateLimits = new Map();
  const RATE_LIMIT = 10;
  const RATE_WINDOW_MS = 60 * 60 * 1000; // 1 hour

  function checkRateLimit(userid) {
    const now = Date.now();
    const entry = rateLimits.get(userid);
    if (!entry || now - entry.windowStart > RATE_WINDOW_MS) {
      rateLimits.set(userid, { windowStart: now, count: 1 });
      return true;
    }
    if (entry.count >= RATE_LIMIT) return false;
    entry.count++;
    return true;
  }

  router.post('/api/restore-request', express.json(), (req, res) => {
    const { email, backup_date, requested_by, timestamp, signature } = req.body;

    if (!email || !backup_date || !requested_by || !timestamp || !signature) {
      return res.status(400).json({ error: 'Missing required fields' });
    }

    const authResult = verifyRequest(
      {
        email,
        backup_date,
        userid: requested_by.userid,
        systemrole: requested_by.systemrole,
        timestamp,
        signature,
      },
      secret
    );

    if (!authResult.valid) {
      return res.status(403).json({ error: authResult.reason });
    }

    if (!checkRateLimit(requested_by.userid)) {
      return res.status(429).json({ error: 'Rate limit exceeded (10 requests/hour)' });
    }

    const job = jobQueue.createJob({
      email,
      backup_date,
      requested_by_userid: requested_by.userid,
      requested_by_name: requested_by.displayname,
      requested_by_role: requested_by.systemrole,
    });

    const position = jobQueue.getPosition(job.job_id);

    res.status(201).json({
      job_id: job.job_id,
      status: job.status,
      position,
    });
  });

  router.get('/api/restore-request/:jobId', (req, res) => {
    const job = jobQueue.getJob(req.params.jobId);
    if (!job) {
      return res.status(404).json({ error: 'Job not found' });
    }

    res.json({
      job_id: job.job_id,
      status: job.status,
      backup_date: job.backup_date,
      email: job.email,
      requested_by: job.requested_by_name,
      requested_at: job.requested_at,
      started_at: job.started_at,
      progress: job.progress,
      completed_at: job.completed_at,
      error: job.error,
    });
  });

  router.get('/api/restore-requests', (req, res) => {
    const jobs = jobQueue.listJobs();
    res.json({ jobs });
  });

  router.delete('/api/restore-request/:jobId', express.json(), (req, res) => {
    const cancelled = jobQueue.cancelJob(req.params.jobId);
    if (!cancelled) {
      return res.status(409).json({ error: 'Job cannot be cancelled (not in queued state)' });
    }
    res.json({ status: 'cancelled' });
  });

  return router;
}

module.exports = createRestoreRoutes;
```

- [ ] **Step 2: Mount in server.js**

In `yesterday/api/server.js`, add with other requires:

```javascript
const JobQueue = require('./lib/job-queue');
const createRestoreRoutes = require('./routes/restore-requests');
```

After the `snapshotDb` initialization, add:

```javascript
const jobQueue = new JobQueue(snapshotDb);
const apiSecret = process.env.MODTOOLS_API_SECRET || '';
app.use(createRestoreRoutes(jobQueue, apiSecret));
```

- [ ] **Step 3: Add MODTOOLS_API_SECRET to docker-compose**

In `yesterday/docker-compose.yesterday-services.yml`, add to the `yesterday-api` environment:

```yaml
    - MODTOOLS_API_SECRET=${MODTOOLS_API_SECRET:-}
```

- [ ] **Step 4: Commit**

```bash
cd /home/edward/FreegleDockerWSL
git add yesterday/api/routes/restore-requests.js yesterday/api/server.js yesterday/docker-compose.yesterday-services.yml
git commit -m "feat(yesterday): add restore request endpoints with signed auth and job queue"
```

---

## Task 6: Whitelist Public Endpoints in 2FA Gateway

**Files:**
- Modify: `yesterday/2fa-gateway/server.js` (after the existing `/api/current-backup` block, around line 661)

- [ ] **Step 1: Add public endpoint for user-snapshots**

In `yesterday/2fa-gateway/server.js`, after the `/api/current-backup` CORS + GET blocks (around line 661), add:

```javascript
// Public: User snapshot lookup (for ModTools support tools)
app.options('/api/user-snapshots', (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, sentry-trace, baggage');
    res.status(204).end();
});

app.get('/api/user-snapshots', (req, res) => {
    console.log('[PUBLIC] /api/user-snapshots accessed without auth');
    const options = {
        hostname: 'yesterday-api',
        port: 8082,
        path: '/api/user-snapshots' + (req.url.includes('?') ? '?' + req.url.split('?')[1] : ''),
        method: 'GET',
        headers: { 'Accept': 'application/json' }
    };

    const proxyReq = http.request(options, (proxyRes) => {
        res.setHeader('Content-Type', 'application/json');
        res.setHeader('Access-Control-Allow-Origin', '*');
        proxyRes.pipe(res);
    });

    proxyReq.on('error', (err) => {
        console.error('[PUBLIC] /api/user-snapshots proxy error:', err.message);
        res.status(502).json({ error: 'Backend unavailable' });
    });

    proxyReq.end();
});
```

- [ ] **Step 2: Add public endpoints for restore requests**

Add after the user-snapshots block:

```javascript
// Public: Restore request submission (signed by ModTools)
app.options('/api/restore-request', (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    res.status(204).end();
});

app.post('/api/restore-request', (req, res) => {
    console.log('[PUBLIC] /api/restore-request POST accessed without auth');
    const options = {
        hostname: 'yesterday-api',
        port: 8082,
        path: '/api/restore-request',
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        }
    };

    const proxyReq = http.request(options, (proxyRes) => {
        res.setHeader('Content-Type', 'application/json');
        res.setHeader('Access-Control-Allow-Origin', '*');
        proxyRes.pipe(res);
    });

    proxyReq.on('error', (err) => {
        console.error('[PUBLIC] /api/restore-request proxy error:', err.message);
        res.status(502).json({ error: 'Backend unavailable' });
    });

    req.pipe(proxyReq);
});

// Public: Restore request status check
app.options('/api/restore-request/:jobId', (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, DELETE, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    res.status(204).end();
});

app.get('/api/restore-request/:jobId', (req, res) => {
    console.log(`[PUBLIC] /api/restore-request/${req.params.jobId} accessed without auth`);
    const options = {
        hostname: 'yesterday-api',
        port: 8082,
        path: `/api/restore-request/${req.params.jobId}`,
        method: 'GET',
        headers: { 'Accept': 'application/json' }
    };

    const proxyReq = http.request(options, (proxyRes) => {
        res.setHeader('Content-Type', 'application/json');
        res.setHeader('Access-Control-Allow-Origin', '*');
        proxyRes.pipe(res);
    });

    proxyReq.on('error', (err) => {
        res.status(502).json({ error: 'Backend unavailable' });
    });

    proxyReq.end();
});

// Public: List restore requests
app.options('/api/restore-requests', (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    res.status(204).end();
});

app.get('/api/restore-requests', (req, res) => {
    console.log('[PUBLIC] /api/restore-requests accessed without auth');
    const options = {
        hostname: 'yesterday-api',
        port: 8082,
        path: '/api/restore-requests',
        method: 'GET',
        headers: { 'Accept': 'application/json' }
    };

    const proxyReq = http.request(options, (proxyRes) => {
        res.setHeader('Content-Type', 'application/json');
        res.setHeader('Access-Control-Allow-Origin', '*');
        proxyRes.pipe(res);
    });

    proxyReq.on('error', (err) => {
        res.status(502).json({ error: 'Backend unavailable' });
    });

    proxyReq.end();
});
```

- [ ] **Step 3: Commit**

```bash
cd /home/edward/FreegleDockerWSL
git add yesterday/2fa-gateway/server.js
git commit -m "feat(yesterday): whitelist backup index and restore request endpoints in 2FA gateway"
```

---

## Task 7: Laravel Snapshot Indexer Command

**Files:**
- Create: `iznik-batch/app/Console/Commands/User/IndexSnapshotCommand.php`
- Create: `iznik-batch/tests/Feature/User/IndexSnapshotCommandTest.php`

- [ ] **Step 1: Write failing test for the indexer**

Create `iznik-batch/tests/Feature/User/IndexSnapshotCommandTest.php`:

```php
<?php

namespace Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IndexSnapshotCommandTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = tempnam(sys_get_temp_dir(), 'snapshot_') . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
        parent::tearDown();
    }

    public function test_requires_backup_date(): void
    {
        $this->artisan('user:index-snapshot', ['--output-db' => $this->dbPath])
            ->assertFailed();
    }

    public function test_indexes_recently_active_users(): void
    {
        $userId = $this->createTestUser([
            'fullname' => 'Test User',
            'lastaccess' => now()->subDays(5)->toDateTimeString(),
            'engagement' => 'Frequent',
            'systemrole' => 'User',
        ]);

        $this->createTestEmail($userId, 'test@example.com');
        $this->createTestMessage($userId);
        $this->createTestMessage($userId);
        $this->createMembership($userId, $this->createTestGroup());

        $this->artisan('user:index-snapshot', [
            '--backup-date' => '2026-03-29',
            '--output-db' => $this->dbPath,
        ])->assertSuccessful();

        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $stmt = $pdo->prepare('SELECT * FROM snapshots WHERE email = ?');
        $stmt->execute(['test@example.com']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotNull($row);
        $this->assertEquals('2026-03-29', $row['backup_date']);
        $this->assertEquals($userId, $row['userid']);
        $this->assertEquals('Test User', $row['fullname']);
        $this->assertEquals('Frequent', $row['engagement']);
        $this->assertEquals(2, $row['message_count']);
        $this->assertEquals(1, $row['membership_count']);
    }

    public function test_skips_users_inactive_beyond_retention(): void
    {
        $userId = $this->createTestUser([
            'fullname' => 'Old User',
            'lastaccess' => now()->subDays(100)->toDateTimeString(),
        ]);
        $this->createTestEmail($userId, 'old@example.com');

        $this->artisan('user:index-snapshot', [
            '--backup-date' => '2026-03-29',
            '--output-db' => $this->dbPath,
        ])->assertSuccessful();

        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $stmt = $pdo->prepare('SELECT * FROM snapshots WHERE email = ?');
        $stmt->execute(['old@example.com']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertFalse($row);
    }

    public function test_captures_deleted_and_forgotten_status(): void
    {
        $userId = $this->createTestUser([
            'fullname' => 'Deleted User',
            'lastaccess' => now()->subDays(2)->toDateTimeString(),
            'deleted' => now()->subDay()->toDateTimeString(),
            'forgotten' => null,
        ]);
        $this->createTestEmail($userId, 'deleted@example.com');

        $this->artisan('user:index-snapshot', [
            '--backup-date' => '2026-03-29',
            '--output-db' => $this->dbPath,
        ])->assertSuccessful();

        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $stmt = $pdo->prepare('SELECT * FROM snapshots WHERE email = ?');
        $stmt->execute(['deleted@example.com']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotNull($row);
        $this->assertNotNull($row['deleted']);
        $this->assertNull($row['forgotten']);
    }

    public function test_prunes_old_snapshots(): void
    {
        // Pre-populate the SQLite DB with an old snapshot
        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS snapshots (
                backup_date TEXT NOT NULL,
                userid INTEGER NOT NULL,
                email TEXT,
                fullname TEXT,
                deleted TEXT,
                forgotten TEXT,
                lastaccess TEXT,
                engagement TEXT,
                systemrole TEXT,
                message_count INTEGER,
                chat_count INTEGER,
                membership_count INTEGER,
                PRIMARY KEY (backup_date, userid)
            )
        ");
        $pdo->exec("
            INSERT INTO snapshots (backup_date, userid, email, fullname, message_count, chat_count, membership_count)
            VALUES ('2025-01-01', 1, 'ancient@example.com', 'Ancient', 0, 0, 0)
        ");
        $pdo = null;

        $userId = $this->createTestUser([
            'fullname' => 'Current User',
            'lastaccess' => now()->subDays(1)->toDateTimeString(),
        ]);
        $this->createTestEmail($userId, 'current@example.com');

        $this->artisan('user:index-snapshot', [
            '--backup-date' => '2026-03-29',
            '--output-db' => $this->dbPath,
        ])->assertSuccessful();

        $pdo = new \PDO("sqlite:{$this->dbPath}");

        // Old snapshot should be pruned
        $stmt = $pdo->prepare('SELECT * FROM snapshots WHERE email = ?');
        $stmt->execute(['ancient@example.com']);
        $this->assertFalse($stmt->fetch());

        // New snapshot should exist
        $stmt->execute(['current@example.com']);
        $this->assertNotFalse($stmt->fetch());
    }

    // --- Helper methods (same pattern as UserDumpRestoreCommandTest) ---

    private function createTestUser(array $attrs = []): int
    {
        return DB::table('users')->insertGetId(array_merge([
            'fullname' => 'Test User',
            'systemrole' => 'User',
            'added' => now()->toDateTimeString(),
            'lastaccess' => now()->toDateTimeString(),
        ], $attrs));
    }

    private function createTestEmail(int $userId, string $email): int
    {
        return DB::table('users_emails')->insertGetId([
            'userid' => $userId,
            'email' => $email,
            'preferred' => 1,
            'added' => now()->toDateTimeString(),
        ]);
    }

    private function createTestGroup(): int
    {
        return DB::table('groups')->insertGetId([
            'nameshort' => 'testgroup-' . uniqid(),
            'type' => 'Freegle',
            'region' => 'TestRegion',
            'publish' => 1,
        ]);
    }

    private function createTestMessage(int $userId): int
    {
        return DB::table('messages')->insertGetId([
            'fromuser' => $userId,
            'date' => now()->toDateTimeString(),
            'arrival' => now()->toDateTimeString(),
            'type' => 'Offer',
            'source' => 'Platform',
        ]);
    }

    private function createMembership(int $userId, int $groupId): void
    {
        DB::table('memberships')->insert([
            'userid' => $userId,
            'groupid' => $groupId,
            'role' => 'Member',
            'added' => now()->toDateTimeString(),
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/edward/FreegleDockerWSL/iznik-batch
php artisan test --filter=IndexSnapshotCommandTest
```

Expected: FAIL — command not found

- [ ] **Step 3: Implement IndexSnapshotCommand**

Create `iznik-batch/app/Console/Commands/User/IndexSnapshotCommand.php`:

```php
<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IndexSnapshotCommand extends Command
{
    protected $signature = 'user:index-snapshot
        {--backup-date= : The date of the backup being indexed (YYYY-MM-DD)}
        {--output-db=/data/user-snapshots.db : Path to the SQLite database}
        {--retention-days=90 : Prune snapshots older than this many days}';

    protected $description = 'Extract user metadata from the current backup into the snapshot index';

    public function handle(): int
    {
        $backupDate = $this->option('backup-date');
        if (!$backupDate) {
            $this->error('--backup-date is required');
            return Command::FAILURE;
        }

        $dbPath = $this->option('output-db');
        $retentionDays = (int) $this->option('retention-days');

        $pdo = new \PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->initSchema($pdo);

        $cutoffDate = now()->subDays($retentionDays)->toDateString();

        // Prune old snapshots
        $pruned = $pdo->prepare('DELETE FROM snapshots WHERE backup_date < ?');
        $pruned->execute([$cutoffDate]);
        $prunedCount = $pruned->rowCount();
        if ($prunedCount > 0) {
            $this->info("Pruned {$prunedCount} old snapshot rows (before {$cutoffDate})");
        }

        // Delete any existing snapshot for this backup date (re-run safety)
        $pdo->prepare('DELETE FROM snapshots WHERE backup_date = ?')->execute([$backupDate]);

        // Query recently active users with counts
        $cutoffAccess = now()->subDays($retentionDays)->toDateTimeString();

        $users = DB::select("
            SELECT
                u.id AS userid,
                u.fullname,
                u.deleted,
                u.forgotten,
                u.lastaccess,
                u.engagement,
                u.systemrole,
                (SELECT e.email FROM users_emails e WHERE e.userid = u.id AND e.preferred = 1 LIMIT 1) AS email,
                (SELECT COUNT(*) FROM messages m WHERE m.fromuser = u.id) AS message_count,
                (SELECT COUNT(*) FROM chat_rooms cr WHERE cr.user1 = u.id OR cr.user2 = u.id) AS chat_count,
                (SELECT COUNT(*) FROM memberships mb WHERE mb.userid = u.id) AS membership_count
            FROM users u
            WHERE u.lastaccess >= ?
        ", [$cutoffAccess]);

        $insert = $pdo->prepare("
            INSERT OR REPLACE INTO snapshots
                (backup_date, userid, email, fullname, deleted, forgotten,
                 lastaccess, engagement, systemrole, message_count, chat_count, membership_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $pdo->beginTransaction();
        $count = 0;

        foreach ($users as $user) {
            $insert->execute([
                $backupDate,
                $user->userid,
                $user->email,
                $user->fullname,
                $user->deleted,
                $user->forgotten,
                $user->lastaccess,
                $user->engagement,
                $user->systemrole,
                $user->message_count,
                $user->chat_count,
                $user->membership_count,
            ]);
            $count++;
        }

        $pdo->commit();

        $this->info("Indexed {$count} users for backup date {$backupDate}");
        return Command::SUCCESS;
    }

    private function initSchema(\PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS snapshots (
                backup_date TEXT NOT NULL,
                userid INTEGER NOT NULL,
                email TEXT,
                fullname TEXT,
                deleted TEXT,
                forgotten TEXT,
                lastaccess TEXT,
                engagement TEXT,
                systemrole TEXT,
                message_count INTEGER,
                chat_count INTEGER,
                membership_count INTEGER,
                PRIMARY KEY (backup_date, userid)
            )
        ");

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_snapshots_email ON snapshots(email)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_snapshots_fullname ON snapshots(fullname)');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd /home/edward/FreegleDockerWSL/iznik-batch
php artisan test --filter=IndexSnapshotCommandTest
```

Expected: PASS (all 4 tests)

- [ ] **Step 5: Commit**

```bash
cd /home/edward/FreegleDockerWSL
git add iznik-batch/app/Console/Commands/User/IndexSnapshotCommand.php iznik-batch/tests/Feature/User/IndexSnapshotCommandTest.php
git commit -m "feat(batch): add user:index-snapshot command for backup metadata extraction"
```

---

## Task 8: Hook Indexer into restore-backup.sh

**Files:**
- Modify: `yesterday/scripts/restore-backup.sh` (post-restore section, before `update_status "completed"`)

- [ ] **Step 1: Add indexing step to restore-backup.sh**

In `yesterday/scripts/restore-backup.sh`, find the line `update_status "completed" "Restore completed successfully"` (around line 652). Add before it:

```bash
# Index user snapshots for the restored backup
echo "=== Indexing user snapshots ==="
BACKUP_DATE_FORMATTED=$(echo "$BACKUP_DATE" | sed 's/\(....\)\(..\)\(..\)/\1-\2-\3/')
if docker exec batch php artisan user:index-snapshot --backup-date="$BACKUP_DATE_FORMATTED" 2>&1; then
    echo "User snapshot indexing complete."
else
    echo "WARNING: User snapshot indexing failed (non-fatal, continuing)"
fi
```

- [ ] **Step 2: Commit**

```bash
cd /home/edward/FreegleDockerWSL
git add yesterday/scripts/restore-backup.sh
git commit -m "feat(yesterday): run snapshot indexer after each nightly backup restore"
```

---

## Task 9: Job Queue Processor (Background Worker)

**Files:**
- Create: `yesterday/api/lib/queue-processor.js`

This is the component that actually executes restore jobs by calling `restore-backup.sh` and `user:dump`.

- [ ] **Step 1: Implement the queue processor**

Create `yesterday/api/lib/queue-processor.js`:

```javascript
const { execSync, exec } = require('child_process');
const fs = require('fs');
const path = require('path');

class QueueProcessor {
  constructor(jobQueue, options = {}) {
    this.jobQueue = jobQueue;
    this.currentBackupFile = options.currentBackupFile || '/data/current-backup.json';
    this.restoreScript = options.restoreScript || '/scripts/restore-backup.sh';
    this.dumpsDir = options.dumpsDir || '/data/dumps';
    this.processing = false;
    this.timer = null;
  }

  getCurrentBackupDate() {
    try {
      const data = JSON.parse(fs.readFileSync(this.currentBackupFile, 'utf8'));
      // Convert YYYYMMDD to YYYY-MM-DD
      const d = data.date;
      if (d && d.length === 8) {
        return `${d.slice(0, 4)}-${d.slice(4, 6)}-${d.slice(6, 8)}`;
      }
      return d || null;
    } catch {
      return null;
    }
  }

  start(intervalMs = 30000) {
    this.timer = setInterval(() => this.processNext(), intervalMs);
    console.log(`Queue processor started (polling every ${intervalMs / 1000}s)`);
  }

  stop() {
    if (this.timer) {
      clearInterval(this.timer);
      this.timer = null;
    }
  }

  async processNext() {
    if (this.processing) return;

    const currentDate = this.getCurrentBackupDate();
    const batch = this.jobQueue.getNextBatch(currentDate);
    if (!batch) return;

    this.processing = true;

    try {
      // If we need a different backup, load it first
      if (batch.backup_date !== currentDate) {
        for (const job of batch.jobs) {
          this.jobQueue.updateJobStatus(job.job_id, 'loading_backup', `Loading backup ${batch.backup_date}...`);
        }

        const backupDateCompact = batch.backup_date.replace(/-/g, '');
        try {
          execSync(`${this.restoreScript} ${backupDateCompact}`, {
            timeout: 3 * 60 * 60 * 1000, // 3 hour timeout
            stdio: 'pipe',
          });
        } catch (err) {
          for (const job of batch.jobs) {
            this.jobQueue.updateJobStatus(job.job_id, 'failed', null);
            this.jobQueue.setJobError(job.job_id, `Backup restore failed: ${err.message}`);
          }
          return;
        }
      }

      // Dump each user
      if (!fs.existsSync(this.dumpsDir)) {
        fs.mkdirSync(this.dumpsDir, { recursive: true });
      }

      for (const job of batch.jobs) {
        this.jobQueue.updateJobStatus(job.job_id, 'dumping_user', `Dumping user ${job.email}...`);

        const outputPath = path.join(this.dumpsDir, `${job.job_id}.json`);
        try {
          execSync(
            `docker exec batch php artisan user:dump --email="${job.email}" --output="${outputPath}"`,
            { timeout: 60000, stdio: 'pipe' }
          );

          if (fs.existsSync(outputPath)) {
            this.jobQueue.updateJobStatus(job.job_id, 'completed', 'Dump ready for download');
          } else {
            this.jobQueue.updateJobStatus(job.job_id, 'failed', null);
            this.jobQueue.setJobError(job.job_id, 'User not found in backup');
          }
        } catch (err) {
          this.jobQueue.updateJobStatus(job.job_id, 'failed', null);
          this.jobQueue.setJobError(job.job_id, `Dump failed: ${err.message}`);
        }
      }
    } finally {
      this.processing = false;
    }
  }

  getDumpPath(jobId) {
    return path.join(this.dumpsDir, `${jobId}.json`);
  }
}

module.exports = QueueProcessor;
```

- [ ] **Step 2: Add setJobError to JobQueue**

In `yesterday/api/lib/job-queue.js`, add to the class before the closing brace:

```javascript
  setJobError(jobId, error) {
    this.db.prepare('UPDATE restore_jobs SET error = ? WHERE job_id = ?').run(error, jobId);
  }
```

- [ ] **Step 3: Add dump download endpoint to restore-requests routes**

In `yesterday/api/routes/restore-requests.js`, add before the `return router;` line:

```javascript
  router.get('/api/restore-request/:jobId/dump', (req, res) => {
    const job = jobQueue.getJob(req.params.jobId);
    if (!job) {
      return res.status(404).json({ error: 'Job not found' });
    }
    if (job.status !== 'completed') {
      return res.status(409).json({ error: 'Dump not ready', status: job.status });
    }

    const dumpPath = require('path').join('/data/dumps', `${req.params.jobId}.json`);
    if (!require('fs').existsSync(dumpPath)) {
      return res.status(404).json({ error: 'Dump file not found' });
    }

    res.download(dumpPath, `user-restore-${job.email}.json`);
  });
```

- [ ] **Step 4: Wire up processor in server.js**

In `yesterday/api/server.js`, add with other requires:

```javascript
const QueueProcessor = require('./lib/queue-processor');
```

After the `jobQueue` initialization, add:

```javascript
const queueProcessor = new QueueProcessor(jobQueue);
queueProcessor.start();
```

- [ ] **Step 5: Commit**

```bash
cd /home/edward/FreegleDockerWSL
git add yesterday/api/lib/queue-processor.js yesterday/api/lib/job-queue.js yesterday/api/routes/restore-requests.js yesterday/api/server.js
git commit -m "feat(yesterday): add background queue processor for restore jobs"
```

---

## Task 10: Run All Tests and Final Verification

- [ ] **Step 1: Run Node.js tests**

```bash
cd /home/edward/FreegleDockerWSL/yesterday/api
npx jest --verbose
```

Expected: All tests pass (snapshot-db, auth, job-queue)

- [ ] **Step 2: Run Laravel tests**

```bash
cd /home/edward/FreegleDockerWSL/iznik-batch
php artisan test --filter=IndexSnapshotCommandTest
```

Expected: All tests pass

- [ ] **Step 3: Run full Laravel test suite to check for regressions**

```bash
cd /home/edward/FreegleDockerWSL/iznik-batch
php artisan test
```

Expected: All existing tests still pass

- [ ] **Step 4: Commit any remaining changes**

If any test fixes were needed, commit them.
