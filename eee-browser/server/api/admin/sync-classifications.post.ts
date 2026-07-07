import Database from 'better-sqlite3'

/**
 * Receive a batch of EEE classification rows from iznik-batch and write them
 * into the eee-browser's `eee_classifications` SQLite. Authenticated by the
 * shared `EEE_SYNC_SECRET`. Idempotent — INSERT OR REPLACE on the natural
 * unique key (messageid, attid, model, prompt_version).
 *
 * Used to keep the eee-browser's classifications in step with the iznik-batch
 * dev SQLite, which is where new classification runs land. Without this sync
 * the dashboard's Condition / Weight / Size accuracy joins stay empty for
 * any item the dev side has classified after the eee-browser's last
 * snapshot.
 */

// Columns we accept from the wire. Anything else on the row is dropped.
const COLUMNS = [
  'messageid', 'attid', 'model', 'prompt_version', 'run_at', 'data_sources',
  'is_eee', 'is_eee_confidence', 'is_eee_reasoning',
  'is_unusual_eee', 'unusual_eee_reason',
  'weee_category', 'weee_category_name', 'weee_category_confidence',
  'weight_kg_min', 'weight_kg_max', 'weight_kg_confidence',
  'size_cm', 'size_confidence',
  'condition', 'condition_confidence',
  'brand', 'brand_confidence',
  'model_number', 'model_number_confidence',
  'material_primary', 'material_secondary', 'material_confidence',
  'primary_item', 'short_description', 'long_description',
  'photo_quality', 'photo_quality_notes',
  'item_complete', 'item_complete_confidence', 'item_complete_notes',
  'accessories_visible',
  'value_band_gbp', 'value_band_confidence',
  'text_eee_signals', 'chat_eee_signals',
  'conflict_flag',
  'raw_response',
  'input_tokens', 'output_tokens', 'cost_usd',
  'contains_eee_components', 'electrical_components_description',
  'is_eee_from_components',
]

interface ClassificationRow {
  messageid: number
  attid: number | null
  model: string
  prompt_version: string
  [k: string]: unknown
}

export default defineEventHandler(async (event) => {
  const secret = getHeader(event, 'x-eee-sync-secret') || (getQuery(event).secret as string | undefined)
  if (!secret || secret !== process.env.EEE_SYNC_SECRET) {
    throw createError({ statusCode: 401, statusMessage: 'Unauthorized' })
  }

  const body = await readBody(event)
  const rows: ClassificationRow[] = body?.rows
  if (!Array.isArray(rows)) {
    throw createError({ statusCode: 400, statusMessage: 'Body must be { rows: [...] }' })
  }

  const dbPath = process.env.EEE_SQLITE_PATH
  if (!dbPath) throw createError({ statusCode: 500, statusMessage: 'EEE_SQLITE_PATH not set' })
  const db = new Database(dbPath)

  try {
    // We DELETE-then-INSERT per row keyed on (messageid, attid, model,
    // prompt_version). Cannot use INSERT OR REPLACE because the existing
    // table doesn't have a UNIQUE constraint on this key (CREATE UNIQUE INDEX
    // fails because the existing data has duplicates we cannot drop blindly).
    const tableInfo = db.prepare('PRAGMA table_info(eee_classifications)').all() as Array<{ name: string }>
    const allowedCols = new Set(tableInfo.map(c => c.name))
    const writeCols = COLUMNS.filter(c => allowedCols.has(c))
    const placeholders = writeCols.map(() => '?').join(', ')

    const insertStmt = db.prepare(`INSERT INTO eee_classifications (${writeCols.join(', ')}) VALUES (${placeholders})`)
    const deleteStmt = db.prepare(`DELETE FROM eee_classifications WHERE messageid = ? AND attid IS ? AND model = ? AND prompt_version = ?`)

    let inserted = 0
    let rejected = 0
    const txn = db.transaction((batch: ClassificationRow[]) => {
      for (const r of batch) {
        if (!Number.isInteger(r.messageid) ||
            !r.model || typeof r.model !== 'string' ||
            !r.prompt_version || typeof r.prompt_version !== 'string') {
          rejected++
          continue
        }
        // Remove any previous rows on the same natural key (multiple rows OK).
        // `IS ?` matches NULL too (text-only classifications carry attid=NULL).
        deleteStmt.run(r.messageid, r.attid ?? null, r.model, r.prompt_version)
        const params = writeCols.map(c => (r as Record<string, unknown>)[c] ?? null)
        insertStmt.run(...params)
        inserted++
      }
    })
    txn(rows)

    return { inserted, rejected, total: rows.length }
  } finally {
    db.close()
  }
})
