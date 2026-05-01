import { exec } from 'child_process'
import { promisify } from 'util'
import { testEnvCache, testEnvPending } from '../../../utils/testEnvCache'

const execAsync = promisify(exec)

// On-demand test environment creation. Called by the Playwright testEnv fixture.
// GET /api/tests/env/:prefix
// Returns the JSON from create-test-env.php for the given prefix.
// Idempotent: reuses existing data if prefix already exists.
// Uses async exec so concurrent requests from 11 Playwright workers
// don't block the Node.js event loop.
//
// The cache is shared with playwright.post.ts via testEnvCache so it can be
// cleared after a freeze-retry DB reset (stale postcode/IDs cause test failures).

export default defineEventHandler(async (event) => {
  const prefix = getRouterParam(event, 'prefix')

  if (!prefix || !/^[a-z0-9]+$/.test(prefix)) {
    throw createError({ statusCode: 400, message: 'Invalid prefix' })
  }

  // Return cached result if already created this session
  if (testEnvCache[prefix]) {
    return testEnvCache[prefix]
  }

  // Deduplicate concurrent requests for the same prefix
  if (!testEnvPending[prefix]) {
    testEnvPending[prefix] = (async () => {
      const { stdout } = await execAsync(
        `docker exec ${process.env.COMPOSE_PROJECT_NAME || 'freegle'}-apiv1 php /var/www/iznik/install/create-test-env.php ${prefix}`,
        { encoding: 'utf8', timeout: 60000 }
      )
      const env = JSON.parse(stdout.trim())
      testEnvCache[prefix] = env
      delete testEnvPending[prefix]
      return env
    })()
  }

  try {
    return await testEnvPending[prefix]
  } catch (e: any) {
    delete testEnvPending[prefix]
    console.error(`Failed to create test env for ${prefix}:`, e.message)
    throw createError({
      statusCode: 500,
      message: `Failed to create test environment: ${e.message}`,
    })
  }
})
