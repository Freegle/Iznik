import { randomBytes } from 'crypto'
import { openLabelsDb } from '~/server/utils/labelsDb'

export default defineEventHandler(async (event) => {
  const body = await readBody(event)
  const rawName = (body?.name || '').toString().trim()

  if (!rawName) {
    throw createError({ statusCode: 400, statusMessage: 'Name is required' })
  }

  // Sanitize: letters, digits, hyphens, underscores; max 32 chars
  const name = rawName.toLowerCase().replace(/[^a-z0-9_-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').slice(0, 32)
  if (!name) {
    throw createError({ statusCode: 400, statusMessage: 'Name must contain at least one letter or digit' })
  }

  const db = openLabelsDb()

  try {
    const existing = db.prepare('SELECT token FROM eee_reviewers WHERE name = ?').get(name) as any
    if (existing) {
      throw createError({ statusCode: 409, statusMessage: `The name "${name}" is already taken — choose another` })
    }

    const token = randomBytes(24).toString('hex')
    db.prepare('INSERT INTO eee_reviewers (name, token) VALUES (?, ?)').run(name, token)

    setCookie(event, 'eee_reviewer', token, {
      httpOnly: true,
      sameSite: 'lax',
      maxAge: 60 * 60 * 24 * 365,
      path: '/',
    })

    return { name }
  } finally {
    db.close()
  }
})
