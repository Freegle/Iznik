/**
 * Classification of "coverage-jitter" CI checks.
 *
 * Coveralls posts a per-suite coverage-delta status ("Coveralls - laravel",
 * "Coveralls - vitest", "coverage/coveralls") that fails whenever a suite's
 * GLOBAL coverage dips below its base commit — even by sub-0.1% run-to-run
 * measurement noise, and even when the PR's own changed lines are 100% covered
 * (or the PR doesn't touch that suite at all).
 *
 * The real test gate ("ci/circleci: build-and-test") is a SEPARATE check. So a
 * PR whose ONLY failing checks are coverage checks has PASSING tests — it isn't
 * a CI failure to "fix", it's a signal to add genuine coverage somewhere real
 * until the suite clears the noise floor. Treating these as ordinary red CI
 * makes the FSM thrash fix-attempts and then mark the PR "exhausted", starving
 * the rest of its work. We classify them out of the red-CI path and route them
 * to the coverage booster (WRITE_COVERAGE) instead.
 */

export interface FailedCheck {
  context: string
  state: string
  url: string
}

/**
 * True when a check name is a Coveralls coverage-delta status (the noise-prone
 * gate), as opposed to a real test/build/lint check.
 */
export function isCoverageJitterCheck(name: string): boolean {
  return /coveralls/i.test(name) || /^coverage\//i.test(name)
}

/**
 * True when a check is the guard that fails BECAUSE the PR carries a blocking
 * label (.github/workflows/blocked-label-guard.yml).
 *
 * This one is not noise like the coverage delta — it is the guard doing its job.
 * The PR is marked "do not merge" deliberately, so there is nothing to repair
 * and no amount of work will turn the check green while the label is on. Left in
 * the red-CI path it is worse than useless: each such PR takes the iteration's
 * single fix slot, three times over, before being written off as exhausted. On
 * 2026-09-22 every PR in the red list was red on this and nothing else — five of
 * them, so up to fifteen delegate runs aimed at a guard that was working.
 *
 * Matched exactly, not as a substring: "guard" is the workflow that runs this
 * check and passes on ordinary PRs, and a real failure in a job that merely
 * mentions the phrase must stay visible.
 */
export function isDeliberateBlockCheck(name: string): boolean {
  return /^\s*blocked-label\s*$/i.test(name)
}

/**
 * Split a PR's failed checks into genuine failures, coverage-delta failures, and
 * deliberate blocks.
 *
 * A PR is "coverage-jitter only" when realFailed is empty but coverageFailed
 * is not — its tests pass and it merely needs more coverage to clear the noise.
 * A PR is deliberately blocked when realFailed is empty but blockedFailed is
 * not — it is parked on purpose and the monitor should leave it alone.
 *
 * A blocked PR that is ALSO genuinely broken keeps its real failures: the label
 * says do not merge it, not stop testing it.
 */
export function partitionFailedChecks(failed: FailedCheck[]): {
  realFailed: FailedCheck[]
  coverageFailed: FailedCheck[]
  blockedFailed: FailedCheck[]
} {
  const realFailed: FailedCheck[] = []
  const coverageFailed: FailedCheck[] = []
  const blockedFailed: FailedCheck[] = []
  for (const f of failed) {
    if (isDeliberateBlockCheck(f.context)) blockedFailed.push(f)
    else if (isCoverageJitterCheck(f.context)) coverageFailed.push(f)
    else realFailed.push(f)
  }
  return { realFailed, coverageFailed, blockedFailed }
}

/**
 * The coverage booster's target test: true only when a PR's tests pass, a Coveralls
 * delta is red, and nobody has parked the PR.
 *
 * A PR carrying a blocking label is deliberately held. Its coverage dip is real, but
 * clearing it means pushing commits to a branch the author has asked everyone to leave
 * alone, often one under review. So a blocked PR is never coverage jitter, whatever
 * else is red on it. On 2026-10-16 the action handed the booster three such PRs.
 */
export function isCoverageJitterOnly(failed: FailedCheck[]): boolean {
  const { realFailed, coverageFailed, blockedFailed } = partitionFailedChecks(failed)
  return realFailed.length === 0 && blockedFailed.length === 0 && coverageFailed.length > 0
}

/**
 * The labels that park a PR, as .github/workflows/blocked-label-guard.yml lists them
 * (BLOCKING_LABELS, matched case-insensitively). Keep the two in step.
 */
const BLOCKING_LABELS = ['blocked']

/**
 * True when a PR carries a label that means "not for merge". Such a PR is parked on
 * purpose: the monitor neither raises its coverage nor rebases it. The framework PRs
 * that carry it are stacked on one another, so a force-pushed rebase of one onto
 * master would break every branch above it.
 */
export function hasBlockingLabel(labels: string[]): boolean {
  return labels.some(l => BLOCKING_LABELS.includes(l.trim().toLowerCase()))
}

/**
 * The red suites (laravel / vitest / go / playwright) a PR's coverage checks
 * are failing on, parsed from "Coveralls - <suite>" check names. Used to tell
 * the coverage booster which suite to raise.
 */
export function redCoverageSuites(coverageFailed: FailedCheck[]): string[] {
  const suites = new Set<string>()
  for (const f of coverageFailed) {
    const m = f.context.match(/coveralls\s*[-:]\s*(\w+)/i)
    if (m) suites.add(m[1].toLowerCase())
  }
  return [...suites]
}
