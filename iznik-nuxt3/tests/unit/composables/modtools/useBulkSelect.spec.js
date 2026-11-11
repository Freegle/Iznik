import { describe, it, expect } from 'vitest'
import {
  useBulkSelect,
  BULK_SELECT_LIMIT,
} from '~/modtools/composables/useBulkSelect'

describe('useBulkSelect', () => {
  it('starts empty', () => {
    const bulk = useBulkSelect()
    expect(bulk.count.value).toBe(0)
    expect(bulk.atLimit.value).toBe(false)
    expect(bulk.isSelected(1)).toBe(false)
  })

  it('toggles an id on and off', () => {
    const bulk = useBulkSelect()
    bulk.toggle(7)
    expect(bulk.isSelected(7)).toBe(true)
    expect(bulk.count.value).toBe(1)
    bulk.toggle(7)
    expect(bulk.isSelected(7)).toBe(false)
    expect(bulk.count.value).toBe(0)
  })

  it('caps at the limit and ignores further toggles on', () => {
    const bulk = useBulkSelect(3)
    bulk.toggle(1)
    bulk.toggle(2)
    bulk.toggle(3)
    expect(bulk.atLimit.value).toBe(true)
    bulk.toggle(4)
    expect(bulk.isSelected(4)).toBe(false)
    expect(bulk.count.value).toBe(3)
    // Removing one makes room again.
    bulk.toggle(1)
    bulk.toggle(4)
    expect(bulk.isSelected(4)).toBe(true)
  })

  it('defaults to fifty', () => {
    expect(BULK_SELECT_LIMIT).toBe(50)
    const bulk = useBulkSelect()
    bulk.selectAll(Array.from({ length: 80 }, (_, i) => i + 1))
    expect(bulk.count.value).toBe(50)
    expect(bulk.isSelected(50)).toBe(true)
    expect(bulk.isSelected(51)).toBe(false)
  })

  it('selectAll keeps what was already selected and fills to the cap in order', () => {
    const bulk = useBulkSelect(4)
    bulk.toggle(9)
    bulk.selectAll([1, 9, 2, 3, 4])
    expect(bulk.selected.value).toEqual([9, 1, 2, 3])
  })

  it('clear empties the selection', () => {
    const bulk = useBulkSelect()
    bulk.selectAll([1, 2, 3])
    bulk.clear()
    expect(bulk.count.value).toBe(0)
  })

  it('keepOnly drops ids that have left the list', () => {
    const bulk = useBulkSelect()
    bulk.selectAll([1, 2, 3])
    bulk.keepOnly([2, 3, 4])
    expect(bulk.selected.value).toEqual([2, 3])
  })
})
