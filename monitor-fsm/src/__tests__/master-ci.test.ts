import { describe, it, expect } from 'vitest'
import { masterCiVerdict, pickMasterCi, type CommitStatuses } from '../master-ci'

// On 2026-10-16 master's head was a docs bot commit marked [skip ci]. CircleCI opened a
// pipeline for it and cancelled it, which left "ci/circleci: build-and-test" and
// "ci/circleci: check-raw-sql" on that commit in state error with the description
// "Your CircleCI tests were canceled". The combined state of the commit was failure, so
// check_master_ci said master was failing in three laps running, and each spent six to
// eight minutes in FIX_MASTER_CI finding nothing to fix. The last real run, on the
// commit before, was green.

const st = (context: string, state: string, description = '') => ({ context, state, description })
const cancelled = (context: string) => st(context, 'error', 'Your CircleCI tests were canceled')

describe('masterCiVerdict', () => {
  it('is green when every CircleCI status succeeded', () => {
    expect(masterCiVerdict([st('ci/circleci: build-and-test', 'success'), st('ci/circleci: check-runner', 'success')])).toBe('green')
  })

  it('is failing on a real failure', () => {
    expect(masterCiVerdict([st('ci/circleci: build-and-test', 'failure', 'Your tests failed on CircleCI!')])).toBe('failing')
  })

  it('is failing on an error that is not a cancellation', () => {
    expect(masterCiVerdict([st('ci/circleci: build-and-test', 'error', 'Your CircleCI tests failed to start')])).toBe('failing')
  })

  it('is pending while a run is still going', () => {
    expect(masterCiVerdict([st('ci/circleci: build-and-test', 'pending'), st('ci/circleci: check-runner', 'success')])).toBe('pending')
  })

  it('is cancelled, not failing, when the only non-green statuses are cancellations', () => {
    expect(masterCiVerdict([
      st('ci/circleci: check-runner', 'success'),
      cancelled('ci/circleci: build-and-test'),
      cancelled('ci/circleci: check-raw-sql'),
    ])).toBe('cancelled')
  })

  it('ignores statuses that are not CircleCI', () => {
    expect(masterCiVerdict([st('Coveralls - go', 'failure', 'Coverage decreased'), st('ci/circleci: build-and-test', 'success')])).toBe('green')
  })

  it('has no verdict for a commit with no CircleCI status at all', () => {
    expect(masterCiVerdict([st('netlify/deploy-preview', 'success')])).toBe('none')
  })
})

describe('pickMasterCi', () => {
  const head: CommitStatuses = { sha: 'c6bab3dfe', message: 'docs: regenerate screenshots [skip ci]', statuses: [st('ci/circleci: check-runner', 'success'), cancelled('ci/circleci: build-and-test'), cancelled('ci/circleci: check-raw-sql')] }
  const previous: CommitStatuses = { sha: 'd8d874614', message: 'Merge pull request #1800', statuses: [st('ci/circleci: build-and-test', 'success'), st('ci/circleci: check-raw-sql', 'success'), st('ci/circleci: check-runner', 'success')] }

  it('reads a cancelled head as the verdict of the newest commit that really ran', () => {
    const picked = pickMasterCi([head, previous])
    expect(picked.sha).toBe('d8d874614')
    expect(picked.verdict).toBe('green')
    expect(picked.failing).toBe(false)
    expect(picked.skippedShas).toEqual(['c6bab3dfe'])
  })

  it('keeps walking back past several commits that never ran', () => {
    const skipped: CommitStatuses = { sha: 'aaaaaaaaa', message: 'docs: tidy [skip ci]', statuses: [] }
    const red: CommitStatuses = { ...previous, sha: 'bbbbbbbbb', statuses: [st('ci/circleci: build-and-test', 'failure', 'Your tests failed on CircleCI!')] }
    const picked = pickMasterCi([head, skipped, red])
    expect(picked.sha).toBe('bbbbbbbbb')
    expect(picked.failing).toBe(true)
    expect(picked.skippedShas).toEqual(['c6bab3dfe', 'aaaaaaaaa'])
  })

  it('uses the head itself when it has a real result', () => {
    const picked = pickMasterCi([previous, head])
    expect(picked.sha).toBe('d8d874614')
    expect(picked.skippedShas).toEqual([])
  })

  it('does not call a cancelled head failing when nothing older is known', () => {
    const picked = pickMasterCi([head])
    expect(picked.sha).toBe('c6bab3dfe')
    expect(picked.verdict).toBe('cancelled')
    expect(picked.failing).toBe(false)
  })

  it('still reports a real failure on the head', () => {
    const redHead: CommitStatuses = { ...head, statuses: [st('ci/circleci: build-and-test', 'failure', 'Your tests failed on CircleCI!')] }
    expect(pickMasterCi([redHead, previous]).failing).toBe(true)
  })
})
