import { describe, it, expect } from 'vitest'
import {
  hasItemList,
  isAutoSplit,
  isClearance,
  itemChoiceLine,
  withItemChoice,
} from '~/composables/useAutoSplit'

const clearance = { bulkcount: 2, bulkitems: [{}, {}] }
const split = {
  type: 'Offer',
  bulkcount: 2,
  bulkitems: [{}, {}],
  autosplit: true,
}
const single = { bulkcount: 0 }

describe('useAutoSplit', () => {
  it('tells a clearance from an automatically split post', () => {
    expect(isClearance(clearance)).toBe(true)
    expect(isAutoSplit(clearance)).toBe(false)
    expect(isClearance(split)).toBe(false)
    expect(isAutoSplit(split)).toBe(true)
    expect(hasItemList(split)).toBe(true)
  })

  it('never treats a WANTED as split, and never as a clearance either', () => {
    const wanted = { ...split, type: 'Wanted' }
    expect(isAutoSplit(wanted)).toBe(false)
    expect(isClearance(wanted)).toBe(false)
  })

  it('treats a post with no items as neither', () => {
    for (const m of [single, { autosplit: true }, null, undefined]) {
      expect(isClearance(m)).toBe(false)
      expect(isAutoSplit(m)).toBe(false)
      expect(hasItemList(m)).toBe(false)
    }
  })

  it('reads the item list from either field', () => {
    expect(hasItemList({ bulkitems: [{}] })).toBe(true)
    expect(hasItemList({ bulkcount: 1 })).toBe(true)
  })

  it('writes the chosen items as a first line', () => {
    expect(itemChoiceLine([])).toBe('')
    expect(itemChoiceLine(['Sofa', 'Chairs'])).toBe("I'd like: Sofa, Chairs")
    expect(withItemChoice('', ['Sofa'])).toBe("I'd like: Sofa")
    expect(withItemChoice('Can collect Friday', ['Sofa'])).toBe(
      "I'd like: Sofa\nCan collect Friday"
    )
  })

  it('replaces its own first line and leaves the rest of the reply alone', () => {
    const once = withItemChoice('Can collect Friday\nThanks', ['Sofa'])
    expect(withItemChoice(once, ['Sofa', 'Bed'])).toBe(
      "I'd like: Sofa, Bed\nCan collect Friday\nThanks"
    )
    expect(withItemChoice(once, [])).toBe('Can collect Friday\nThanks')
    expect(withItemChoice("I'd like: Sofa", [])).toBe('')
  })
})
