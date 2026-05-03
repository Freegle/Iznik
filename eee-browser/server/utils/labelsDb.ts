import Database from 'better-sqlite3'

export function openLabelsDb(): Database.Database {
  const basePath = process.env.EEE_SQLITE_PATH || '/tmp/classifications.sqlite'
  const labelsPath = process.env.EEE_LABELS_PATH || basePath.replace(/[^/]*$/, 'eee-labels.db')
  const db = new Database(labelsPath)
  migrateLabelsDb(db)
  return db
}

function migrateLabelsDb(db: Database.Database): void {
  db.exec(`
    CREATE TABLE IF NOT EXISTS eee_reviewers (
      name TEXT PRIMARY KEY,
      token TEXT UNIQUE NOT NULL,
      registered_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
  `)

  const tableInfo = db.prepare('PRAGMA table_info(eee_field_labels)').all() as any[]

  if (tableInfo.length === 0) {
    db.exec(`
      CREATE TABLE IF NOT EXISTS eee_field_labels (
        messageid INTEGER NOT NULL,
        attid INTEGER NOT NULL,
        field TEXT NOT NULL,
        labeller TEXT NOT NULL,
        label TEXT NOT NULL,
        labelled_at TEXT NOT NULL DEFAULT (datetime('now')),
        notes TEXT,
        PRIMARY KEY (messageid, attid, field, labeller)
      );
    `)
    return
  }

  if (!tableInfo.some((c: any) => c.name === 'labeller')) {
    db.exec(`
      BEGIN;
      ALTER TABLE eee_field_labels RENAME TO eee_field_labels_old;
      CREATE TABLE eee_field_labels (
        messageid INTEGER NOT NULL, attid INTEGER NOT NULL, field TEXT NOT NULL,
        labeller TEXT NOT NULL DEFAULT 'default', label TEXT NOT NULL,
        labelled_at TEXT NOT NULL DEFAULT (datetime('now')), notes TEXT,
        PRIMARY KEY (messageid, attid, field, labeller)
      );
      INSERT INTO eee_field_labels SELECT messageid, attid, field, 'default', label, labelled_at, notes FROM eee_field_labels_old;
      DROP TABLE eee_field_labels_old;
      COMMIT;
    `)
  }
}

export function resolveLabeller(db: Database.Database, token: string | null | undefined): string | null {
  if (!token) return null
  const row = db.prepare('SELECT name FROM eee_reviewers WHERE token = ?').get(token) as any
  return row?.name ?? null
}
