import { describe, it, expect, vi, beforeEach } from 'vitest'

import { trackConversion } from '~/composables/useTrackConversion'

// trackConversion must use useGtm() from @gtm-support/vue-gtm - the only
// accessor that actually returns the GTM instance in this app. The old
// globalThis.$gtm / nuxtApp.$gtm / globalProperties.gtm patterns were all
// undefined, so conversion events never fired.
let mockGtm

vi.mock('@gtm-support/vue-gtm', () => ({
  useGtm: () => mockGtm,
}))

describe('trackConversion', () => {
  beforeEach(() => {
    mockGtm = {
      enabled: vi.fn(() => true),
      trackEvent: vi.fn(),
    }
  })

  it('fires a GTM event with the matching conversion label', () => {
    trackConversion('Give an Item')

    expect(mockGtm.trackEvent).toHaveBeenCalledWith({
      event: 'Give an Item',
      label: 'YqHzCIHbv7kZELy618UD',
    })
  })

  it('keeps the live GTM container event names', () => {
    // These names are matched EXACTLY by custom-event triggers in GTM
    // container GTM-KJ5FSZK4 - renaming them silently kills conversions.
    for (const event of [
      'Register with Website',
      'Give an Item',
      'Find an Item',
    ]) {
      trackConversion(event)
      expect(mockGtm.trackEvent).toHaveBeenCalledWith(
        expect.objectContaining({ event })
      )
    }
  })

  it('passes extra params through to the dataLayer', () => {
    trackConversion('Reply Sent', { message_id: 123 })

    expect(mockGtm.trackEvent).toHaveBeenCalledWith({
      event: 'Reply Sent',
      message_id: 123,
    })
  })

  it('does not add a label for events with no conversion tag', () => {
    trackConversion('Reply Sent')

    const args = mockGtm.trackEvent.mock.calls[0][0]
    expect(args.label).toBeUndefined()
  })

  it('does nothing when GTM is not enabled', () => {
    mockGtm.enabled = vi.fn(() => false)

    trackConversion('Give an Item')

    expect(mockGtm.trackEvent).not.toHaveBeenCalled()
  })

  it('does nothing and does not throw when GTM is absent (no GTM_ID build)', () => {
    mockGtm = undefined

    expect(() => trackConversion('Give an Item')).not.toThrow()
  })

  it('never breaks the user flow if trackEvent throws', () => {
    mockGtm.trackEvent = vi.fn(() => {
      throw new Error('gtm exploded')
    })

    expect(() => trackConversion('Give an Item')).not.toThrow()
  })
})
