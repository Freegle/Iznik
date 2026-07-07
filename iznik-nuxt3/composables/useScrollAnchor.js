// Scroll an element into view and KEEP it there while late-rendering content
// (async reply chunks, images, link previews) shifts the layout underneath.
//
// Fully event-driven - no timers, no polling, no rAF sampling:
// - corrections happen when layout-change EVENTS fire (ResizeObserver on the
//   observed root, MutationObserver for node churn, both of which cover
//   async chunks mounting and images getting their real height)
// - release happens when the caller's `done` promise resolves - built from
//   component rendered events, the API request counter reaching zero and
//   image load/error events - with one final correction on the way out
// - user input (wheel/touch/pointer/key) cancels instantly; the pin never
//   fights the user. Unmount and pin-supersession also cancel.
//
// A smooth scrollIntoView chase can't work here: it aims at where the target
// was when the animation started, and on a page still streaming in content
// the destination is stale before the animation lands. Instant event-driven
// re-pins mean the target visibly holds still while everything else loads.

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

// True when every image under root has finished (loaded or errored).
export function imagesComplete(root = document.body) {
  for (const img of root.querySelectorAll('img')) {
    if (!img.complete) return false
  }
  return true
}

// Resolves once every image currently under root - plus any added while
// waiting - has fired load or error.
export function whenImagesComplete(root = document.body) {
  return new Promise((resolve) => {
    const pending = new Set()
    let observer = null

    function finish() {
      if (pending.size === 0) {
        if (observer) observer.disconnect()
        resolve()
      }
    }

    function track(img) {
      if (img.complete || pending.has(img)) return
      pending.add(img)
      const done = () => {
        img.removeEventListener('load', done)
        img.removeEventListener('error', done)
        pending.delete(img)
        finish()
      }
      img.addEventListener('load', done)
      img.addEventListener('error', done)
    }

    observer = new MutationObserver((mutations) => {
      for (const m of mutations) {
        for (const node of m.addedNodes) {
          if (node.nodeName === 'IMG') track(node)
          else if (node.querySelectorAll) {
            for (const img of node.querySelectorAll('img')) track(img)
          }
        }
      }
    })
    observer.observe(root, { childList: true, subtree: true })

    for (const img of root.querySelectorAll('img')) track(img)
    finish()
  })
}

// Awaits a set of {ok, wait} conditions until ALL hold at the same moment.
// Each wait() resolves on its condition's next satisfaction; after every
// round the conditions are re-checked together, so one flipping back (a
// fresh API call, a new image) just triggers another round.
export async function whenAllSettled(conditions) {
  for (;;) {
    await Promise.all(conditions.map((c) => (c.ok() ? null : c.wait())))
    if (conditions.every((c) => c.ok())) return
  }
}

/**
 * @param {() => Element|null} getEl re-resolved on every correction so
 *   v-if/key churn that replaces the node doesn't strand the pin.
 * @param {object} options
 * @param {'start'|'center'} options.block viewport placement of the target
 * @param {number} options.offset viewport top the target sits at (block=start);
 *   for block=center it is the minimum top, so an over-tall target still
 *   starts below the fixed header
 * @param {Promise} options.done content-complete signal: the pin holds until
 *   this resolves, then applies one final correction and releases
 * @param {Element} options.root subtree whose layout changes drive
 *   corrections (defaults to document.body)
 * @returns {() => void} cancel
 */
export function scrollToAndPin(getEl, options = {}) {
  const { block = 'start', offset = 0, done = null, root = null } = options

  if (activeCancel) activeCancel()

  const observedRoot = root || document.body
  let cancelled = false
  let lastDocTop = null
  let resizeObserver = null
  let mutationObserver = null

  function cancel() {
    if (cancelled) return
    cancelled = true
    if (resizeObserver) resizeObserver.disconnect()
    if (mutationObserver) mutationObserver.disconnect()
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

  // force pins even when the document position hasn't changed - used for
  // the final correction so the target ends exactly placed.
  function pinNow(force) {
    if (cancelled) return
    const el = getEl()
    if (!el) return
    const rect = el.getBoundingClientRect()
    const docTop = rect.top + window.scrollY
    if (force || lastDocTop === null || Math.abs(docTop - lastDocTop) > 1) {
      // The layout moved the target (or this is the first sighting):
      // re-pin instantly. Our own scrolls don't re-trigger the observers
      // because scrolling changes no element's size or the DOM tree.
      lastDocTop = docTop
      window.scrollTo({
        top: Math.max(0, docTop - desiredViewportTop(rect)),
        behavior: 'instant',
      })
    }
  }

  function finish() {
    if (cancelled) return
    pinNow(true)
    cancel()
  }

  if (done) {
    done.then(finish, finish)
  }

  // Content growing, shrinking or replacing above the target changes the
  // observed root's size and/or its subtree - both observers deliver the
  // event; pinNow() is idempotent so overlap is harmless.
  resizeObserver = new ResizeObserver(() => pinNow(false))
  resizeObserver.observe(observedRoot)
  mutationObserver = new MutationObserver(() => pinNow(false))
  mutationObserver.observe(observedRoot, {
    childList: true,
    subtree: true,
    attributes: true,
  })

  pinNow(false)
  return cancel
}
