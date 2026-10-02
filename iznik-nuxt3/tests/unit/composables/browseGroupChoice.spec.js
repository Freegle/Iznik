import { describe, it, expect } from 'vitest'
import { resolveBrowseGroup } from '~/composables/browseGroupChoice'

// "Show posts from" used to forget a single community the moment the page reloaded, because
// only the two whole-feed views were stored. settings.browseGroup remembers it; this resolves
// what the feed should actually filter by (Discourse 10096).
describe('resolveBrowseGroup', () => {
  const groups = [{ id: 21455 }, { id: 9 }]

  it('filters by a community the member is still in', () => {
    expect(resolveBrowseGroup(21455, groups)).toBe(21455)
  })

  it('accepts the id as a string, which is how settings come back', () => {
    expect(resolveBrowseGroup('21455', groups)).toBe(21455)
  })

  it('honours the saved choice before the group list has loaded', () => {
    // Settings arrive before myGroups. An empty list means "not known yet" - reading it as
    // "not a member" would drop the choice on every cold load, which is the bug this fixes.
    expect(resolveBrowseGroup(21455, [])).toBe(21455)
    expect(resolveBrowseGroup(21455, undefined)).toBe(21455)
  })

  it('stops filtering by a community the member has left', () => {
    expect(resolveBrowseGroup(999, groups)).toBe(0)
  })

  it('means no group filter when nothing is saved', () => {
    expect(resolveBrowseGroup(null, groups)).toBe(0)
    expect(resolveBrowseGroup(undefined, groups)).toBe(0)
    expect(resolveBrowseGroup(0, groups)).toBe(0)
    expect(resolveBrowseGroup('nearby', groups)).toBe(0)
  })
})
