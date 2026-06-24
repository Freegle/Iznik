// Browse-feed scroll-depth capture.
//
// Tracks the FURTHEST feed position a browse session reached and reports it once,
// on leave/hide, to POST /scrolldepth. This is the browse-feed analogue of the
// digest click-by-position instrumentation: aggregated server-side into a
// scroll-depth curve (what fraction of sessions reach position N) on the sysadmin
// "Scrolling" tab.
//
// record(position, total) is called as the feed grows (e.g. from the infinite-scroll
// load-more handler, which knows the furthest item index revealed). The send is
// fire-and-forget via navigator.sendBeacon so it survives the page unload; a single
// session reports at most once.
//
// apiBase is the APIv2 base URL (so the composable stays unit-testable without the
// Nuxt runtime). getContext returns 'browse' or 'search' for the current feed.

import { onBeforeUnmount, getCurrentInstance } from 'vue'

export function useScrollDepth(apiBase, getContext) {
  let maxPos = -1
  let itemsAvailable = 0
  let sent = false

  function record(position, total) {
    if (typeof position === 'number' && position > maxPos) {
      maxPos = position
    }
    if (typeof total === 'number' && total > itemsAvailable) {
      itemsAvailable = total
    }
  }

  function send() {
    // Nothing scrolled, already reported this session, or no API base (e.g. SSR
    // or missing runtime config) — never emit a broken request.
    if (sent || maxPos < 0 || !apiBase) {
      return
    }
    sent = true

    const body = JSON.stringify({
      maxposition: maxPos,
      itemsavailable: itemsAvailable,
      context: (getContext && getContext()) || 'browse',
    })
    const url = `${apiBase}/scrolldepth`

    try {
      // sendBeacon is the reliable way to get a request out during unload; fall back
      // to a keepalive fetch where it's unavailable (older browsers / test env).
      if (typeof navigator !== 'undefined' && navigator.sendBeacon) {
        navigator.sendBeacon(
          url,
          new Blob([body], { type: 'application/json' })
        )
      } else if (typeof fetch !== 'undefined') {
        fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body,
          keepalive: true,
        }).catch(() => {})
      }
    } catch (e) {
      // Fire-and-forget: instrumentation must never disrupt browsing.
    }
  }

  // Report when the tab is hidden (covers mobile app-switch / tab close, where
  // unmount may not fire) and on component teardown (SPA route change away).
  function onVisibilityChange() {
    if (
      typeof document !== 'undefined' &&
      document.visibilityState === 'hidden'
    ) {
      send()
    }
  }

  if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', onVisibilityChange)
  }

  // Only register the unmount hook when used inside a component (the SPA
  // route-away case). Guarding on the instance keeps the composable usable — and
  // warning-free — outside a setup context, e.g. in unit tests.
  if (getCurrentInstance()) {
    onBeforeUnmount(() => {
      if (typeof document !== 'undefined') {
        document.removeEventListener('visibilitychange', onVisibilityChange)
      }
      send()
    })
  }

  return { record, send }
}
