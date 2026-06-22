import { describe, it, expect } from 'vitest'
import { buildKeywordRegex } from '~/composables/useKeywordRegex'

describe('buildKeywordRegex', () => {
  it('matches the standard keywords and common variants (case-insensitive)', () => {
    const re = buildKeywordRegex()
    for (const s of [
      'OFFER: Sofa',
      'offer: sofa',
      'OFFERED: Sofa',
      'WANTED: Bike',
      'REQUESTED: Green House',
      'REQUEST: Green House',
      'TAKEN: Sofa',
      'RECEIVED: Bike',
    ]) {
      expect(s.match(re), s).toBeTruthy()
    }
  })

  it('does not match a subject with no recognised keyword', () => {
    const re = buildKeywordRegex()
    expect('random junk with no keyword'.match(re)).toBeFalsy()
    expect('Green House (Norwich)'.match(re)).toBeFalsy()
  })

  it("honours a group's custom keywords", () => {
    const re = buildKeywordRegex({ offer: 'Offered', wanted: 'Looking for' })
    expect('Offered: Sofa'.match(re)).toBeTruthy()
    expect('Looking for: Bike'.match(re)).toBeTruthy()
    // Standard keywords still recognised alongside the custom ones.
    expect('WANTED: Bike'.match(re)).toBeTruthy()
  })

  it('escapes regex special characters in custom keywords', () => {
    const re = buildKeywordRegex({ wanted: 'Want (urgent)' })
    expect('Want (urgent): Bike'.match(re)).toBeTruthy()
    // The parens are literal, not a group — a bare "Want" must not match.
    expect('Want: Bike'.match(re)).toBeFalsy()
  })
})
