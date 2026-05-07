// Shared in-memory cache for test environments created by create-test-env.php.
// Exported so playwright.post.ts can clear it after a DB reset, preventing
// stale postcode/ID data from being served to freeze-retry test runs.

export const testEnvCache: Record<string, any> = {}
export const testEnvPending: Record<string, Promise<any>> = {}

export function clearTestEnvCache(): void {
  for (const key of Object.keys(testEnvCache)) {
    delete testEnvCache[key]
  }
  for (const key of Object.keys(testEnvPending)) {
    delete testEnvPending[key]
  }
}
