import { describe, it, expect } from 'vitest'
import {
  REACH_BAND_MINUTES,
  REACH_CEILING_MINUTES,
  bandLabel,
  reachModelSentence,
  reachSliderHelp,
} from '~/modtools/composables/rippling/reachmodel'

// The explorer described a flat 30-minute reach for a month after the engine stopped
// using one (Discourse 9808/675). These lock the numbers to the band policy so the
// page cannot drift away from it again silently.

describe('reach model constants', () => {
  it('mirrors the band caps the engine uses', () => {
    expect(REACH_BAND_MINUTES).toEqual({ dense: 20, medium: 30, sparse: 45 })
  })

  it('takes the ceiling as the widest band, not a hardcoded number', () => {
    // DensityService::ceiling() is a max over the configured bands for the same reason:
    // re-tuning one band must not leave the ripple too small to serve it.
    expect(REACH_CEILING_MINUTES).toBe(
      Math.max(...Object.values(REACH_BAND_MINUTES))
    )
    expect(REACH_CEILING_MINUTES).toBe(45)
  })

  it('is frozen, so nothing can mutate the policy at runtime', () => {
    expect(Object.isFrozen(REACH_BAND_MINUTES)).toBe(true)
  })
})

describe('bandLabel', () => {
  it('names each band in words a moderator would use', () => {
    expect(bandLabel('dense')).toBe('a town or city')
    expect(bandLabel('medium')).toBe('a middling area')
    expect(bandLabel('sparse')).toBe('the countryside')
  })

  it('returns null for unknown, which is a real state rather than an error', () => {
    // density_band is 'unknown' when the spatial server could not measure it. The
    // caller must be able to say nothing rather than invent a band.
    expect(bandLabel('unknown')).toBeNull()
    expect(bandLabel(undefined)).toBeNull()
  })
})

describe('reachModelSentence', () => {
  it('states BOTH limits: how far the post travels and how far this member sees', () => {
    const s = reachModelSentence('dense', 20)

    expect(s).toContain("ripples out to 45 minutes' drive")
    expect(s).toContain('a town or city')
    expect(s).toContain('within 20 minutes')
  })

  it('uses the live cap, not the band default, when they differ', () => {
    // cap_minutes comes from the Go implementation answering for a real point; if it
    // has been re-tuned there, the page must show its number and not this file's.
    expect(reachModelSentence('medium', 35)).toContain('within 35 minutes')
  })

  it('rounds a fractional cap rather than showing decimals', () => {
    expect(reachModelSentence('sparse', 44.6)).toContain('within 45 minutes')
  })

  it('says nothing when the band is unmeasurable', () => {
    expect(reachModelSentence('unknown', 30)).toBeNull()
  })

  it('says nothing when the cap is missing or nonsensical', () => {
    expect(reachModelSentence('dense', 0)).toBeNull()
    expect(reachModelSentence('dense', undefined)).toBeNull()
    expect(reachModelSentence('dense', NaN)).toBeNull()
  })
})

describe('reachSliderHelp', () => {
  it('names the production ceiling', () => {
    const help = reachSliderHelp()

    expect(help).toContain('ripple out to 45 minutes')
  })

  it('claims no hypothetical stretch, because the slider stops at the ceiling', () => {
    // The slider used to run to 60 so a wider reach could be explored, and this
    // caption marked where that became hypothetical. The explorer describes what
    // we run now, so the slider stops at 45 and the caveat would be describing a
    // range the control cannot reach.
    expect(reachSliderHelp()).not.toMatch(/hypothetical/i)
  })

  it('lists the bands in ascending order of travel time', () => {
    const help = reachSliderHelp()

    expect(help.indexOf('a town or city 20')).toBeLessThan(
      help.indexOf('a middling area 30')
    )
    expect(help.indexOf('a middling area 30')).toBeLessThan(
      help.indexOf('the countryside 45')
    )
  })

  it('does not claim a single number is the reach everyone gets', () => {
    // The old caption said "the reach we actually use in production now" of a flat 30,
    // which is what made a 45-minute demo look like a bug to a moderator on a 20-minute cap.
    expect(reachSliderHelp()).not.toContain('the reach we actually use')
  })
})
