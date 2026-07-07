import { execSync } from 'child_process'

const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'

export default defineEventHandler(async () => {
  try {
    const results: string[] = []

    // Delete existing test users first to ensure clean recreate
    try {
      execSync(
        `docker exec ${prefix}-percona mysql -u root -piznik iznik -e "DELETE FROM users WHERE id IN (SELECT userid FROM (SELECT userid FROM users_emails WHERE email IN ('test@test.com', 'testmod@test.com')) AS subquery)"`,
        { encoding: 'utf8', timeout: 10000 }
      )
      results.push('Deleted existing test users')
    } catch (error: any) {
      results.push(`Warning: Failed to delete existing users - ${error.message}`)
    }

    // Recreate test@test.com
    try {
      const testUserResult = execSync(
        `docker exec ${prefix}-apiv1 php /var/www/iznik/scripts/cli/user_create.php -e test@test.com -n "Test User" -p freegle`,
        { encoding: 'utf8', timeout: 30000 }
      )
      results.push(`test@test.com: ${testUserResult.trim()}`)
    } catch (error: any) {
      results.push(`test@test.com: Failed - ${error.message}`)
    }

    // Recreate testmod@test.com
    try {
      const modUserResult = execSync(
        `docker exec ${prefix}-apiv1 php /var/www/iznik/scripts/cli/user_create.php -e testmod@test.com -n "Test Mod" -p freegle`,
        { encoding: 'utf8', timeout: 30000 }
      )
      results.push(`testmod@test.com: ${modUserResult.trim()}`)
    } catch (error: any) {
      results.push(`testmod@test.com: Failed - ${error.message}`)
    }

    // Grant Support role to testmod@test.com
    try {
      execSync(
        `docker exec ${prefix}-percona mysql -u root -piznik iznik -e "UPDATE users SET systemrole = 'Support' WHERE id = (SELECT userid FROM users_emails WHERE email = 'testmod@test.com')"`,
        { encoding: 'utf8', timeout: 10000 }
      )
      results.push('testmod@test.com: Granted Support role')
    } catch (error: any) {
      results.push(`testmod@test.com: Failed to grant Support role - ${error.message}`)
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
