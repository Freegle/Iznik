import { execSync } from 'child_process'

export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const container = query.container as string

  if (!container) {
    throw createError({
      statusCode: 400,
      message: 'Missing container parameter'
    })
  }

  try {
    const stats = execSync(
      `docker stats ${container} --no-stream --format "{{.CPUPerc}}"`,
      { encoding: 'utf8', timeout: 5000 }
    ).trim()

    const cpuPercent = parseFloat(stats.replace('%', '')) || 0

    return { cpu: cpuPercent, timestamp: Date.now() }
  } catch (error: any) {
    return { error: error.message, cpu: 0 }
  }
})
