import { openLabelsDb } from '~/server/utils/labelsDb'

/**
 * Receive a batch of micro-volunteering labels from iznik-batch and write
 * them into eee_field_labels. Authenticated by a shared secret in
 * `EEE_SYNC_SECRET`. Each row is upserted, so the iznik-batch syncer can
 * safely re-send overlapping ranges.
 */
interface SyncLabel {
  messageid: number
  attid: number
  field: string         // 'Condition' | 'Weight (kg)' | 'Size'
  labeller: string      // 'mv-<userid>' so each MV user is its own labeller
  label: string         // value from the agreed vocabulary (see Go validators)
  labelled_at?: string  // ISO timestamp; defaults to now
}

const VALID_FIELDS = new Set(['EEE', 'Electrical components', 'Photo quality', 'Condition', 'Weight (kg)', 'Size', 'Value band'])

export default defineEventHandler(async (event) => {
  const secret = getHeader(event, 'x-eee-sync-secret') ||
                 (getQuery(event).secret as string | undefined)
  if (!secret || secret !== process.env.EEE_SYNC_SECRET) {
    throw createError({ statusCode: 401, statusMessage: 'Unauthorized' })
  }

  const body = await readBody(event)
  const labels: SyncLabel[] = body?.labels
  if (!Array.isArray(labels)) {
    throw createError({ statusCode: 400, statusMessage: 'Body must be { labels: [...] }' })
  }

  const db = openLabelsDb()
  try {
    const stmt = db.prepare(`
      INSERT INTO eee_field_labels (messageid, attid, field, labeller, label, labelled_at, notes)
      VALUES (?, ?, ?, ?, ?, COALESCE(?, datetime('now')), NULL)
      ON CONFLICT (messageid, attid, field, labeller) DO UPDATE SET
        label = excluded.label,
        labelled_at = excluded.labelled_at
    `)

    let inserted = 0
    let rejected = 0
    const txn = db.transaction((rows: SyncLabel[]) => {
      for (const r of rows) {
        if (!VALID_FIELDS.has(r.field) ||
            !r.labeller || !r.label ||
            !Number.isInteger(r.messageid) || !Number.isInteger(r.attid)) {
          rejected++
          continue
        }
        stmt.run(r.messageid, r.attid, r.field, r.labeller, r.label, r.labelled_at ?? null)
        inserted++
      }
    })
    txn(labels)

    return { inserted, rejected, total: labels.length }
  } finally {
    db.close()
  }
})
