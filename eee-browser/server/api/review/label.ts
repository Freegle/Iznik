import Database from 'better-sqlite3'

const VALID_LABELS = ['eee', 'not_eee', 'unsure', 'correct', 'incorrect']
const FIELD_NAMES = ['EEE', 'Photo quality', 'Condition', 'Weight (kg)', 'Value band', 'Brand']

function getLabelsDb(): Database.Database {
  const basePath = process.env.EEE_SQLITE_PATH || '/tmp/classifications.sqlite'
  const labelsPath = process.env.EEE_LABELS_PATH || basePath.replace(/[^/]*$/, 'eee-labels.db')

  const db = new Database(labelsPath)
  // Create table if not exists
  db.exec(`
    CREATE TABLE IF NOT EXISTS eee_field_labels (
      messageid INTEGER NOT NULL,
      attid INTEGER NOT NULL,
      field TEXT NOT NULL,
      label TEXT NOT NULL,
      labelled_at TEXT NOT NULL DEFAULT (datetime('now')),
      notes TEXT,
      PRIMARY KEY (messageid, attid, field)
    )
  `)
  return db
}

export default defineEventHandler(async (event) => {
  const body = await readBody(event)
  const { messageid, attid, field, label, notes } = body

  if (!messageid || !attid || !field || !label) {
    throw createError({
      statusCode: 400,
      statusMessage: 'Missing required fields: messageid, attid, field, label',
    })
  }

  if (!FIELD_NAMES.includes(field)) {
    throw createError({
      statusCode: 400,
      statusMessage: `Invalid field. Must be one of: ${FIELD_NAMES.join(', ')}`,
    })
  }

  if (!VALID_LABELS.includes(label)) {
    throw createError({
      statusCode: 400,
      statusMessage: `Invalid label. Must be one of: ${VALID_LABELS.join(', ')}`,
    })
  }

  try {
    const db = getLabelsDb()

    // Insert or replace the label
    const stmt = db.prepare(`
      INSERT OR REPLACE INTO eee_field_labels (messageid, attid, field, label, labelled_at, notes)
      VALUES (?, ?, ?, ?, datetime('now'), ?)
    `)

    stmt.run(messageid, attid, field, label, notes || null)
    db.close()

    return {
      success: true,
      messageid,
      attid,
      field,
      label,
    }
  } catch (error) {
    console.error('Database error:', error)
    throw createError({
      statusCode: 500,
      statusMessage: `Database error: ${(error as Error).message}`,
    })
  }
})
