import { execSync } from 'child_process'
import { getDockerComposeCommand } from '../../utils/docker'

export default defineEventHandler(async () => {
  try {
    const DOCKER_COMPOSE = getDockerComposeCommand()
    const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'
    console.log(`Starting ${prefix}-dev-live container with dev-live profile...`)

    execSync(
      `${DOCKER_COMPOSE} --profile dev-live up -d ${prefix}-dev-live`,
      { timeout: 120000, cwd: '/project' }
    )

    return { success: true, message: 'Container starting' }
  } catch (error: any) {
    console.error('Start live container error:', error)
    throw createError({
      statusCode: 500,
      message: error.message
    })
  }
})
