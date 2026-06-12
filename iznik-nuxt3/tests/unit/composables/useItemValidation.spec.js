import { describe, it, expect } from 'vitest'
import {
  isNumericOnlyItem,
  INVALID_ITEM_MESSAGE,
} from '~/composables/useItemValidation'

describe('isNumericOnlyItem', () => {
  it('rejects a bare number', () => {
    expect(isNumericOnlyItem('123')).toBe(true)
  })

  it('rejects a number with surrounding whitespace', () => {
    expect(isNumericOnlyItem('  42 ')).toBe(true)
  })

  it('rejects a single zero', () => {
    expect(isNumericOnlyItem('0')).toBe(true)
  })

  it('allows a normal item name', () => {
    expect(isNumericOnlyItem('Red sofa')).toBe(false)
  })

  it('allows an item that merely contains digits', () => {
    expect(isNumericOnlyItem('3 chairs')).toBe(false)
    expect(isNumericOnlyItem('size 12 boots')).toBe(false)
  })

  it('allows decimals and thousands separators (not purely digits)', () => {
    expect(isNumericOnlyItem('12.5')).toBe(false)
    expect(isNumericOnlyItem('1,000')).toBe(false)
  })

  it('treats empty / nullish as not-numeric (handled by the required check)', () => {
    expect(isNumericOnlyItem('')).toBe(false)
    expect(isNumericOnlyItem('   ')).toBe(false)
    expect(isNumericOnlyItem(null)).toBe(false)
    expect(isNumericOnlyItem(undefined)).toBe(false)
  })

  it('exposes a human-readable message', () => {
    expect(typeof INVALID_ITEM_MESSAGE).toBe('string')
    expect(INVALID_ITEM_MESSAGE.length).toBeGreaterThan(0)
  })
})
