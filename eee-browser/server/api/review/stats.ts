import Database from 'better-sqlite3'
import { openLabelsDb } from '~/server/utils/labelsDb'

interface FieldDef {
  field: string
  weight: number
  dbColumn: string
  type: 'binary' | 'presence' | 'rating5' | 'condition' | 'bucket' | 'size_bucket' | 'value_band'
}

const WEIGHT_BUCKETS: Record<string, [number | null, number | null]> = {
  under_1kg:  [null, 1],
  '1_5kg':    [1, 5],
  '5_20kg':   [5, 20],
  '20_100kg': [20, 100],
  over_100kg: [100, null],
}

const SIZE_BUCKETS: Record<string, [number | null, number | null]> = {
  tiny:   [null, 20],
  small:  [20, 50],
  medium: [50, 100],
  large:  [100, null],
}

function inBucket(value: number, buckets: Record<string, [number | null, number | null]>, key: string): boolean {
  const range = buckets[key]
  if (!range) return false
  const [min, max] = range
  if (min !== null && value < min) return false
  if (max !== null && value >= max) return false
  return true
}

function maxSizeCm(sizeCm: string | null): number | null {
  if (!sizeCm) return null
  try {
    const obj = typeof sizeCm === 'string' ? JSON.parse(sizeCm) : sizeCm
    const vals = [obj.w, obj.h, obj.d].filter((v: any) => typeof v === 'number' && v > 0)
    return vals.length > 0 ? Math.max(...vals) : null
  } catch { return null }
}

const FIELDS: FieldDef[] = [
  { field: 'EEE',                   weight: 5, dbColumn: 'is_eee',                          type: 'binary' },
  { field: 'Electrical components', weight: 4, dbColumn: 'electrical_components_description', type: 'presence' },
  { field: 'Photo quality',         weight: 4, dbColumn: 'photo_quality',                    type: 'rating5' },
  { field: 'Condition',             weight: 3, dbColumn: 'condition',                        type: 'condition' },
  { field: 'Weight (kg)',           weight: 3, dbColumn: 'weight_kg_min',                   type: 'bucket' },
  { field: 'Size',                  weight: 2, dbColumn: 'size_cm',                         type: 'size_bucket' },
  { field: 'Value band',            weight: 2, dbColumn: 'value_band_gbp',                  type: 'value_band' },
]

const EXCLUDE_MODELS = new Set(['claude-opus-4-7'])

// Compute quorum label for each (messageid, attid) across all labellers.
// Returns map of "messageid-attid" → plurality label (null if tied).
function computeQuorumLabels(
  labels: { messageid: number; attid: number; labeller: string; label: string }[]
): Map<string, string | null> {
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
    result.set(key, winners.length === 1 ? winners[0] : null)
  }
  return result
}

function isCorrect(fieldDef: FieldDef, quorumLabel: string, clf: any): boolean {
  if (quorumLabel === 'unsure') return false

  switch (fieldDef.type) {
    case 'binary':
      return (clf.is_eee === 1) === (quorumLabel === 'eee')

    case 'presence':
      // human: 'present'/'absent'. Model: non-null description = present
      const modelPresent = clf.electrical_components_description !== null && clf.electrical_components_description !== ''
      return (quorumLabel === 'present') === modelPresent

    case 'rating5': {
      // human: '1'-'5'. Model: photo_quality 1-5. Accept exact or ±1
      const humanRating = parseInt(quorumLabel, 10)
      const modelRating = parseInt(clf.photo_quality, 10)
      if (isNaN(humanRating) || isNaN(modelRating)) return false
      return Math.abs(humanRating - modelRating) <= 1
    }

    case 'condition':
      // human: 'reusable'/'damaged'. Model: 'Reusable'/'Damaged'/'Unknown'
      return clf.condition?.toLowerCase() === quorumLabel.toLowerCase()

    case 'bucket':
      const modelKg = parseFloat(clf.weight_kg_min)
      return !isNaN(modelKg) && inBucket(modelKg, WEIGHT_BUCKETS, quorumLabel)

    case 'size_bucket': {
      const maxDim = maxSizeCm(clf.size_cm)
      return maxDim !== null && inBucket(maxDim, SIZE_BUCKETS, quorumLabel)
    }

    case 'value_band':
      return clf.value_band_gbp === quorumLabel

    default:
      return false
  }
}

export default defineEventHandler(async (event) => {
  const classDbPath = process.env.EEE_SQLITE_PATH
  if (!classDbPath) {
    throw createError({ statusCode: 500, statusMessage: 'EEE_SQLITE_PATH environment variable not set' })
  }

  try {
    const classDb = new Database(classDbPath, { readonly: true })
    const labelsDb = openLabelsDb()

    const reviewers = (labelsDb.prepare('SELECT name FROM eee_reviewers ORDER BY name').all() as any[]).map(r => r.name as string)
    const totalRow = classDb.prepare('SELECT COUNT(DISTINCT item_name) as count FROM eee_item_type_samples').get() as any
    const totalCount = totalRow?.count || 0

    const result = { fields: [] as any[], reviewers }

    for (const fieldDef of FIELDS) {
      const allLabels = labelsDb.prepare(`
        SELECT messageid, attid, labeller, label
        FROM eee_field_labels WHERE field = ?
      `).all(fieldDef.field) as any[]

      const perLabeller: Record<string, number> = {}
      for (const row of allLabels) {
        perLabeller[row.labeller] = (perLabeller[row.labeller] ?? 0) + 1
      }

      const quorumMap = computeQuorumLabels(allLabels)
      const quorumLabels = [...quorumMap.entries()].filter(([, l]) => l !== null && l !== 'unsure')

      const modelAccuracyMap: Record<string, { correct: number; total: number }> = {}

      for (const [itemKey, quorumLabel] of quorumLabels) {
        if (!quorumLabel) continue
        const [mid, aid] = itemKey.split('-').map(Number)

        const classifications = classDb.prepare(`
          SELECT DISTINCT model, is_eee, electrical_components_description, photo_quality,
                 condition, weight_kg_min, size_cm, value_band_gbp, brand
          FROM eee_classifications
          WHERE messageid = ? AND attid = ?
          AND prompt_version IN ('1.4.1','1.4.2')
          AND ${fieldDef.dbColumn} IS NOT NULL
        `).all(mid, aid) as any[]

        for (const clf of classifications) {
          if (EXCLUDE_MODELS.has(clf.model)) continue
          if (!modelAccuracyMap[clf.model]) {
            modelAccuracyMap[clf.model] = { correct: 0, total: 0 }
          }
          modelAccuracyMap[clf.model].total++
          if (isCorrect(fieldDef, quorumLabel, clf)) {
            modelAccuracyMap[clf.model].correct++
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
