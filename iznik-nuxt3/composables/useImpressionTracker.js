// Counts how often each component type was seen on screen, so clicks can be turned into conversion rates.
//
// Components are found through the data-component attribute stamped on their root element at build
// time (build-plugins/componentNameTransform.js). To keep the cost flat however long a list gets, only
// the first element of each component type is observed: one IntersectionObserver, about 15 to 30
// observed elements per page rather than one per card. New elements are found with one
// MutationObserver whose work is batched.
//
// Each time an observed element scrolls into view it counts one impression for its component type.
// The totals are reported as a single summary through `report` every 30 seconds, on leaving a page and
// when the tab is hidden.

export const FLUSH_INTERVAL_MS = 30000
const SCAN_DELAY_MS = 100
const SELECTOR = '[data-component]'

export function createImpressionTracker({
  report,
  root = typeof document === 'undefined' ? null : document,
  flushMs = FLUSH_INTERVAL_MS,
  threshold = 0.5,
} = {}) {
  const tracked = new Map() // component name -> the one element we observe
  const counts = new Map() // component name -> impressions since the last report
  const visible = new Set() // observed elements currently on screen
  let pending = new Set()
  let io = null
  let mo = null
  let scanTimer = null
  let flushTimer = null

  function observe(el) {
    const name = el.dataset?.component

    if (name && !tracked.has(name)) {
      tracked.set(name, el)
      io.observe(el)
    }
  }

  function scan(node) {
    if (node.nodeType !== 1) return

    if (node.hasAttribute('data-component')) observe(node)

    node.querySelectorAll(SELECTOR).forEach(observe)
  }

  // An unmounted element we were observing is replaced by the next instance of its type, if any.
  function dropDisconnected() {
    for (const [name, el] of [...tracked]) {
      if (!el.isConnected) {
        io.unobserve(el)
        visible.delete(el)
        tracked.delete(name)

        const next = root.querySelector(
          `[data-component="${globalThis.CSS?.escape ? CSS.escape(name) : name}"]`
        )

        if (next) observe(next)
      }
    }
  }

  function process() {
    scanTimer = null
    const nodes = pending
    pending = new Set()

    dropDisconnected()
    nodes.forEach((n) => n.isConnected && scan(n))
  }

  function schedule() {
    if (!scanTimer) scanTimer = setTimeout(process, SCAN_DELAY_MS)
  }

  function onIntersect(entries) {
    for (const e of entries) {
      if (e.isIntersecting) {
        if (!visible.has(e.target)) {
          visible.add(e.target)
          const name = e.target.dataset?.component
          counts.set(name, (counts.get(name) || 0) + 1)
        }
      } else {
        visible.delete(e.target)
      }
    }
  }

  function onMutate(records) {
    for (const r of records) {
      r.addedNodes.forEach((n) => n.nodeType === 1 && pending.add(n))

      if (r.removedNodes.length) pending.add(r.target)
    }

    schedule()
  }

  // Report and forget the counts so far.
  function flush() {
    if (!counts.size) return

    const components = Object.fromEntries(counts)
    counts.clear()
    report(components)
  }

  // A new page: report the old one, then stop observing its elements.
  function pageChanged() {
    flush()
    tracked.forEach((el) => io.unobserve(el))
    tracked.clear()
    visible.clear()
    pending.clear()
  }

  // Find components already on the page, e.g. persistent navigation and the page just rendered.
  function rescan() {
    if (root?.body) scan(root.body)
  }

  function start() {
    if (
      !root?.body ||
      typeof IntersectionObserver === 'undefined' ||
      typeof MutationObserver === 'undefined'
    ) {
      return false
    }

    io = new IntersectionObserver(onIntersect, { threshold })
    mo = new MutationObserver(onMutate)
    mo.observe(root.body, { childList: true, subtree: true })
    flushTimer = setInterval(flush, flushMs)
    rescan()

    return true
  }

  function stop() {
    flush()
    mo?.disconnect()
    io?.disconnect()
    clearInterval(flushTimer)
    clearTimeout(scanTimer)
    scanTimer = null
    tracked.clear()
    visible.clear()
    pending.clear()
  }

  return { start, stop, flush, pageChanged, rescan }
}
