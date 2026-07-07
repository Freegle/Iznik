import { writeFileSync, existsSync, unlinkSync } from 'fs'

export default defineEventHandler(async (event) => {
  const body = await readBody(event)
  const { container, service } = body

  // Validate container name
  const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'
  if (!container || !container.startsWith(`${prefix}-`) || !/^[a-zA-Z0-9_-]+$/.test(container)) {
    throw createError({
      statusCode: 400,
      message: 'Invalid container name'
    })
  }

  // Validate service name
  if (!service || !/^[a-zA-Z0-9_-]+$/.test(service)) {
    throw createError({
      statusCode: 400,
      message: 'Invalid service name'
    })
  }

  try {
    console.log(`Rebuilding service: ${service} (${container})`)

    // Write rebuild request to shared volume for host to process
    const rebuildRequest = {
      service,
      container,
      timestamp: Date.now(),
      id: Math.random().toString(36).substring(2, 11),
    }

    const requestFile = `/rebuild-requests/rebuild-${rebuildRequest.id}.json`
    writeFileSync(requestFile, JSON.stringify(rebuildRequest))

    // Wait for completion or timeout
    let completed = false
    let attempts = 0
    const maxAttempts = 60 // 5 minutes max

    while (!completed && attempts < maxAttempts) {
      await new Promise(resolve => setTimeout(resolve, 5000)) // Wait 5 seconds
      attempts++

      // Check if request file was processed (removed by host script)
      if (!existsSync(requestFile)) {
        completed = true
        break
      }
    }

    if (!completed) {
      // Clean up and timeout
      try {
        unlinkSync(requestFile)
      } catch {}
      throw new Error('Rebuild request timed out')
    }

    return { success: true, message: `Service ${service} rebuilt and restarted successfully` }
  } catch (error: any) {
    console.error('Rebuild error:', error)
    throw createError({
      statusCode: 500,
      message: `Failed to rebuild service: ${error.message}`
    })
  }
})
