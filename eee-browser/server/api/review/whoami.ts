import { openLabelsDb, resolveLabeller } from '~/server/utils/labelsDb'

export default defineEventHandler(async (event) => {
  const token = getCookie(event, 'eee_reviewer')
  if (!token) return { name: null }

  const db = openLabelsDb()
  try {
    const name = resolveLabeller(db, token)
    return { name }
  } finally {
    db.close()
  }
})
