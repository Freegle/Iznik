import { describe, it, expect } from 'vitest'
import { pluralise } from '~/modtools/composables/usePluralise'

describe('pluralise', () => {
  it('appends s for plural counts without a number', () => {
    expect(pluralise('item', 2, false)).toBe('items')
  })

  it('does not append s for a count of 1 without a number', () => {
    expect(pluralise('item', 1, false)).toBe('item')
  })

  it('prefixes the count when withnumber is true', () => {
    expect(pluralise('item', 3, true)).toBe('3 items')
  })

  it('prefixes the count for a singular value', () => {
    expect(pluralise('item', 1, true)).toBe('1 item')
  })

  it('picks word[0] for a count of 1 when given an array', () => {
    expect(pluralise(['box', 'boxes'], 1, false)).toBe('box')
  })

  it('picks word[1] for any other count when given an array', () => {
    expect(pluralise(['box', 'boxes'], 5, false)).toBe('boxes')
    expect(pluralise(['box', 'boxes'], 0, false)).toBe('boxes')
  })

  it('formats an array word with a leading count', () => {
    expect(pluralise(['box', 'boxes'], 2, true)).toBe('2 boxes')
  })

  it('inserts a thousands comma once the number prefix is long enough', () => {
    // count=12345 -> "12345 " is 6 chars, so a comma splits the last 4 off.
    expect(pluralise('item', 12345, true)).toBe('12,345 items')
  })

  it('treats a zero count as plural', () => {
    expect(pluralise('item', 0, false)).toBe('items')
  })

  it('handles withnumber=false regardless of count size', () => {
    expect(pluralise('item', 99999, false)).toBe('items')
  })
})
