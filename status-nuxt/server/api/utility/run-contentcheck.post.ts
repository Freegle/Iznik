import { execSync } from 'child_process'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

export default defineEventHandler(async () => {
  try {
    const output = execSync(
      `docker exec ${prefix}-batch php artisan messages:contentcheck`,
      { encoding: 'utf8', timeout: 30000 }
    )
    return { success: true, output: output.trim() }
  } catch (error: any) {
    return { success: false, error: error.message }
  }
})
