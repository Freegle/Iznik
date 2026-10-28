import { describe, it, expect } from 'vitest'
import {
  donationAsksPaused,
  donationAsksPausedUntil,
  formatDonationAsksUntil,
} from '~/composables/useDonationAsks'

const now = new Date('2026-10-23T12:00:00Z').getTime()
const future = '2027-10-23T12:00:00Z'
const past = '2026-10-22T12:00:00Z'

describe('useDonationAsks', () => {
  it('is not paused when the switch is off or absent', () => {
    expect(donationAsksPaused(null, now)).toBe(false)
    expect(donationAsksPaused({}, now)).toBe(false)
    expect(
      donationAsksPaused(
        { nodonationasks: false, nodonationasksuntil: future },
        now
      )
    ).toBe(false)
  })

  it('is paused until the date, then lapses', () => {
    expect(
      donationAsksPaused(
        { nodonationasks: true, nodonationasksuntil: future },
        now
      )
    ).toBe(true)
    expect(
      donationAsksPaused(
        { nodonationasks: true, nodonationasksuntil: past },
        now
      )
    ).toBe(false)
  })

  it('treats an undated or unreadable opt-out as running', () => {
    expect(donationAsksPaused({ nodonationasks: true }, now)).toBe(true)
    expect(
      donationAsksPaused(
        { nodonationasks: true, nodonationasksuntil: 'junk' },
        now
      )
    ).toBe(true)
  })

  it('gives the end date only while it runs', () => {
    expect(
      donationAsksPausedUntil(
        { nodonationasks: true, nodonationasksuntil: future },
        now
      )?.toISOString()
    ).toBe('2027-10-23T12:00:00.000Z')
    expect(
      donationAsksPausedUntil(
        { nodonationasks: true, nodonationasksuntil: past },
        now
      )
    ).toBe(null)
    expect(donationAsksPausedUntil({ nodonationasks: true }, now)).toBe(null)
  })

  it('formats the date for members', () => {
    expect(formatDonationAsksUntil(new Date(future))).toBe('23 October 2027')
    expect(formatDonationAsksUntil(null)).toBe(null)
  })
})
