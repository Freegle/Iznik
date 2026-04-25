import { describe, it, expect } from 'vitest'
import { classifyPost } from '~/composables/usePostClassifier'

describe('classifyPost', () => {
  it('classifies "got a sofa" as Offer', () => {
    expect(classifyPost('got a sofa, bit worn but comfy')).toBe('Offer')
  })
  it('classifies "offering a desk" as Offer', () => {
    expect(classifyPost('offering a desk, good condition')).toBe('Offer')
  })
  it('classifies "free to collect" as Offer', () => {
    expect(classifyPost('free to collect - washing machine')).toBe('Offer')
  })
  it('classifies "giving away books" as Offer', () => {
    expect(classifyPost('giving away box of books')).toBe('Offer')
  })

  it('classifies "looking for a bike" as Wanted', () => {
    expect(classifyPost('looking for a bike for my kid')).toBe('Wanted')
  })
  it('classifies "anyone got a table" as Wanted', () => {
    expect(classifyPost('anyone got a table they dont need?')).toBe('Wanted')
  })
  it('classifies "need a bookshelf" as Wanted', () => {
    expect(classifyPost('need a bookshelf, any size')).toBe('Wanted')
  })
  it('classifies "wanted: garden tools" as Wanted', () => {
    expect(classifyPost('wanted: garden tools')).toBe('Wanted')
  })

  it('classifies general text as Discussion', () => {
    expect(classifyPost('has anyone used the tip lately?')).toBe('Discussion')
  })
  it('classifies empty string as Discussion', () => {
    expect(classifyPost('')).toBe('Discussion')
  })

  it('is case insensitive', () => {
    expect(classifyPost('GOT A sofa')).toBe('Offer')
    expect(classifyPost('LOOKING FOR a bike')).toBe('Wanted')
  })
})
