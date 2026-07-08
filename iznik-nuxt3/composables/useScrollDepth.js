// Browse-feed scroll-depth capture.
//
// Tracks the FURTHEST feed position a browse session reached and reports it to
// POST /scrolldepth. This is the browse-feed analogue of the digest
// click-by-position instrumentation: aggregated server-side into a scroll-depth
// curve (what fraction of sessions reach position N) on the sysadmin "Scrolling"
// tab.
//
// record(position, total) is called as items scroll into view (per-item
// visibility), so even small scrolls register - not just the infinite-scroll
// load-more boundary. The send is DEBOUNCED: it fires a short time after
// scrolling settles, and re-sends only when the furthest position grows. Each
// session has a stable id and the server upserts one row per session keeping the
// max, so a debounced timer firing repeatedly - or after re-entry - never
// double-counts. We also flush on tab-hide and on unmount (and clear the timer
// then, so a stale timer can't fire after teardown).
//
// apiBase is the APIv2 base URL (so the composable stays unit-testable without
// the Nuxt runtime). getContext returns 'browse' or 'search' for the current feed.

import { onBeforeUnmount, getCurrentInstance } from 'vue'

function newSessionId() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID()
  }
  return (
    'sd-' + Math.random().toString(36).slice(2) + '-' + Date.now().toString(36)
  )
}

export function useScrollDepth(apiBase, getContext, options = {}) {
  const debounceMs =
    typeof options.debounceMs === 'number' ? options.debounceMs : 1500

  // Stable per-session id: one browse session = one row server-side.
  const session = newSessionId()

  let maxPos = -1
  let itemsAvailable = 0
  let lastSentPos = -1
  let timer = null

  function clearTimer() {
    if (timer) {
      clearTimeout(timer)
      timer = null
    }
  }

  // Called as items scroll into view. Keeps the running max and (re)arms a
  // debounced send so we report a short time after scrolling settles.
  function record(position, total) {
    if (typeof position === 'number' && position > maxPos) {
      maxPos = position
    }
    if (typeof total === 'number' && total > itemsAvailable) {
      itemsAvailable = total
    }
    if (maxPos < 0 || !apiBase) {
      return
    }
    clearTimer()
    timer = setTimeout(send, debounceMs)
  }

  function send() {
    clearTimer()
    // Nothing scrolled, no growth since the last send, or no API base (SSR /
    // missing runtime config) - never emit a redundant or broken request. The
    // server keys on `session` and keeps GREATEST(max_position), so repeat sends
    // and re-entry are idempotent.
    if (maxPos < 0 || maxPos === lastSentPos || !apiBase) {
      return
    }
    lastSentPos = maxPos

    const body = JSON.stringify({
      session,
      maxposition: maxPos,
      itemsavailable: itemsAvailable,
      context: (getContext && getContext()) || 'browse',
    })
    const url = `${apiBase}/scrolldepth`

    try {
      // sendBeacon survives unload; fall back to a keepalive fetch where it's
      // unavailable (older browsers / test env).
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

  // Flush immediately when the tab is hidden (mobile app-switch / tab close,
  // where unmount may not fire).
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

  // Flush and tear down on unmount (SPA route away). Clearing the timer here is
  // what stops a debounced send firing after the component - and its session -
  // are gone.
  if (getCurrentInstance()) {
    onBeforeUnmount(() => {
      if (typeof document !== 'undefined') {
        document.removeEventListener('visibilitychange', onVisibilityChange)
      }
      send()
      clearTimer()
    })
  }

  return { record, send, session }
}
