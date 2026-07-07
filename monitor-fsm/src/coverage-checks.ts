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
 * Split a PR's failed checks into genuine failures vs coverage-delta failures.
 * A PR is "coverage-jitter only" when realFailed is empty but coverageFailed
 * is not — its tests pass and it merely needs more coverage to clear the noise.
 */
export function partitionFailedChecks(failed: FailedCheck[]): {
  realFailed: FailedCheck[]
  coverageFailed: FailedCheck[]
} {
  const realFailed: FailedCheck[] = []
  const coverageFailed: FailedCheck[] = []
  for (const f of failed) {
    if (isCoverageJitterCheck(f.context)) coverageFailed.push(f)
    else realFailed.push(f)
  }
  return { realFailed, coverageFailed }
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
