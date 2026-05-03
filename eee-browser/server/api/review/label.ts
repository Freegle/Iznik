import { openLabelsDb, resolveLabeller } from '~/server/utils/labelsDb'

const BUCKET_LABELS = ['under_1kg', '1_5kg', '5_20kg', '20_100kg', 'over_100kg']
const VALID_LABELS = ['eee', 'not_eee', 'unsure', 'correct', 'incorrect', ...BUCKET_LABELS]
const FIELD_NAMES = ['EEE', 'Electrical components', 'Photo quality', 'Condition', 'Weight (kg)', 'Value band', 'Brand']

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

  if (!VALID_LABELS.includes(label)) {
    throw createError({ statusCode: 400, statusMessage: `Invalid label. Must be one of: ${VALID_LABELS.join(', ')}` })
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
