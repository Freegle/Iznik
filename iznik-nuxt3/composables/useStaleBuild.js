import { STALE_BUILD_HARD_MS, STALE_BUILD_SOFT_MS } from '~/constants'

/**
 * Decide how to react to a stale build, given that a newer production deploy is
 * known to exist (the caller checks the deploy id mismatch first).
 *
 * - 'hard': the bundle we're actually running (buildDate) is older than the hard
 *   threshold (a week), so we should force a reload.
 * - 'soft': the newer deploy has been live longer than the soft threshold (12h),
 *   so we softly nag — but not right after a release.
 * - 'none': too soon to do anything yet.
 *
 * Fails open: unparseable dates never trigger 'hard'/'soft'.
 *
 * @param {string} buildDate  ISO BUILD_DATE baked into the running bundle.
 * @param {string} deployDate ISO publish time of the newer deploy.
 * @param {number} now        Current time in epoch ms.
 * @returns {'hard'|'soft'|'none'}
 */
export function classifyStaleBuild(buildDate, deployDate, now) {
  const buildAge = now - new Date(buildDate).getTime()
  if (!Number.isNaN(buildAge) && buildAge > STALE_BUILD_HARD_MS) {
    return 'hard'
  }

  const deployMs = new Date(deployDate).getTime()
  if (!Number.isNaN(deployMs) && deployMs < now - STALE_BUILD_SOFT_MS) {
    return 'soft'
  }

  return 'none'
}
