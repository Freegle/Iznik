import { openLabelsDb, resolveLabeller } from '~/server/utils/labelsDb'

const WEIGHT_BUCKET_KEYS = ['under_1kg', '1_5kg', '5_20kg', '20_100kg', 'over_100kg']
const SIZE_BUCKET_KEYS   = ['tiny', 'small', 'medium', 'large']

const VALID_LABELS: Record<string, string[]> = {
  'EEE':                     ['eee', 'not_eee', 'unsure'],
  'Electrical components':   ['present', 'absent', 'unsure'],
  'Photo quality':           ['1', '2', '3', '4', '5', 'unsure'],
  'Condition':               ['reusable', 'damaged', 'unsure'],
  'Weight (kg)':             [...WEIGHT_BUCKET_KEYS, 'unsure'],
  'Size':                    [...SIZE_BUCKET_KEYS, 'unsure'],
  'Value band':              ['0-20', '20-100', '100-500', '500+', 'unsure'],
  'Brand':                   ['visible', 'not_visible', 'unsure'],
}

const FIELD_NAMES = Object.keys(VALID_LABELS)

export default defineEventHandler(async (event) => {
  const token = getCookie(event, 'eee_reviewer')
  const body = await readBody(event)
  const { messageid, attid, field, label, notes } = body

  if (!messageid || !attid || !field || !label) {
    throw createError({ statusCode: 400, statusMessage: 'Missing required fields: messageid, attid, field, label' })
  }

  if (!FIELD_NAMES.includes(field)) {
    throw createError({ statusCode: 400, statusMessage: `Invalid field. Must be one of: ${FIELD_NAMES.join(', ')}` })
  }

  const allowed = VALID_LABELS[field]
  if (!allowed.includes(label)) {
    throw createError({ statusCode: 400, statusMessage: `Invalid label "${label}" for field "${field}". Must be one of: ${allowed.join(', ')}` })
  }

  const db = openLabelsDb()
  try {
    const labeller = resolveLabeller(db, token)
    if (!labeller) {
      throw createError({ statusCode: 401, statusMessage: 'Not registered — please choose a reviewer name first' })
    }

    db.prepare(`
      INSERT OR REPLACE INTO eee_field_labels (messageid, attid, field, labeller, label, labelled_at, notes)
      VALUES (?, ?, ?, ?, ?, datetime('now'), ?)
    `).run(messageid, attid, field, labeller, label, notes || null)

    return { success: true, messageid, attid, field, label, labeller }
  } finally {
    db.close()
  }
})
