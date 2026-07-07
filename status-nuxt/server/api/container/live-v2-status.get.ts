import { readFileSync } from 'fs'
import { isContainerRunning } from '../../utils/docker'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

/**
 * Returns the current state of the live V2 API toggle.
 * Reads .env (mounted at /app/dotenv) to determine which dev-live
 * containers are using the local apiv2-live vs the live V2 API.
 */
export default defineEventHandler(async () => {
  const envPath = '/app/dotenv'
  const localLiveUrl = 'http://apiv2-live.localhost/api'

  let freegleEnabled = false
  let modtoolsEnabled = false
  let liveDbPort = '1234'

  try {
    const envContent = readFileSync(envPath, 'utf8')

    const freegleMatch = envContent.match(/^LIVE_FREEGLE_API_V2=(.*)$/m)
    freegleEnabled = freegleMatch?.[1]?.trim() === localLiveUrl

    const modtoolsMatch = envContent.match(/^LIVE_MT_API_V2=(.*)$/m)
    modtoolsEnabled = modtoolsMatch?.[1]?.trim() === localLiveUrl

    const portMatch = envContent.match(/^LIVE_DB_PORT=(.*)$/m)
    if (portMatch?.[1]?.trim()) {
      liveDbPort = portMatch[1].trim()
    }
  } catch {
    // .env not readable, assume defaults
  }

  const apiv2LiveRunning = isContainerRunning(`${prefix}-apiv2-live`)

  return {
    freegle: freegleEnabled,
    modtools: modtoolsEnabled,
    apiv2LiveRunning,
    liveDbPort,
  }
})
