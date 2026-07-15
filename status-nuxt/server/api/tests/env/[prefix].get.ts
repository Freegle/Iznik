import { readFileSync } from 'fs'
import { testEnvCache } from '../../../utils/testEnvCache'

// On-demand test environment lookup. Called by the Playwright testEnv fixture.
// GET /api/tests/env/:prefix
//
// The per-prefix seeded environments (groups, moderators, users, messages, chats) are
// pre-seeded into the `iznik` DB by scripts/test-fixtures.sql (loaded by
// setup-test-database.sh). Their IDs are captured, keyed by spec prefix, in the committed
// iznik-nuxt3/tests/e2e/test-envs.json — this endpoint simply serves the matching entry.
//
// The cache is shared with playwright.post.ts via testEnvCache so it can be cleared after a
// freeze-retry DB reset; since the fixture reload restores the same ids, the values are stable.

const TEST_ENVS_PATH = '/project/iznik-nuxt3/tests/e2e/test-envs.json'

function loadTestEnvs(): Record<string, any> {
  try {
    return JSON.parse(readFileSync(TEST_ENVS_PATH, 'utf8'))
  } catch (e: any) {
    console.error(`Failed to read ${TEST_ENVS_PATH}: ${e.message}`)
    return {}
  }
}

export default defineEventHandler(async (event) => {
  const prefix = getRouterParam(event, 'prefix')

  if (!prefix || !/^[a-z0-9]+$/.test(prefix)) {
    throw createError({ statusCode: 400, message: 'Invalid prefix' })
  }

  if (testEnvCache[prefix]) {
    return testEnvCache[prefix]
  }

  const env = loadTestEnvs()[prefix]
  if (!env) {
    // No pre-seeded environment for this prefix — the Playwright fixture falls back to
    // the shared FreeglePlayground environment.
    throw createError({
      statusCode: 404,
      message: `No seeded test environment for prefix "${prefix}" in test-envs.json`,
    })
  }

  testEnvCache[prefix] = env
  return env
})
