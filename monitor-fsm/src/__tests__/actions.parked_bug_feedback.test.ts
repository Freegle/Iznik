import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { getDb, resetDbForTests, upsertDiscourseBug } from '../db/index.js'
import { selectParkedBugsForFeedback, REVIVED_BUG_REJECTIONS } from '../actions/index.js'

let db: ReturnType<typeof getDb>

beforeEach(() => {
  resetDbForTests()
  db = getDb(':memory:')
})

afterEach(() => {
  resetDbForTests()
})

function bug(topic: number, state: string, reporter = 'derek') {
  upsertDiscourseBug(db, { topic, post: 1, topicTitle: 't', reporter, excerpt: 'x', state })
}

describe('which parked bugs get re-read', () => {
  // The scan used to cover 'open' and 'investigating' only, so a bug parked in
  // any other waiting state was never looked at again however much its thread
  // moved on. Three real reports sat that way for weeks.
  it('includes the states that were being stranded', () => {
    bug(99700, 'confirmed')
    bug(99701, 'fix-queued')
    bug(99702, 'deferred')

    const picked = selectParkedBugsForFeedback(db).map(b => b.topic)
    expect(picked).toEqual(expect.arrayContaining([99700, 99701, 99702]))
  })

  it('still includes the states it always covered', () => {
    bug(99703, 'open')
    bug(99704, 'investigating')

    const picked = selectParkedBugsForFeedback(db).map(b => b.topic)
    expect(picked).toEqual(expect.arrayContaining([99703, 99704]))
  })

  it('leaves settled bugs alone', () => {
    bug(99705, 'fixed')
    bug(99706, 'off-topic')
    bug(99707, 'duplicate')
    bug(99708, 'feature-request')

    expect(selectParkedBugsForFeedback(db)).toHaveLength(0)
  })
})

describe('how much evidence closing one takes', () => {
  // The confirmation regex matches a bare "thanks" from any participant. That is
  // acceptable for a bug nobody has fixed yet, but a bug already marked
  // confirmed or fix-queued has a fix believed to exist or a PR open against it,
  // and closing that on a passing "thanks" from a bystander is worse than
  // leaving it parked.
  it('demands the reporter for states that already assume a fix', () => {
    bug(99710, 'confirmed')
    bug(99711, 'fix-queued')
    bug(99712, 'deferred')

    for (const b of selectParkedBugsForFeedback(db)) expect(b.requireReporter).toBe(true)
  })

  it('keeps the looser rule where no fix is assumed', () => {
    bug(99713, 'open')
    bug(99714, 'investigating')

    for (const b of selectParkedBugsForFeedback(db)) expect(b.requireReporter).toBe(false)
  })

  it('carries the reporter through, since the strict rule is matched against it', () => {
    bug(99715, 'confirmed', 'Neville_Reid')

    const picked = selectParkedBugsForFeedback(db)
    expect(picked[0].reporter).toBe('Neville_Reid')
    expect(picked[0].state).toBe('confirmed')
  })
})

describe('an escalated bug can come back when its thread moves on', () => {
  // work_router escalates at 2 rejected PRs and only ever dispatches 'open', so
  // 'deferred' was a grave: 7 bugs sat there for weeks while their threads
  // gained exactly the detail that would have changed the diagnosis.
  it('carries last_seen_at, which is what "new since we escalated" is measured against', () => {
    bug(99720, 'deferred')
    db.prepare("UPDATE discourse_bug SET last_seen_at='2026-07-18 10:00:00' WHERE topic=99720").run()

    const picked = selectParkedBugsForFeedback(db).find(b => b.topic === 99720)
    expect(picked?.lastSeenAt).toBe('2026-07-18 10:00:00')
  })

  it('leaves one attempt, so new evidence buys a retry without wiping the history', () => {
    // Clearing the count would lose what DIAGNOSE_BUG reads to make the next
    // approach differ; leaving it at 2 would re-escalate the bug on sight.
    expect(REVIVED_BUG_REJECTIONS).toBe(1)
    expect(REVIVED_BUG_REJECTIONS).toBeLessThan(2)
  })

  it('revives via MIN, so a bug with fewer rejections keeps its lower count', () => {
    bug(99721, 'deferred')
    db.prepare('UPDATE discourse_bug SET pr_rejections=0 WHERE topic=99721').run()
    db.prepare('UPDATE discourse_bug SET pr_rejections=MIN(pr_rejections, ?) WHERE topic=99721')
      .run(REVIVED_BUG_REJECTIONS)

    const row = db.prepare('SELECT pr_rejections FROM discourse_bug WHERE topic=99721').get() as { pr_rejections: number }
    expect(row.pr_rejections).toBe(0)
  })
})
