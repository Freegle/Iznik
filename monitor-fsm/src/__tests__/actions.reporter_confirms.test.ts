import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { getDb, resetDbForTests, upsertDiscourseBug, getDiscourseBug } from '../db/index.js'

// A report fixed by hand rather than by the monitor stayed open for ever: the reporter's later "loaded
// instantly, thank you" only refreshed its timestamp, and a post classified "confirmed" was dropped
// (Discourse 10226/12). The reporter saying it works now closes their report.

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let persist: (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>
let db: ReturnType<typeof getDb>

beforeEach(async () => {
  resetDbForTests()
  db = getDb(':memory:')
  const { actions } = await import('../actions/index.js')
  persist = actions.find((a: any) => a.name === 'persist_classifications')!.handler
})

afterEach(() => {
  resetDbForTests()
})

function report(post: number, reporter: string, state: string) {
  upsertDiscourseBug(db, { topic: 10226, post, reporter, excerpt: `report ${post}`, state: state as any })
}

describe('the reporter confirming it works', () => {
  it('closes their report when the post is classified as confirmed', async () => {
    report(12, 'Sheila', 'investigating')

    await persist({}, { classifications: [{ topic: 10226, post: 24, type: 'confirmed', user: 'Sheila', originalPostText: 'Thank you, loaded instantly.' }] })

    expect(getDiscourseBug(db, 10226, 12)?.state).toBe('confirmed')
  })

  it('closes their report on a retest that says it works', async () => {
    report(12, 'Sheila', 'open')

    await persist({}, { classifications: [{ topic: 10226, post: 28, type: 'retest', user: 'Sheila', summary: "That's sorted it, thanks" }] })

    expect(getDiscourseBug(db, 10226, 12)?.state).toBe('confirmed')
  })

  it('leaves it open when the reporter says it is still broken', async () => {
    report(12, 'Sheila', 'open')

    await persist({}, { classifications: [{ topic: 10226, post: 21, type: 'retest', user: 'Sheila', summary: 'Thanks, but it still crashes my phone' }] })

    expect(getDiscourseBug(db, 10226, 12)?.state).toBe('open')
  })

  it('does not close someone else\'s report', async () => {
    report(3, 'Vee', 'investigating')

    await persist({}, { classifications: [{ topic: 10226, post: 24, type: 'confirmed', user: 'Sheila', originalPostText: 'Thank you, loaded instantly.' }] })

    expect(getDiscourseBug(db, 10226, 3)?.state).toBe('investigating')
  })

  it('closes only the reporter\'s latest earlier report', async () => {
    report(5, 'Sheila', 'open')
    report(12, 'Sheila', 'open')

    await persist({}, { classifications: [{ topic: 10226, post: 24, type: 'confirmed', user: 'Sheila' }] })

    expect(getDiscourseBug(db, 10226, 12)?.state).toBe('confirmed')
    expect(getDiscourseBug(db, 10226, 5)?.state).toBe('open')
  })
})
