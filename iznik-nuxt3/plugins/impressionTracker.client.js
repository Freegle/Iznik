/**
 * Impression tracker plugin.
 *
 * Counts how often each component type is seen on a page and logs a summary, which pairs with the
 * interaction capture to give per-component conversion. See composables/useImpressionTracker.js.
 */
import { action, flush as flushLogs } from '~/composables/useClientLog'
import { useLoggingContextStore } from '~/stores/loggingContext'
import { createImpressionTracker } from '~/composables/useImpressionTracker'

export default defineNuxtPlugin((nuxtApp) => {
  if (import.meta.server) return

  const tracker = createImpressionTracker({
    report: (components) => {
      action('impressions', {
        event_type: 'impressions',
        components,
        ...useLoggingContextStore().getContext(),
      })
    },
  })

  nuxtApp.hook('app:mounted', () => {
    if (!tracker.start()) return

    // Leaving a page reports its impressions while the logging context still describes it.
    nuxtApp.hook('page:start', () => tracker.pageChanged())
    nuxtApp.hook('page:finish', () => tracker.rescan())

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        tracker.flush()
        flushLogs()
      }
    })
  })
})
