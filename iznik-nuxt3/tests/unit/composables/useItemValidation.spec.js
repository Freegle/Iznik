import { describe, it, expect } from 'vitest'
import {
  isNumericOnlyItem,
  isNumericOnlyBody,
  isVagueItem,
  isUnpostableItem,
  unpostableItemMessage,
  invalidBodyMessage,
  INVALID_ITEM_MESSAGE,
  VAGUE_ITEM_MESSAGE,
  INVALID_BODY_MESSAGE_OFFER,
  INVALID_BODY_MESSAGE_WANTED,
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

  it('rejects numbers with punctuation but no letters (decimals, separators, prices)', () => {
    expect(isNumericOnlyItem('12.5')).toBe(true)
    expect(isNumericOnlyItem('1,000')).toBe(true)
    expect(isNumericOnlyItem('12.50')).toBe(true)
    expect(isNumericOnlyItem('£5')).toBe(true)
    expect(isNumericOnlyItem('£5.00')).toBe(true)
    expect(isNumericOnlyItem('3 - 4')).toBe(true)
  })

  it('rejects strings of only punctuation/symbols', () => {
    expect(isNumericOnlyItem('!!!')).toBe(true)
    expect(isNumericOnlyItem('???')).toBe(true)
    expect(isNumericOnlyItem('...')).toBe(true)
    expect(isNumericOnlyItem('@#$%')).toBe(true)
    expect(isNumericOnlyItem('- -')).toBe(true)
  })

  it('allows a normal item name', () => {
    expect(isNumericOnlyItem('Red sofa')).toBe(false)
  })

  it('allows an item that merely contains digits', () => {
    expect(isNumericOnlyItem('3 chairs')).toBe(false)
    expect(isNumericOnlyItem('size 12 boots')).toBe(false)
  })

  it('allows letters from any language/script (has descriptive text)', () => {
    expect(isNumericOnlyItem('café')).toBe(false)
    expect(isNumericOnlyItem('naïve')).toBe(false)
    expect(isNumericOnlyItem('стол')).toBe(false)
    expect(isNumericOnlyItem('椅子')).toBe(false)
    expect(isNumericOnlyItem('5kg potatoes')).toBe(false)
  })

  it('treats empty / nullish as not-blocked (handled by the required check)', () => {
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

describe('isNumericOnlyBody', () => {
  it('rejects a bare number (with or without whitespace)', () => {
    expect(isNumericOnlyBody('24')).toBe(true)
    expect(isNumericOnlyBody('  7 ')).toBe(true)
  })

  it('rejects numbers/prices/punctuation with no letters', () => {
    expect(isNumericOnlyBody('12.50')).toBe(true)
    expect(isNumericOnlyBody('£5')).toBe(true)
    expect(isNumericOnlyBody('!!!')).toBe(true)
    expect(isNumericOnlyBody('1,000')).toBe(true)
  })

  it('allows real descriptions, including ones containing numbers', () => {
    expect(isNumericOnlyBody('Good condition, collect after 6pm')).toBe(false)
    expect(isNumericOnlyBody('Size 12')).toBe(false)
  })

  it('treats empty / nullish as not-blocked', () => {
    expect(isNumericOnlyBody('')).toBe(false)
    expect(isNumericOnlyBody('   ')).toBe(false)
    expect(isNumericOnlyBody(null)).toBe(false)
    expect(isNumericOnlyBody(undefined)).toBe(false)
  })
})

describe('isVagueItem', () => {
  it('rejects content-free catch-all terms (case/space-insensitive)', () => {
    expect(isVagueItem('any')).toBe(true)
    expect(isVagueItem('Anything')).toBe(true)
    expect(isVagueItem('everything')).toBe(true)
    expect(isVagueItem('  STUFF ')).toBe(true)
    expect(isVagueItem('all')).toBe(true)
    expect(isVagueItem("don't know")).toBe(true)
  })

  it('allows a vague term used within a real phrase', () => {
    expect(isVagueItem('anything blue')).toBe(false)
    expect(isVagueItem('garden tools')).toBe(false)
  })

  it('does not block legitimate broad categories (warning-only territory)', () => {
    expect(isVagueItem('furniture')).toBe(false)
    expect(isVagueItem('tools')).toBe(false)
    expect(isVagueItem('garden')).toBe(false)
  })

  it('treats empty / nullish as not-vague', () => {
    expect(isVagueItem('')).toBe(false)
    expect(isVagueItem(null)).toBe(false)
  })
})

describe('isUnpostableItem', () => {
  it('is true for a value with no descriptive words or a content-free term', () => {
    expect(isUnpostableItem('123')).toBe(true)
    expect(isUnpostableItem('£5')).toBe(true)
    expect(isUnpostableItem('everything')).toBe(true)
  })

  it('is false for a real item', () => {
    expect(isUnpostableItem('Red sofa')).toBe(false)
    expect(isUnpostableItem('furniture')).toBe(false)
  })
})

describe('unpostableItemMessage', () => {
  it('returns the numeric message for a value with no descriptive words', () => {
    expect(unpostableItemMessage('123')).toBe(INVALID_ITEM_MESSAGE)
    expect(unpostableItemMessage('£5')).toBe(INVALID_ITEM_MESSAGE)
  })

  it('returns the vague message for a catch-all term', () => {
    expect(unpostableItemMessage('anything')).toBe(VAGUE_ITEM_MESSAGE)
  })

  it('returns null for a valid item', () => {
    expect(unpostableItemMessage('Red sofa')).toBeNull()
  })
})

describe('invalidBodyMessage', () => {
  it('uses "giving away" wording for offers', () => {
    expect(invalidBodyMessage('Offer')).toBe(INVALID_BODY_MESSAGE_OFFER)
  })

  it('uses "looking for" wording for wanteds', () => {
    expect(invalidBodyMessage('Wanted')).toBe(INVALID_BODY_MESSAGE_WANTED)
  })
})
