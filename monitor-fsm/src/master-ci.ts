/**
 * Reading master's CI from GitHub commit statuses.
 *
 * A commit marked [skip ci] still gets a CircleCI pipeline, which CircleCI then cancels.
 * The cancellation lands on the commit as a status in state "error" whose description says
 * the tests were canceled, so the commit's combined state is "failure" although nothing
 * ran. On 2026-10-16 master's head was such a docs bot commit; check_master_ci called
 * master failing in three laps running, and each lap spent six to eight minutes in
 * FIX_MASTER_CI with nothing to fix. The verdict for master is the newest commit that
 * really ran.
 */

export type Status = { context: string; state: string; description?: string | null }
export type CommitStatuses = { sha: string; message?: string; statuses: Status[] }
export type Verdict = 'green' | 'failing' | 'pending' | 'cancelled' | 'none'

export function isCircleCi(s: Status): boolean {
  return s.context.startsWith('ci/circleci')
}

export function isCancelled(s: Status): boolean {
  return s.state === 'error' && /cancel/i.test(s.description ?? '')
}

/** The verdict of one commit's CircleCI statuses. */
export function masterCiVerdict(statuses: Status[]): Verdict {
  const circle = statuses.filter(isCircleCi)
  if (circle.length === 0) return 'none'
  if (circle.some(s => (s.state === 'failure' || s.state === 'error') && !isCancelled(s))) return 'failing'
  if (circle.some(s => s.state === 'pending')) return 'pending'
  if (circle.some(isCancelled)) return 'cancelled'
  return 'green'
}

export type MasterCi = {
  sha: string
  verdict: Verdict
  failing: boolean
  statuses: Status[]
  /** Commits newer than the one judged, skipped because nothing ran on them. */
  skippedShas: string[]
}

/**
 * Pick the commit whose statuses say what master's CI is: the newest one whose CircleCI
 * statuses are not all cancelled or absent. `commits` is newest first. When every commit
 * given was cancelled or never ran, the head is reported as it is, and it is not failing.
 */
export function pickMasterCi(commits: CommitStatuses[]): MasterCi {
  const skippedShas: string[] = []
  for (const c of commits) {
    const verdict = masterCiVerdict(c.statuses)
    if (verdict === 'cancelled' || verdict === 'none') { skippedShas.push(c.sha); continue }
    return { sha: c.sha, verdict, failing: verdict === 'failing', statuses: c.statuses, skippedShas }
  }
  const head = commits[0]
  if (!head) return { sha: '', verdict: 'none', failing: false, statuses: [], skippedShas }
  return { sha: head.sha, verdict: masterCiVerdict(head.statuses), failing: false, statuses: head.statuses, skippedShas: skippedShas.slice(1) }
}
