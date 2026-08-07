import { describe, it, expect, afterEach } from 'vitest'
import { mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import Database from 'better-sqlite3'
import { getDb, resetDbForTests, upsertDiscourseBug } from '../db/index.js'

// applySchema stamps schema_version whether or not the migrations above it
// actually succeeded. A table rebuild can fail for reasons that have nothing to
// do with the SQL — a locked database while the FSM is mid-iteration is enough —
// leaving a DB recorded at the new version with the old constraint. A version
// gate would never retry that, which is how v4 left databases unable to store
// 'feature-request' until v5 was written to repair them. Widening the CHECK is
// therefore driven by what the live table allows, not by the recorded version.

let dir: string | null = null

function tempDbPath(): string {
  dir = mkdtempSync(join(tmpdir(), 'monitor-fsm-migration-'))
  return join(dir, 'monitor.db')
}

afterEach(() => {
  resetDbForTests()
  if (dir) {
    rmSync(dir, { recursive: true, force: true })
    dir = null
  }
})

/** A database in the broken shape: stamped current, but with the old constraint. */
function buildStaleDb(path: string): void {
  const db = new Database(path)
  db.exec(`
    CREATE TABLE schema_version (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT (datetime('now')));
    CREATE TABLE discourse_bug (
      topic INTEGER NOT NULL,
      post INTEGER NOT NULL,
      topic_title TEXT,
      reporter TEXT,
      excerpt TEXT,
      state TEXT NOT NULL DEFAULT 'open' CHECK (state IN ('open','investigating','fix-queued','deferred','fixed','confirmed','off-topic','duplicate','feature-request')),
      pr_number INTEGER,
      reason TEXT,
      first_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
      last_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
      feature_area TEXT,
      fixed_at TEXT,
      deployed_at TEXT,
      pr_rejections INTEGER NOT NULL DEFAULT 0,
      symptom_tags TEXT,
      code_area TEXT,
      PRIMARY KEY (topic, post)
    );
    INSERT INTO schema_version (version) VALUES (6);
    INSERT INTO discourse_bug (topic, post, state, reporter, reason)
      VALUES (900, 1, 'open', 'keeper', 'must survive the rebuild');
    INSERT INTO discourse_bug (topic, post, state, reason)
      VALUES (901, 1, 'deferred', 'classified as a question');
    INSERT INTO discourse_bug (topic, post, state, reason)
      VALUES (902, 1, 'deferred', 'genuinely put aside');
  `)
  db.close()
}

describe('discourse_bug state CHECK — repaired regardless of recorded version', () => {
  it('widens a DB already stamped at the current version', () => {
    const path = tempDbPath()
    buildStaleDb(path)
    resetDbForTests()

    const db = getDb(path)
    const sql = (db.prepare(
      "SELECT sql FROM sqlite_master WHERE type='table' AND name='discourse_bug'"
    ).get() as { sql: string }).sql

    expect(sql).toContain("'question'")
  })

  it('accepts a question after the repair, which previously threw', () => {
    const path = tempDbPath()
    buildStaleDb(path)
    resetDbForTests()

    getDb(path)
    expect(() =>
      upsertDiscourseBug(getDb(path), { topic: 903, post: 1, state: 'question' })
    ).not.toThrow()
  })

  it('preserves existing rows through the rebuild', () => {
    const path = tempDbPath()
    buildStaleDb(path)
    resetDbForTests()

    const db = getDb(path)
    const kept = db.prepare('SELECT reporter, reason FROM discourse_bug WHERE topic = 900 AND post = 1')
      .get() as { reporter: string; reason: string }

    expect(kept.reporter).toBe('keeper')
    expect(kept.reason).toBe('must survive the rebuild')
  })

  it('reclaims questions previously filed as deferred, and only those', () => {
    const path = tempDbPath()
    buildStaleDb(path)
    resetDbForTests()

    const db = getDb(path)
    const wasQuestion = db.prepare('SELECT state FROM discourse_bug WHERE topic = 901').get() as { state: string }
    const genuinelyDeferred = db.prepare('SELECT state FROM discourse_bug WHERE topic = 902').get() as { state: string }

    expect(wasQuestion.state).toBe('question')
    expect(genuinelyDeferred.state).toBe('deferred')
  })

  it('is a no-op on a database that already allows it', () => {
    const path = tempDbPath()
    buildStaleDb(path)
    resetDbForTests()

    getDb(path)
    upsertDiscourseBug(getDb(path), { topic: 904, post: 1, state: 'question' })
    resetDbForTests()

    // Second open must not rebuild again and must not lose the row.
    const db = getDb(path)
    expect(db.prepare('SELECT COUNT(*) c FROM discourse_bug WHERE topic = 904').get()).toEqual({ c: 1 })
  })
})
