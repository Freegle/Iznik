import { describe, it, expect } from 'vitest'
import { extractTitle } from '~/composables/useTitleExtractor'

describe('extractTitle', () => {
  it('strips "got a" prefix and takes text before comma', () => {
    expect(extractTitle('got a sofa, bit worn but comfy', 'Offer')).toBe('Sofa')
  })
  it('strips "offering" prefix', () => {
    expect(extractTitle('offering a desk, good condition', 'Offer')).toBe('Desk')
  })
  it('strips "free to collect" prefix', () => {
    expect(extractTitle('free to collect - washing machine', 'Offer')).toBe('Washing machine')
  })
  it('strips "giving away" prefix', () => {
    expect(extractTitle('giving away box of books', 'Offer')).toBe('Box of books')
  })
  it('strips "looking for" prefix', () => {
    expect(extractTitle('looking for a bike for my kid', 'Wanted')).toBe('Bike for my kid')
  })
  it('strips "anyone got" prefix', () => {
    expect(extractTitle('anyone got a table they dont need?', 'Wanted')).toBe('Table they dont need')
  })
  it('strips "need a" prefix', () => {
    expect(extractTitle('need a bookshelf, any size', 'Wanted')).toBe('Bookshelf')
  })
  it('strips "wanted:" prefix', () => {
    expect(extractTitle('wanted: garden tools', 'Wanted')).toBe('Garden tools')
  })
  it('truncates at 40 characters', () => {
    const long = 'got a really incredibly amazingly super duper long item name that goes on forever'
    const result = extractTitle(long, 'Offer')
    expect(result.length).toBeLessThanOrEqual(40)
  })
  it('capitalises first letter', () => {
    expect(extractTitle('got a sofa', 'Offer')).toBe('Sofa')
  })
  it('handles discussion type (no stripping)', () => {
    expect(extractTitle('has anyone used the tip lately?', 'Discussion')).toBe('Has anyone used the tip lately')
  })
  it('handles empty string', () => {
    expect(extractTitle('', 'Offer')).toBe('')
  })
})
