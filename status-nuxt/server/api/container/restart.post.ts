import { execSync } from 'child_process'

export default defineEventHandler(async (event) => {
  const body = await readBody(event)
  const { container } = body

  // Validate container name - must start with project prefix
  const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'
  if (!container || !container.startsWith(`${prefix}-`) || !/^[a-zA-Z0-9_-]+$/.test(container)) {
    throw createError({
      statusCode: 400,
      message: 'Invalid container name'
    })
  }

  try {
    console.log(`Restarting container: ${container}`)
    execSync(`docker restart ${container}`, { timeout: 30000 })

    return { success: true, message: `Container ${container} restarted successfully` }
  } catch (error: any) {
    console.error('Restart error:', error)
    throw createError({
      statusCode: 500,
      message: `Failed to restart container: ${error.message}`
    })
  }
})
