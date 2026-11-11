import { describe, it, expect } from 'vitest'
import { exploreTitle } from '~/composables/exploreTitle'

describe('exploreTitle', () => {
  it('words the title as a search for free stuff in the place', () => {
    expect(exploreTitle('Birmingham Freegle')).toBe(
      'Free stuff in Birmingham | Freegle'
    )
  })

  it('drops the trailing Freegle whatever its case', () => {
    expect(exploreTitle('Leeds FreeGle')).toBe('Free stuff in Leeds | Freegle')
  })

  it('keeps names that do not end in Freegle', () => {
    expect(exploreTitle('Manchester Greencycle')).toBe(
      'Free stuff in Manchester Greencycle | Freegle'
    )
  })

  it('keeps Freegle in the middle of a name', () => {
    expect(exploreTitle('Freegle Hackney')).toBe(
      'Free stuff in Freegle Hackney | Freegle'
    )
  })

  it('falls back to a generic title with no name', () => {
    expect(exploreTitle(undefined)).toBe('Explore Freegle')
    expect(exploreTitle('Freegle')).toBe('Explore Freegle')
  })
})
