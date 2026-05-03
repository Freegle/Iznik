import Database from 'better-sqlite3'
import { openLabelsDb, resolveLabeller } from '~/server/utils/labelsDb'

interface FieldDef {
  field: string
  weight: number
  dbColumn: string
  question: string
}

const FIELDS: FieldDef[] = [
  {
    field: 'EEE',
    weight: 5,
    dbColumn: 'is_eee',
    question: 'Is this item EEE (electrical/electronic equipment requiring WEEE recycling)?',
  },
  {
    field: 'Electrical components',
    weight: 4,
    dbColumn: 'electrical_components_description',
    question: 'Are the electrical components listed plausible for this item?',
  },
  {
    field: 'Photo quality',
    weight: 4,
    dbColumn: 'photo_quality',
    question: "Is the model's photo quality rating correct?",
  },
  {
    field: 'Condition',
    weight: 3,
    dbColumn: 'condition',
    question: "Is the model's condition assessment correct?",
  },
  {
    field: 'Weight (kg)',
    weight: 3,
    dbColumn: 'weight_kg_min',
    question: "Does the model's weight estimate seem plausible?",
  },
  {
    field: 'Value band',
    weight: 2,
    dbColumn: 'value_band_gbp',
    question: "Is the model's value band reasonable?",
  },
  {
    field: 'Brand',
    weight: 1,
    dbColumn: 'brand',
    question: "Is the model's brand identification correct?",
  },
]

const FIELD_MAP = Object.fromEntries(FIELDS.map(f => [f.field, f]))

function buildImageUrl(externaluid: string): string {
  if (externaluid.startsWith('freegletusd-')) {
    const fileId = externaluid.slice('freegletusd-'.length)
    return `https://delivery.ilovefreegle.org?url=https://uploads.ilovefreegle.org:8080/${fileId}&w=768&h=768&fit=inside&output=jpg`
  }
  return `https://ucarecdn.com/${externaluid}/-/preview/768x768/-/format/jpeg/`
}

const MODEL_DISPLAY: Record<string, string> = {
  'claude-opus-4-7': 'Claude Opus',
  'claude-sonnet-4-6': 'Claude Sonnet',
  'gemini-2.0-flash-lite': 'Gemini Flash Lite',
  'gpt-4o': 'GPT-4o',
  'geeks_c87e/Qwen/Qwen2.5-VL-72B-Instruct-e07a2308': 'Qwen2.5-VL 72B',
}

export default defineEventHandler(async (event) => {
  const classdbPath = process.env.EEE_SQLITE_PATH
  if (!classdbPath) {
    throw createError({ statusCode: 500, statusMessage: 'EEE_SQLITE_PATH environment variable not set' })
  }

  const query = getQuery(event)
  const fieldParam = query.field as string
  if (!fieldParam || !FIELD_MAP[fieldParam]) {
    throw createError({
      statusCode: 400,
      statusMessage: `Invalid or missing field parameter. Must be one of: ${FIELDS.map(f => f.field).join(', ')}`,
    })
  }

  const token = getCookie(event, 'eee_reviewer')
  const fieldDef = FIELD_MAP[fieldParam]

  try {
    const classDb = new Database(classdbPath, { readonly: true })
    const labelsDb = openLabelsDb()

    const labeller = resolveLabeller(labelsDb, token)
    if (!labeller) {
      labelsDb.close()
      classDb.close()
      throw createError({ statusCode: 401, statusMessage: 'Not registered — please choose a reviewer name first' })
    }

    const canonicalSamples = classDb.prepare(`
      WITH per_sample AS (
        SELECT s.messageid, s.attid, s.item_name, s.externaluid,
          COUNT(DISTINCT c.${fieldDef.dbColumn}) AS distinct_values
        FROM eee_item_type_samples s
        LEFT JOIN eee_classifications c
          ON c.messageid = s.messageid AND c.attid = s.attid
          AND c.prompt_version = '1.4.1' AND c.${fieldDef.dbColumn} IS NOT NULL
        GROUP BY s.messageid, s.attid, s.item_name, s.externaluid
      ),
      ranked AS (
        SELECT *,
          ROW_NUMBER() OVER (
            PARTITION BY item_name
            ORDER BY distinct_values DESC, messageid
          ) AS rn
        FROM per_sample
      )
      SELECT messageid, attid, item_name, externaluid, distinct_values
      FROM ranked WHERE rn = 1
    `).all() as any[]

    const total = canonicalSamples.length

    const labelledSet = new Set(
      (labelsDb.prepare('SELECT messageid, attid FROM eee_field_labels WHERE field = ? AND labeller = ?').all(fieldParam, labeller) as any[])
        .map((r: any) => `${r.messageid}-${r.attid}`)
    )

    const labelledCount = labelledSet.size
    const unlabelled = canonicalSamples.filter(s => !labelledSet.has(`${s.messageid}-${s.attid}`))

    const disagreements = unlabelled.filter(s => s.distinct_values > 1)
      .sort((a, b) => b.distinct_values - a.distinct_values || a.messageid - b.messageid)
    const pool = disagreements.length > 0
      ? disagreements
      : unlabelled.sort((a, b) => a.messageid - b.messageid)

    const item = pool.length > 0 ? pool[0] : null

    if (!item) {
      classDb.close()
      labelsDb.close()
      return {
        messageid: null, attid: null, itemName: null, imageUrl: null,
        field: fieldParam, fieldQuestion: fieldDef.question,
        modelValues: [], progress: { labelled: labelledCount, total }, hasDisagreement: false,
        labeller,
      }
    }

    const classifications = classDb.prepare(`
      SELECT model, is_eee, electrical_components_description, photo_quality,
             condition, weight_kg_min, value_band_gbp, brand
      FROM eee_classifications
      WHERE messageid = ? AND attid = ? AND prompt_version = '1.4.1'
      ORDER BY model
    `).all(item.messageid, item.attid) as any[]

    const modelValues = classifications
      .filter((c: any) => c[fieldDef.dbColumn] !== null && c[fieldDef.dbColumn] !== undefined)
      .map((c: any) => ({
        model: c.model,
        displayName: MODEL_DISPLAY[c.model] ?? c.model,
        value: c[fieldDef.dbColumn],
      }))

    const hasDisagreement = new Set(modelValues.map((m: any) => String(m.value))).size > 1

    classDb.close()
    labelsDb.close()

    return {
      messageid: item.messageid,
      attid: item.attid,
      itemName: item.item_name || null,
      imageUrl: buildImageUrl(item.externaluid),
      field: fieldParam,
      fieldQuestion: fieldDef.question,
      modelValues,
      progress: { labelled: labelledCount, total },
      hasDisagreement,
      labeller,
    }
  } catch (error: any) {
    if (error.statusCode) throw error
    console.error('Review next error:', error)
    throw createError({ statusCode: 500, statusMessage: `Database error: ${(error as Error).message}` })
  }
})
