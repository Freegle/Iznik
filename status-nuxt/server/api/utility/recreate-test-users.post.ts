import { execSync } from 'child_process'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

// Recreate the shared test users (test@test.com / testmod@test.com) by deleting them and
// reloading the captured fixtures (scripts/test-fixtures.sql), which restore the users along
// with their memberships, roles, isochrones and chats exactly as seeded.
export default defineEventHandler(async () => {
  try {
    const results: string[] = []

    // Delete existing test users first so the fixture reload recreates them cleanly.
    try {
      execSync(
        `docker exec ${prefix}-percona mysql -u root -piznik iznik -e "DELETE FROM users WHERE id IN (SELECT userid FROM (SELECT userid FROM users_emails WHERE email IN ('test@test.com', 'testmod@test.com')) AS subquery)"`,
        { encoding: 'utf8', timeout: 10000 }
      )
      results.push('Deleted existing test users')
    } catch (error: any) {
      results.push(`Warning: Failed to delete existing users - ${error.message}`)
    }

    // Reload the captured fixtures to recreate the test users (idempotent INSERT IGNORE).
    try {
      execSync(
        `docker cp /project/scripts/test-fixtures.sql ${prefix}-percona:/tmp/test-fixtures.sql && docker exec ${prefix}-percona sh -c "mysql -u root -piznik iznik < /tmp/test-fixtures.sql"`,
        { encoding: 'utf8', timeout: 120000 }
      )
      results.push('Reloaded test fixtures (test@test.com, testmod@test.com recreated)')
    } catch (error: any) {
      results.push(`Failed to reload fixtures - ${error.message}`)
    }

    return {
      success: true,
      message: 'Users recreated successfully',
      details: results,
    }
  } catch (error: any) {
    throw createError({
      statusCode: 500,
      message: `Failed to recreate users: ${error.message}`
    })
  }
})
