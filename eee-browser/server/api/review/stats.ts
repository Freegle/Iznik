import Database from 'better-sqlite3'
import { openLabelsDb } from '~/server/utils/labelsDb'

interface FieldDef {
  field: string
  weight: number
  dbColumn: string
  type: 'binary' | 'correct' | 'bucket'
}

const WEIGHT_BUCKETS: Record<string, [number | null, number | null]> = {
  under_1kg:  [null, 1],
  '1_5kg':    [1, 5],
  '5_20kg':   [5, 20],
  '20_100kg': [20, 100],
  over_100kg: [100, null],
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

// Compute quorum label for each (messageid, attid) across all labellers.
// Returns map of "messageid-attid" → plurality label (null if tied or no labels).
function computeQuorumLabels(
  labels: { messageid: number; attid: number; labeller: string; label: string }[]
): Map<string, string | null> {
  // Group by item
  const byItem = new Map<string, Map<string, number>>()
  for (const row of labels) {
    const key = `${row.messageid}-${row.attid}`
    if (!byItem.has(key)) byItem.set(key, new Map())
    const votes = byItem.get(key)!
    votes.set(row.label, (votes.get(row.label) ?? 0) + 1)
  }

  const result = new Map<string, string | null>()
  for (const [key, votes] of byItem.entries()) {
    const maxVotes = Math.max(...votes.values())
    const winners = [...votes.entries()].filter(([, v]) => v === maxVotes).map(([l]) => l)
    // Only use the quorum label if there's a clear plurality
    result.set(key, winners.length === 1 ? winners[0] : null)
  }
  return result
}

export default defineEventHandler(async (event) => {
  const classDbPath = process.env.EEE_SQLITE_PATH
  if (!classDbPath) {
    throw createError({ statusCode: 500, statusMessage: 'EEE_SQLITE_PATH environment variable not set' })
  }

  try {
    const classDb = new Database(classDbPath, { readonly: true })
    const labelsDb = openLabelsDb()

    // List all reviewers
    const reviewers = (labelsDb.prepare('SELECT name FROM eee_reviewers ORDER BY name').all() as any[]).map(r => r.name as string)

    const result = {
      fields: [] as any[],
      reviewers,
    }

    const totalRow = classDb.prepare('SELECT COUNT(DISTINCT item_name) as count FROM eee_item_type_samples').get() as any
    const totalCount = totalRow?.count || 0

    for (const fieldDef of FIELDS) {
      // All labels for this field across all labellers
      const allLabels = labelsDb.prepare(`
        SELECT messageid, attid, labeller, label
        FROM eee_field_labels
        WHERE field = ?
      `).all(fieldDef.field) as any[]

      // Per-labeller counts
      const perLabeller: Record<string, number> = {}
      for (const row of allLabels) {
        perLabeller[row.labeller] = (perLabeller[row.labeller] ?? 0) + 1
      }

      // Quorum labels (plurality across labellers)
      const quorumMap = computeQuorumLabels(allLabels)
      const quorumLabels = [...quorumMap.entries()].filter(([, l]) => l !== null)

      // Per-model accuracy against quorum
      const modelAccuracyMap: Record<string, { correct: number; total: number }> = {}

      for (const [itemKey, quorumLabel] of quorumLabels) {
        if (!quorumLabel) continue
        const [mid, aid] = itemKey.split('-').map(Number)

        const classifications = classDb.prepare(`
          SELECT DISTINCT model, ${fieldDef.dbColumn}
          FROM eee_classifications
          WHERE messageid = ? AND attid = ? AND prompt_version = '1.4.1'
          AND ${fieldDef.dbColumn} IS NOT NULL
        `).all(mid, aid) as any[]

        for (const clf of classifications) {
          if (!modelAccuracyMap[clf.model]) {
            modelAccuracyMap[clf.model] = { correct: 0, total: 0 }
          }
          modelAccuracyMap[clf.model].total++

          if (fieldDef.type === 'binary') {
            const modelSaysEee = clf.is_eee === 1
            const labelIsEee = quorumLabel === 'eee'
            if (modelSaysEee === labelIsEee) modelAccuracyMap[clf.model].correct++
          } else if (fieldDef.type === 'bucket') {
            const modelKg = parseFloat(clf[fieldDef.dbColumn])
            if (!isNaN(modelKg) && quorumLabel !== 'unsure' && weightInBucket(modelKg, quorumLabel)) {
              modelAccuracyMap[clf.model].correct++
            }
          } else {
            if (quorumLabel === 'correct') modelAccuracyMap[clf.model].correct++
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
        labelledTotal: quorumLabels.length,
        labelledByReviewer: perLabeller,
        total: totalCount,
        modelAccuracy,
      })
    }

    classDb.close()
    labelsDb.close()

    return result
  } catch (error) {
    console.error('Database error:', error)
    throw createError({ statusCode: 500, statusMessage: `Database error: ${(error as Error).message}` })
  }
})
