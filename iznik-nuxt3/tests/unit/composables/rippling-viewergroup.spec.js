import { describe, it, expect } from 'vitest'
import { pickViewerGroup } from '~/modtools/composables/rippling/viewergroup.js'

const groups = (...names) =>
  new Map(
    names.map((n) => [n.toLowerCase(), { id: names.indexOf(n) + 1, name: n }])
  )

const ALL = groups('Edinburgh Freegle', 'Leeds Freegle', 'Bath Freegle')

describe('rippling/viewergroup pickViewerGroup', () => {
  it('opens on the viewer group when they have exactly one', () => {
    expect(pickViewerGroup(['Leeds Freegle'], ALL)).toMatchObject({
      name: 'Leeds Freegle',
    })
  })

  it('matches regardless of case and surrounding space', () => {
    expect(pickViewerGroup(['  leeds freegle '], ALL)).toMatchObject({
      name: 'Leeds Freegle',
    })
  })

  it('declines to guess when the viewer has several groups', () => {
    expect(pickViewerGroup(['Leeds Freegle', 'Bath Freegle'], ALL)).toBeNull()
  })

  it('counts a repeated group once, so a duplicate still auto-opens', () => {
    expect(
      pickViewerGroup(['Leeds Freegle', 'leeds freegle'], ALL)
    ).toMatchObject({ name: 'Leeds Freegle' })
  })

  it('ignores names the picker would not accept', () => {
    // Seeding a name absent from the explorer's own list would fill the box and
    // leave the map empty - the exact state being fixed.
    expect(pickViewerGroup(['Somewhere Else'], ALL)).toBeNull()
    expect(
      pickViewerGroup(['Somewhere Else', 'Bath Freegle'], ALL)
    ).toMatchObject({ name: 'Bath Freegle' })
  })

  it('returns null for a viewer with no groups', () => {
    expect(pickViewerGroup([], ALL)).toBeNull()
  })

  it('is defensive about junk input', () => {
    expect(pickViewerGroup(null, ALL)).toBeNull()
    expect(pickViewerGroup(undefined, ALL)).toBeNull()
    expect(pickViewerGroup(['Leeds Freegle'], null)).toBeNull()
    expect(pickViewerGroup([null, 42, '', '   '], ALL)).toBeNull()
  })
})
