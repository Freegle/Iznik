// monitor-fsm SQLite schema v1.
// Inlined (not a .sql file) so TS compilation produces a single self-contained
// dist/ without needing a post-build copy step.

export const SCHEMA_VERSION = 3

// v2 migration: add pr_rejections column to discourse_bug.
// Applied in applySchema() via ALTER TABLE (idempotent — caught by DUPLICATE COLUMN error).
export const MIGRATION_V2_SQL = `
ALTER TABLE discourse_bug ADD COLUMN pr_rejections INTEGER NOT NULL DEFAULT 0;
`

// v3 migration: add symptom_tags (JSON array) and code_area (layer:component string)
// for cross-topic deduplication. Both nullable — older rows have no tags.
export const MIGRATION_V3_SQL = `
ALTER TABLE discourse_bug ADD COLUMN symptom_tags TEXT;
ALTER TABLE discourse_bug ADD COLUMN code_area TEXT;
`

export const SCHEMA_SQL = `
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS schema_version (
  version INTEGER PRIMARY KEY,
  applied_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS topic_cursor (
  topic_id INTEGER PRIMARY KEY,
  last_post_number INTEGER NOT NULL DEFAULT 0,
  title TEXT,
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS discourse_bug (
  topic INTEGER NOT NULL,
  post INTEGER NOT NULL,
  topic_title TEXT,
  reporter TEXT,
  excerpt TEXT,
  state TEXT NOT NULL DEFAULT 'open' CHECK (state IN ('open','investigating','fix-queued','deferred','fixed','confirmed','off-topic','duplicate')),
  pr_number INTEGER,
  reason TEXT,
  first_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
  last_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
  feature_area TEXT,
  fixed_at TEXT,
  deployed_at TEXT,
  PRIMARY KEY (topic, post)
);

CREATE INDEX IF NOT EXISTS idx_discourse_bug_state ON discourse_bug(state);
CREATE INDEX IF NOT EXISTS idx_discourse_bug_pr ON discourse_bug(pr_number);

CREATE TABLE IF NOT EXISTS sentry_issue (
  issue_id TEXT PRIMARY KEY,
  project TEXT NOT NULL CHECK (project IN ('nuxt3','go')),
  title TEXT,
  event_count INTEGER,
  last_seen TEXT,
  permalink TEXT,
  sentry_status TEXT,
  disposition TEXT NOT NULL DEFAULT 'pending' CHECK (disposition IN ('pending','investigating','ignored','fix-queued','fixed','deferred')),
  disposition_reason TEXT,
  disposition_set_at TEXT,
  pr_number INTEGER,
  first_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
  last_triage_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_sentry_issue_disposition ON sentry_issue(disposition);

CREATE TABLE IF NOT EXISTS pr (
  number INTEGER PRIMARY KEY,
  title TEXT,
  url TEXT,
  branch TEXT,
  state TEXT,
  ci_state TEXT,
  deploy_state TEXT,
  frontend_only INTEGER,
  preview_url TEXT,
  source_kind TEXT,
  source_ref TEXT,
  created_at TEXT,
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS iteration (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  started_at TEXT NOT NULL,
  ended_at TEXT,
  steps_used INTEGER,
  outcome TEXT,
  prs_created INTEGER NOT NULL DEFAULT 0,
  note TEXT
);

CREATE TABLE IF NOT EXISTS discourse_draft (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  topic INTEGER NOT NULL,
  post INTEGER NOT NULL,
  username TEXT NOT NULL,
  quote TEXT NOT NULL,
  body TEXT NOT NULL,
  preview_url TEXT,
  pr_number INTEGER,
  pr_url TEXT,
  queued_at TEXT NOT NULL DEFAULT (datetime('now')),
  approved_at TEXT,
  posted_at TEXT,
  rejected_at TEXT,
  rejection_reason TEXT
);

CREATE INDEX IF NOT EXISTS idx_discourse_draft_queue ON discourse_draft(approved_at, posted_at, rejected_at);

CREATE TABLE IF NOT EXISTS reviewer_feedback (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL CHECK (kind IN ('pr_rejected','bug_reopen')),
  pr_number INTEGER,
  bug_topic INTEGER,
  bug_post INTEGER,
  reason TEXT,
  raw TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  processed_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_reviewer_feedback_unprocessed ON reviewer_feedback(processed_at);

CREATE TABLE IF NOT EXISTS kv (
  key TEXT PRIMARY KEY,
  value TEXT,
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
`
