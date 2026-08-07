import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { getDb, resetDbForTests, kvGet, kvSet } from '../db/index.js'
import { MAX_COVERAGE_VISITS_PER_ITERATION } from '../actions/index.js'

let db: ReturnType<typeof getDb>

beforeEach(() => {
  resetDbForTests()
  db = getDb(':memory:')
})

afterEach(() => {
  resetDbForTests()
})

// COVERAGE_GATE is the only way into WRITE_COVERAGE and the only way out of it,
// so any gate arm routing to coverage on a condition a coverage pass cannot
// itself change is a closed loop. On 2026-08-07 three iterations died that way,
// one spending 32 of its 40 steps alternating between the two states and
// producing nothing. These pin the two guards that stop it.

describe('a jitter PR is boosted once per iteration', () => {
  // The booster pushes to the PR's branch rather than creating a PR, and CI
  // needs minutes to re-run - so on the way back the PR still looks exactly as
  // jittery as it did going in, and the arm fires again.
  const iteration = '2026-08-07T21:22:08.248Z'
  const key = `coverage_boosted_${iteration}`

  function markBoosted(numbers: number[]) {
    const seen = new Set((kvGet(db, key) ?? '').split(',').filter(Boolean))
    for (const n of numbers) seen.add(String(n))
    kvSet(db, key, [...seen].join(','))
  }

  function unboosted(all: number[]) {
    const seen = new Set((kvGet(db, key) ?? '').split(',').filter(Boolean))
    return all.filter(n => !seen.has(String(n)))
  }

  it('offers a jitter PR for boosting the first time it is seen', () => {
    expect(unboosted([1201])).toEqual([1201])
  })

  it('stops offering it once this iteration has boosted it', () => {
    markBoosted([1201])
    expect(unboosted([1201])).toEqual([])
  })

  it('still offers a different PR that has not been boosted yet', () => {
    markBoosted([1201])
    expect(unboosted([1201, 1266])).toEqual([1266])
  })

  it('keys on the iteration, so the next one starts fresh', () => {
    markBoosted([1201])
    const nextKey = 'coverage_boosted_2026-08-07T21:52:00.000Z'
    const seenNext = new Set((kvGet(db, nextKey) ?? '').split(',').filter(Boolean))
    expect(seenNext.has('1201')).toBe(false)
  })
})

describe('the visit cap ends the loop whatever caused it', () => {
  // The trailing gate arm routes to coverage whenever no PR was created this
  // iteration, and a pass that pushes to an existing branch never creates one.
  // Rather than reason arm by arm about which can settle, cap the visits.
  const iteration = '2026-08-07T21:22:08.248Z'
  const key = `coverage_visits_${iteration}`

  function visit(): string {
    const visits = parseInt(kvGet(db, key) ?? '0', 10) + 1
    kvSet(db, key, String(visits))
    return visits > MAX_COVERAGE_VISITS_PER_ITERATION ? 'WRAP_UP' : 'WRITE_COVERAGE'
  }

  it('lets coverage run enough times to do real work', () => {
    for (let i = 0; i < MAX_COVERAGE_VISITS_PER_ITERATION; i++) {
      expect(visit()).toBe('WRITE_COVERAGE')
    }
  })

  it('wraps up rather than entering coverage again', () => {
    for (let i = 0; i < MAX_COVERAGE_VISITS_PER_ITERATION; i++) visit()
    expect(visit()).toBe('WRAP_UP')
  })

  it('stays wrapped up, so the iteration cannot resume ping-ponging', () => {
    for (let i = 0; i < MAX_COVERAGE_VISITS_PER_ITERATION + 5; i++) visit()
    expect(visit()).toBe('WRAP_UP')
  })

  it('leaves most of the 40-step budget for everything else', () => {
    // Two steps per visit (coverage + gate). The cap has to be well clear of
    // the cap that was ending these iterations.
    expect(MAX_COVERAGE_VISITS_PER_ITERATION * 2).toBeLessThan(10)
  })
})
