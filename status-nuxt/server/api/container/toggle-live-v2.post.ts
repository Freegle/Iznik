import { readFileSync, writeFileSync, existsSync, unlinkSync } from 'fs'
import { isContainerRunning } from '../../utils/docker'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

/**
 * Toggle the V2 API for a dev-live container between the live (remote)
 * V2 API and the local apiv2-live container (connected to production DB
 * via tunnel).
 *
 * Body: { target: 'freegle' | 'modtools', enable: boolean }
 *
 * Updates .env (mounted at /app/dotenv) then uses the rebuild-request
 * mechanism to have the host-scripts container recreate the dev-live
 * container with the new env.
 */
export default defineEventHandler(async (event) => {
  const body = await readBody(event)
  const { target, enable } = body

  if (!target || !['freegle', 'modtools'].includes(target)) {
    throw createError({ statusCode: 400, message: 'target must be "freegle" or "modtools"' })
  }

  if (typeof enable !== 'boolean') {
    throw createError({ statusCode: 400, message: 'enable must be a boolean' })
  }

  const envPath = '/app/dotenv'
  const envKey = target === 'freegle' ? 'LIVE_FREEGLE_API_V2' : 'LIVE_MT_API_V2'
  const containerService = target === 'freegle' ? `${prefix}-dev-live` : `${prefix}-modtools-dev-live`
  const remoteUrl = 'https://api.ilovefreegle.org/apiv2'
  const localLiveUrl = 'http://apiv2-live.localhost/api'

  try {
    // Read current .env
    let envContent = readFileSync(envPath, 'utf8')

    // Update the env var for this target
    const newValue = enable ? localLiveUrl : remoteUrl
    const regex = new RegExp(`^${envKey}=.*$`, 'm')
    if (regex.test(envContent)) {
      envContent = envContent.replace(regex, `${envKey}=${newValue}`)
    } else {
      envContent += `\n${envKey}=${newValue}\n`
    }

    writeFileSync(envPath, envContent)

    // If enabling and apiv2-live isn't running, request a rebuild to start it
    if (enable && !isContainerRunning(`${prefix}-apiv2-live`)) {
      console.log('Requesting apiv2-live rebuild...')
      const apiv2Request = {
        service: 'apiv2-live',
        container: `${prefix}-apiv2-live`,
        timestamp: Date.now(),
        id: Math.random().toString(36).substring(2, 11),
      }
      writeFileSync(
        `/rebuild-requests/rebuild-${apiv2Request.id}.json`,
        JSON.stringify(apiv2Request)
      )
    }

    // Request rebuild of the dev-live container to pick up new env
    console.log(`Requesting rebuild of ${containerService}...`)
    const rebuildRequest = {
      service: containerService,
      container: containerService,
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
      await new Promise(resolve => setTimeout(resolve, 5000))
      attempts++

      if (!existsSync(requestFile)) {
        completed = true
        break
      }
    }

    if (!completed) {
      try { unlinkSync(requestFile) } catch {}
      throw new Error('Toggle timed out waiting for container rebuild')
    }

    return {
      success: true,
      message: `${target} V2 API ${enable ? 'switched to local' : 'switched to live'}`,
    }
  } catch (error: any) {
    console.error('Toggle live V2 error:', error)
    throw createError({
      statusCode: 500,
      message: error.message,
    })
  }
})
