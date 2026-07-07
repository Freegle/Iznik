// Scroll an element into view and KEEP it there while late-rendering content
// (async reply chunks, images, link previews) shifts the layout underneath.
//
// A smooth scrollIntoView aims at where the target was when the animation
// started; on a page that is still streaming in content the destination is
// stale before the animation lands, and re-firing it produces the
// scroll-past/overshoot chase seen on long ChitChat threads. So instead:
// jump instantly, then re-pin instantly whenever the target's DOCUMENT
// position changes, until the layout has been quiet for SETTLE_MS.

export const SETTLE_MS = 750
export const MAX_PIN_MS = 8000

// Only one auto-scroll intent can be live at a time - a newer pin (e.g.
// anchoring a just-sent reply) supersedes an older one (a deep-link scroll).
let activeCancel = null

const CANCEL_EVENTS = ['wheel', 'touchstart', 'pointerdown', 'keydown']

// Viewport offset the target should sit at, allowing for the fixed navbar.
// There are separate desktop and mobile navbars, each display:none at the
// other's breakpoint (offsetHeight 0), so take the tallest visible one.
export function fixedHeaderOffset() {
  let height = 0
  for (const nav of document.querySelectorAll('.navbar.fixed-top')) {
    height = Math.max(height, nav.offsetHeight)
  }
  return height + 8
}

/**
 * @param {() => Element|null} getEl re-resolved every frame so v-if/key churn
 *   that replaces the node doesn't strand the pin on a detached element.
 * @param {object} options
 * @param {'start'|'center'} options.block viewport placement of the target
 * @param {number} options.offset viewport top the target sits at (block=start)
 * @param {number} options.settleMs quiet period after which the pin ends
 * @param {number} options.maxMs hard cap on the pin's lifetime
 * @returns {() => void} cancel
 */
export function scrollToAndPin(getEl, options = {}) {
  const {
    block = 'start',
    offset = 0,
    settleMs = SETTLE_MS,
    maxMs = MAX_PIN_MS,
  } = options

  if (activeCancel) activeCancel()

  let cancelled = false
  let frame = null
  let lastDocTop = null
  const startedAt = performance.now()
  // Until the element first appears there is nothing to settle - deep-link
  // targets can take a while to mount through the async component chain.
  let quietSince = null

  function cancel() {
    if (cancelled) return
    cancelled = true
    if (frame !== null) cancelAnimationFrame(frame)
    for (const ev of CANCEL_EVENTS) {
      window.removeEventListener(ev, cancel, { capture: true })
    }
    if (activeCancel === cancel) activeCancel = null
  }

  // The user grabbing the page always wins - never fight their scroll.
  for (const ev of CANCEL_EVENTS) {
    window.addEventListener(ev, cancel, { passive: true, capture: true })
  }
  activeCancel = cancel

  function desiredViewportTop(rect) {
    if (block === 'center') {
      return Math.max((window.innerHeight - rect.height) / 2, offset)
    }
    return offset
  }

  function tick() {
    if (cancelled) return

    const now = performance.now()
    if (now - startedAt > maxMs) {
      cancel()
      return
    }

    const el = getEl()
    if (el) {
      const rect = el.getBoundingClientRect()
      const docTop = rect.top + window.scrollY
      if (lastDocTop === null || Math.abs(docTop - lastDocTop) > 1) {
        // The layout moved the target (or this is the first sighting):
        // re-pin instantly. Our own scrolls don't re-trigger this because
        // they change rect.top and scrollY by opposite amounts.
        lastDocTop = docTop
        quietSince = now
        window.scrollTo({
          top: Math.max(0, docTop - desiredViewportTop(rect)),
          behavior: 'instant',
        })
      } else if (quietSince !== null && now - quietSince > settleMs) {
        cancel()
        return
      }
    }

    frame = requestAnimationFrame(tick)
  }

  tick()
  return cancel
}
