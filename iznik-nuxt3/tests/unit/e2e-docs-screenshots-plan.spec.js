import { describe, it, expect } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// The decisions the documentation screenshot generator makes without a browser. It is an
// ES module the generator imports, so it is imported the same way here.
const { viewportFor, skipReason, VIEWPORTS } =
  await import('../e2e/docs-screenshots-plan.mjs')

describe('viewportFor', () => {
  it('gives member shots a phone, because Freegle is mobile-first', () => {
    expect(viewportFor({ app: 'member' })).toEqual({ width: 390, height: 844 })
  })

  // At phone width the ModTools menu takes half the screen and the page beside it is cut
  // off mid-word, which is what the post-moderation screenshots looked like.
  it('gives ModTools shots a desktop, where the menu and the page both fit', () => {
    const v = viewportFor({ app: 'mod' })
    expect(v).toEqual(VIEWPORTS.mod)
    expect(v.width).toBeGreaterThanOrEqual(1024)
  })

  it('lets a shot choose its own, for a modal taller than the screen', () => {
    expect(
      viewportFor({ app: 'mod', viewport: { width: 1100, height: 1500 } })
    ).toEqual({ width: 1100, height: 1500 })
  })
})

describe('skipReason', () => {
  const shot = { name: 'check', app: 'mod', trialOnly: true }

  // Post-moderation exists only for a moderator with a community in the trial. Without one
  // (the seeded test account on CI) the page shows none of it, and regenerating would
  // replace the image with a page that does not show what the docs describe.
  it('skips a trial-only shot for a moderator with no community in the trial', () => {
    const reason = skipReason(shot, { inTrial: false })
    expect(reason).toContain('no community in the post-moderation trial')
    expect(reason).toContain('keeping the committed image')
  })

  it('skips it when it is not known whether there is one', () => {
    expect(skipReason(shot, {})).not.toBeNull()
    expect(skipReason(shot, undefined)).not.toBeNull()
  })

  it('takes it for a moderator who has one', () => {
    expect(skipReason(shot, { inTrial: true })).toBeNull()
  })

  it('never skips a shot that is not trial-only', () => {
    expect(
      skipReason({ name: 'members', app: 'mod' }, { inTrial: false })
    ).toBeNull()
    expect(skipReason({ name: 'homepage', app: 'member' }, {})).toBeNull()
  })
})

// The generator's manifest: which shots are the post-moderation ones, and that every shot
// the plan would skip is one that really needs a trial community.
describe('the manifest', () => {
  const here = path.dirname(fileURLToPath(import.meta.url))
  const source = fs.readFileSync(
    path.join(here, '../e2e/docs-screenshots.mjs'),
    'utf8'
  )

  it('marks the four post-moderation shots trial-only', () => {
    for (const name of ['pending', 'check', 'automod-line', 'automod-why']) {
      const entry = source.match(
        new RegExp(`name: '${name}',[\\s\\S]*?\\n  \\}`)
      )
      expect(entry, name).not.toBeNull()
      expect(entry[0], name).toContain('trialOnly: true')
    }
  })

  it('marks no other shot trial-only', () => {
    expect(source.match(/trialOnly: true/g)).toHaveLength(4)
  })

  it('takes its decisions from the plan, so what is tested is what runs', () => {
    expect(source).toContain("from './docs-screenshots-plan.mjs'")
    expect(source).toContain('viewportFor(shot)')
    expect(source).toContain('skipReason(shot')
  })
})
