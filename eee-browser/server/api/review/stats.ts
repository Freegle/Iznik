import Database from 'better-sqlite3'

interface FieldDef {
  field: string
  weight: number
  dbColumn: string
  type: 'binary' | 'correct' | 'bucket'
}

// Bucket ranges for weight field — label key → [min, max) in kg (null = no bound)
const WEIGHT_BUCKETS: Record<string, [number | null, number | null]> = {
  under_1kg:   [null, 1],
  '1_5kg':     [1, 5],
  '5_20kg':    [5, 20],
  '20_100kg':  [20, 100],
  over_100kg:  [100, null],
}

function weightInBucket(kg: number, bucketKey: string): boolean {
  const range = WEIGHT_BUCKETS[bucketKey]
  if (!range) return false
  const [min, max] = range
  if (min !== null && kg < min) return false
  if (max !== null && kg >= max) return false
  return true
}

const FIELDS: FieldDef[] = [
  { field: 'EEE', weight: 5, dbColumn: 'is_eee', type: 'binary' },
  { field: 'Electrical components', weight: 4, dbColumn: 'electrical_components_description', type: 'correct' },
  { field: 'Photo quality', weight: 4, dbColumn: 'photo_quality', type: 'correct' },
  { field: 'Condition', weight: 3, dbColumn: 'condition', type: 'correct' },
  { field: 'Weight (kg)', weight: 3, dbColumn: 'weight_kg_min', type: 'bucket' },
  { field: 'Value band', weight: 2, dbColumn: 'value_band_gbp', type: 'correct' },
  { field: 'Brand', weight: 1, dbColumn: 'brand', type: 'correct' },
]

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

function formatModelName(model: string): string {
  if (model.includes('claude-sonnet-4-6')) return 'Claude Sonnet'
  if (model.includes('claude-opus-4-7')) return 'Claude Opus'
  if (model.includes('claude')) return 'Claude'
  if (model.includes('gemini')) return 'Gemini Flash Lite'
  if (model.includes('gpt-4o')) return 'GPT-4o'
  if (model.includes('Qwen')) return 'Qwen2.5-VL 72B'
  return model
}

export default defineEventHandler(async (event) => {
  const classDbPath = process.env.EEE_SQLITE_PATH
  if (!classDbPath) {
    throw createError({
      statusCode: 500,
      statusMessage: 'EEE_SQLITE_PATH environment variable not set',
    })
  }

  try {
    const classDb = new Database(classDbPath, { readonly: true })
    const labelsDb = getLabelsDb()

    const result = {
      fields: [] as any[],
    }

    // For each field, compute labelled count and per-model accuracy
    for (const fieldDef of FIELDS) {
      // Count labelled for this field
      const labelledRow = labelsDb.prepare(`
        SELECT COUNT(*) as count
        FROM eee_field_labels
        WHERE field = ?
      `).get(fieldDef.field) as any
      const labelledCount = labelledRow?.count || 0

      // Total = one canonical image per item type (same pool as review/next)
      const totalRow = classDb.prepare(`
        SELECT COUNT(DISTINCT item_name) as count FROM eee_item_type_samples
      `).get() as any
      const totalCount = totalRow?.count || 0

      // Get all labels for this field
      const labels = labelsDb.prepare(`
        SELECT messageid, attid, label
        FROM eee_field_labels
        WHERE field = ?
      `).all(fieldDef.field) as any[]

      // Build per-model accuracy
      const modelAccuracyMap: Record<string, { correct: number; total: number }> = {}

      for (const label of labels) {
        // Get classifications for this item+field
        const classifications = classDb.prepare(`
          SELECT DISTINCT model, ${fieldDef.dbColumn}
          FROM eee_classifications
          WHERE messageid = ? AND attid = ? AND prompt_version = '1.4.1'
          AND ${fieldDef.dbColumn} IS NOT NULL
        `).all(label.messageid, label.attid) as any[]

        for (const clf of classifications) {
          if (!modelAccuracyMap[clf.model]) {
            modelAccuracyMap[clf.model] = { correct: 0, total: 0 }
          }

          modelAccuracyMap[clf.model].total++

          if (fieldDef.type === 'binary') {
            const modelSaysEee = clf.is_eee === 1
            const labelIsEee = label.label === 'eee'
            if (modelSaysEee === labelIsEee) {
              modelAccuracyMap[clf.model].correct++
            }
          } else if (fieldDef.type === 'bucket') {
            const modelKg = parseFloat(clf[fieldDef.dbColumn])
            if (!isNaN(modelKg) && label.label !== 'unsure' && weightInBucket(modelKg, label.label)) {
              modelAccuracyMap[clf.model].correct++
            }
          } else {
            if (label.label === 'correct') {
              modelAccuracyMap[clf.model].correct++
            }
          }
        }
      }

      const modelAccuracy: Record<string, number> = {}
      for (const [model, stats] of Object.entries(modelAccuracyMap)) {
        modelAccuracy[model] = stats.total > 0 ? Math.round((stats.correct / stats.total) * 100) : 0
      }

      result.fields.push({
        field: fieldDef.field,
        weight: fieldDef.weight,
        dbColumn: fieldDef.dbColumn,
        labelled: labelledCount,
        total: totalCount,
        modelAccuracy,
      })
    }

    classDb.close()
    labelsDb.close()

    return result
  } catch (error) {
    console.error('Database error:', error)
    throw createError({
      statusCode: 500,
      statusMessage: `Database error: ${(error as Error).message}`,
    })
  }
})
