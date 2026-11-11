import { createLayoutShiftWindows } from '~/composables/useLayoutShiftWindows'
import { action } from '~/composables/useClientLog'

// Layout shifts worse than this (web-vitals "needs improvement" starts at 0.1) are logged.
const CLS_THRESHOLD = 0.1

function describeSource(source) {
  const el = source.node
  if (!el) return '(removed)'
  if (el.id) return '#' + el.id
  if (el.className && el.tagName) {
    return (
      el.tagName.toLowerCase() +
      '.' +
      String(el.className).split(' ').slice(0, 2).join('.')
    )
  }
  return (el.tagName || 'unknown').toLowerCase()
}

// Measures layout shift and logs it to Loki once per page load, when the page is hidden, if the
// worst session window crossed the threshold. This is a measurement to read as a rate by route,
// not a fault, so it does not go to Sentry. The route comes from the client log itself.
export default defineNuxtPlugin(() => {
  if (typeof PerformanceObserver === 'undefined') return

  const windows = createLayoutShiftWindows()
  let reported = false

  const observer = new PerformanceObserver((list) => {
    for (const entry of list.getEntries()) {
      if (!entry.hadRecentInput) {
        windows.add(entry.value, entry.startTime, {
          selectors: (entry.sources || []).map(describeSource),
        })
      }
    }
  })

  observer.observe({ type: 'layout-shift', buffered: true })

  function report() {
    const result = windows.result()
    if (reported || result.cls < CLS_THRESHOLD) return
    reported = true

    action('cls_exceeds_threshold', {
      cls_value: Number(result.cls.toFixed(4)),
      cls_window_start_ms: Math.round(result.windowStart),
      cls_window_shifts: result.windowShifts,
      cls_cumulative: Number(result.cumulative.toFixed(4)),
      shift_count: result.shiftCount,
      viewport: `${window.innerWidth}x${window.innerHeight}`,
      top_shifts: windows
        .topShifts(5)
        .map(
          (s) =>
            `${s.value.toFixed(4)} @ ${Math.round(s.startTime)}ms: ${s.detail.selectors.join(', ')}`
        ),
    })
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      report()
    }
  })

  window.__getCLS = () => {
    const result = windows.result()
    console.table(windows.topShifts(20))
    return { cls: result.cls.toFixed(4), ...result }
  }
})
