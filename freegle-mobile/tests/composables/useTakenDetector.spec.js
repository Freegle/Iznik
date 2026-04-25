import { describe, it, expect } from 'vitest'
import { detectTaken } from '~/composables/useTakenDetector'

describe('detectTaken', () => {
  it('detects "collected"', () => {
    expect(detectTaken('All collected, thanks!')).toBe(true)
  })
  it('detects "picked up"', () => {
    expect(detectTaken('Picked up this morning')).toBe(true)
  })
  it('detects "got it"', () => {
    expect(detectTaken('Got it, thank you so much')).toBe(true)
  })
  it('detects "all done"', () => {
    expect(detectTaken('All done!')).toBe(true)
  })
  it('detects "thanks for the"', () => {
    expect(detectTaken('Thanks for the sofa')).toBe(true)
  })
  it('detects "thank you" standalone', () => {
    expect(detectTaken('Thank you!')).toBe(true)
  })
  it('does not trigger on "can I collect"', () => {
    expect(detectTaken('Can I collect tomorrow?')).toBe(false)
  })
  it('does not trigger on "I got your message"', () => {
    expect(detectTaken('I got your message about the table')).toBe(false)
  })
  it('does not trigger on casual thanks in negotiation', () => {
    expect(detectTaken('Thanks for getting back to me')).toBe(false)
  })
  it('does not trigger on empty string', () => {
    expect(detectTaken('')).toBe(false)
  })
  it('is case insensitive', () => {
    expect(detectTaken('COLLECTED thank you')).toBe(true)
  })
})
