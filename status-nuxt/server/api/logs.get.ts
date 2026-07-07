import { execSync } from 'child_process'

export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const container = query.container as string
  const lines = parseInt(query.lines as string) || 100

  if (!container) {
    throw createError({
      statusCode: 400,
      message: 'Container name is required',
    })
  }

  // Validate container name to prevent injection
  if (!/^[a-zA-Z0-9_-]+$/.test(container)) {
    throw createError({
      statusCode: 400,
      message: 'Invalid container name',
    })
  }

  try {
    const logs = execSync(
      `docker logs --tail ${lines} ${container} 2>&1`,
      { encoding: 'utf8', timeout: 10000 }
    )
    return { logs }
  }
  catch (err: any) {
    // If the command failed, return the error output
    if (err.stdout || err.stderr) {
      return { logs: err.stdout || err.stderr || 'No logs available' }
    }
    throw createError({
      statusCode: 500,
      message: `Failed to get logs: ${err.message}`,
    })
  }
})
