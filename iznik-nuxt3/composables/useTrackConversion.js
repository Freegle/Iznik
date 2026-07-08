/**
 * Google Ads conversion tracking via GTM.
 *
 * Fires named GTM events at SUCCESS points only (post completed, signup
 * confirmed, reply sent) - never on page mounts or button clicks - so Ads
 * conversion data reflects real outcomes. The event names must match the
 * custom-event triggers in GTM container GTM-KJ5FSZK4, which map them to
 * AW-951442748 conversion tags.
 *
 * Uses useGtm() from @gtm-support/vue-gtm, which returns the module-level
 * GTM instance and works outside component setup. (Earlier code used
 * globalThis.$gtm / nuxtApp.$gtm / a destructured globalProperties.gtm,
 * none of which exist, so those events never fired.)
 */
import { useGtm } from '@gtm-support/vue-gtm'

// Conversion labels, for reference/debugging in GTM preview; the GTM
// triggers match on event name alone.
const LABELS = {
  'Register with Website': 'EcEMCPvav7kZELy618UD',
  'Give an Item': 'YqHzCIHbv7kZELy618UD',
  'Find an Item': 'QxhuCP7av7kZELy618UD',
  // 'Reply Sent' has no conversion tag in the GTM container yet.
}

export function trackConversion(event, extra = {}) {
  try {
    const gtm = useGtm()

    if (gtm?.enabled()) {
      const params = { event, ...extra }

      if (LABELS[event]) {
        params.label = LABELS[event]
      }

      gtm.trackEvent(params)
    }
  } catch (e) {
    // Tracking must never break a user flow.
    console.log('trackConversion failed', e?.message)
  }
}
