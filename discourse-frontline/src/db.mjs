// The service's own record: one SQLite file.
//
// Node's built-in SQLite is used so that nothing has to be compiled on install. It
// carries FTS5, which is the whole search engine here: the wiki, the docs and the
// past cases are each a full-text table ranked by BM25.

import { DatabaseSync } from 'node:sqlite'
import { mkdirSync } from 'node:fs'
import { dirname } from 'node:path'

const SCHEMA = `
CREATE TABLE IF NOT EXISTS kv (
  id INTEGER PRIMARY KEY,
  k TEXT NOT NULL UNIQUE,
  v TEXT NOT NULL
);

-- Every forum post the service has read, with what it made of it.
CREATE TABLE IF NOT EXISTS post (
  id INTEGER PRIMARY KEY,
  post_id INTEGER NOT NULL UNIQUE,
  topic_id INTEGER NOT NULL,
  post_number INTEGER NOT NULL,
  reply_to INTEGER,
  author TEXT NOT NULL,
  created_at TEXT NOT NULL,
  version INTEGER NOT NULL DEFAULT 1,
  topic_title TEXT,
  text TEXT NOT NULL,
  has_image INTEGER NOT NULL DEFAULT 0,
  category_id INTEGER,
  -- skipped:<why> | signal:<what> | case | error
  disposition TEXT NOT NULL,
  case_id INTEGER,
  attempts INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS post_topic ON post(topic_id, post_number);

-- One problem, question or suggestion, however many posts and threads it spans.
CREATE TABLE IF NOT EXISTS "case" (
  id INTEGER PRIMARY KEY,
  topic_id INTEGER NOT NULL,
  post_number INTEGER NOT NULL,
  reporter TEXT NOT NULL,
  kind TEXT NOT NULL CHECK (kind IN ('bug','question','feature')),
  -- waiting-reporter: asked, no answer yet. ready: report written. needs-human: the
  -- service could not answer. answered: it did. duplicate: folded into another case.
  state TEXT NOT NULL CHECK (state IN ('waiting-reporter','ready','needs-human','answered','duplicate')),
  summary TEXT NOT NULL,
  area TEXT NOT NULL DEFAULT '',
  urgency TEXT NOT NULL DEFAULT 'normal',
  facts TEXT NOT NULL DEFAULT '{}',
  unknowns TEXT NOT NULL DEFAULT '[]',
  asks INTEGER NOT NULL DEFAULT 0,
  affected INTEGER NOT NULL DEFAULT 1,
  still_broken INTEGER NOT NULL DEFAULT 0,
  human_replied INTEGER NOT NULL DEFAULT 0,
  duplicate_of INTEGER,
  report_version INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  UNIQUE (topic_id, post_number)
);
CREATE INDEX IF NOT EXISTS case_topic ON "case"(topic_id, state);

CREATE VIRTUAL TABLE IF NOT EXISTS case_fts USING fts5(summary, area, body, tokenize='porter unicode61');

-- Every reply the service wrote, sent or not. A row is written before the post is
-- made, so a crash between the two leaves a 'sending' row and never a second post.
CREATE TABLE IF NOT EXISTS outbound (
  id INTEGER PRIMARY KEY,
  topic_id INTEGER NOT NULL,
  post_number INTEGER NOT NULL,
  kind TEXT NOT NULL CHECK (kind IN ('ask','ack','duplicate','answer')),
  round INTEGER NOT NULL DEFAULT 0,
  case_id INTEGER,
  body TEXT NOT NULL,
  -- draft | sending | sent | refused | unknown
  status TEXT NOT NULL,
  reason TEXT,
  discourse_post_id INTEGER,
  created_at TEXT NOT NULL,
  sent_at TEXT,
  UNIQUE (topic_id, post_number, kind, round)
);

-- One row per model call, so cost is counted and not guessed.
CREATE TABLE IF NOT EXISTS usage (
  id INTEGER PRIMARY KEY,
  at TEXT NOT NULL,
  post_id INTEGER,
  model TEXT NOT NULL,
  input_tokens INTEGER NOT NULL,
  cache_read INTEGER NOT NULL,
  cache_write INTEGER NOT NULL,
  output_tokens INTEGER NOT NULL
);

-- The wiki, docs/ and recent changes, cut into sections.
CREATE TABLE IF NOT EXISTS kb (
  id INTEGER PRIMARY KEY,
  source TEXT NOT NULL,
  title TEXT NOT NULL,
  heading TEXT NOT NULL DEFAULT '',
  url TEXT NOT NULL,
  body TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS kb_source ON kb(source);
CREATE VIRTUAL TABLE IF NOT EXISTS kb_fts USING fts5(title, heading, body, tokenize='porter unicode61');
`

export function openDb(path) {
  if (path !== ':memory:') mkdirSync(dirname(path), { recursive: true })
  const db = new DatabaseSync(path)
  db.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;')
  db.exec(SCHEMA)
  return db
}

export function getKv(db, k, fallback = null) {
  const row = db.prepare('SELECT v FROM kv WHERE k = ?').get(k)
  return row ? JSON.parse(row.v) : fallback
}

export function setKv(db, k, v) {
  db.prepare('INSERT INTO kv (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v').run(k, JSON.stringify(v))
}

/** Run fn inside one transaction. Either all of it is stored or none of it. */
export function tx(db, fn) {
  db.exec('BEGIN IMMEDIATE')
  try {
    const out = fn()
    db.exec('COMMIT')
    return out
  } catch (e) {
    db.exec('ROLLBACK')
    throw e
  }
}

// Words that say nothing about what a post is about. Left in, they make every
// query match every document a little.
const STOP = new Set(('a an and are as at be been but by can could did do does for from had has have he her his how i if in into is it its just me my no not of on or our she so some than that the their them then there these they this to too up us was we were what when where which who why will with would you your am im ive dont cant doesnt isnt wont get got getting one any all also now very really please thanks thank hi hello').split(' '))

/** The distinct words in a passage that say what it is about. */
export function contentTerms(text, maxTerms = 24) {
  const seen = new Set()
  for (const w of text.toLowerCase().match(/[a-z][a-z0-9']{2,}/g) ?? []) {
    const t = w.replace(/'/g, '')
    if (!STOP.has(t)) seen.add(t)
    if (seen.size >= maxTerms) break
  }
  return [...seen]
}

/**
 * A free-text passage as an FTS5 query: its content words, OR-ed, each quoted so
 * nothing in forum text can be read as query syntax.
 */
export function ftsQuery(text, maxTerms = 24) {
  return contentTerms(text, maxTerms).map((t) => `"${t}"`).join(' OR ')
}
