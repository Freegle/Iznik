/**
 * Interaction capture plugin.
 *
 * Captures user interactions (clicks, scrolls, swipes, modal events) and logs them
 * with full context including Vue component names and human-readable labels.
 */
import { useLoggingContextStore } from '~/stores/loggingContext'
import {
  action,
  apiRequestStartedBetween,
  noteClickComponent,
  sessionSampled,
  SAMPLE_RATE,
} from '~/composables/useClientLog'

// Rage: this many clicks on one element inside this window.
const RAGE_CLICKS = 3
const RAGE_WINDOW_MS = 1000
// Repeat with no API request: the same labelled element clicked again within this band.
// Only a request started this soon after the click can be its result: chat and notification
// polling runs all the time.
const API_RESPONSE_WINDOW_MS = 1500
const REPEAT_MIN_MS = 2000
const REPEAT_MAX_MS = 30000

// Per element: recent click times, and when it was last clicked with the API request count then.
const recentClicks = new WeakMap()
// Elements whose previous click was already part of a rage burst.
const inRageBurst = new WeakSet()
const lastLabelledClick = new WeakMap()

function clickSignals(el, label) {
  const now = Date.now()
  const times = (recentClicks.get(el) || []).filter(
    (t) => now - t <= RAGE_WINDOW_MS
  )
  times.push(now)
  recentClicks.set(el, times)

  let repeatNoApi = false
  if (label) {
    const last = lastLabelledClick.get(el)
    if (last) {
      const age = now - last.time
      repeatNoApi =
        age >= REPEAT_MIN_MS &&
        age <= REPEAT_MAX_MS &&
        !apiRequestStartedBetween(last.time, last.time + API_RESPONSE_WINDOW_MS)
    }
    lastLabelledClick.set(el, { time: now })
  }

  // One burst is one rage click: only the click that brings the burst to the threshold is flagged,
  // however long the hammering goes on.
  const burst = times.length >= RAGE_CLICKS
  const rage = burst && !inRageBurst.has(el)
  if (burst) inRageBurst.add(el)
  else inRageBurst.delete(el)

  return { rage, repeat_no_api: repeatNoApi }
}

export default defineNuxtPlugin(() => {
  if (import.meta.server) return

  const ctx = useLoggingContextStore()

  // Capture phase - sees events before handlers, passive = non-blocking.
  const options = { capture: true, passive: true }

  // Click events.
  document.addEventListener(
    'click',
    (e) => captureInteraction('click', e),
    options
  )
  document.addEventListener(
    'dblclick',
    (e) => captureInteraction('dblclick', e),
    options
  )
  document.addEventListener(
    'contextmenu',
    (e) => captureInteraction('rightclick', e),
    options
  )

  // Touch events.
  let touchStart = null
  document.addEventListener(
    'touchstart',
    (e) => {
      touchStart = {
        x: e.touches[0].clientX,
        y: e.touches[0].clientY,
        time: Date.now(),
      }
    },
    options
  )
  document.addEventListener(
    'touchend',
    (e) => {
      if (!touchStart) return

      const dx = e.changedTouches[0].clientX - touchStart.x
      const dy = e.changedTouches[0].clientY - touchStart.y
      const dt = Date.now() - touchStart.time

      // Detect swipe (>50px in <300ms).
      if (dt < 300 && (Math.abs(dx) > 50 || Math.abs(dy) > 50)) {
        const direction =
          Math.abs(dx) > Math.abs(dy)
            ? dx > 0
              ? 'right'
              : 'left'
            : dy > 0
              ? 'down'
              : 'up'

        captureInteraction('swipe', {
          target: e.target,
          direction,
          distance: Math.round(Math.sqrt(dx * dx + dy * dy)),
        })
      }

      touchStart = null
    },
    options
  )

  // Form interactions.
  document.addEventListener(
    'change',
    (e) => captureInteraction('change', e),
    options
  )
  document.addEventListener(
    'submit',
    (e) => captureInteraction('submit', e),
    options
  )

  // Scroll (throttled).
  let scrollTimeout
  document.addEventListener(
    'scroll',
    (e) => {
      if (isUxIgnored(e.target)) return

      if (!scrollTimeout) {
        scrollTimeout = setTimeout(() => {
          const scrollY = window.scrollY
          const maxScroll = document.body.scrollHeight - window.innerHeight
          captureInteraction('scroll', {
            target: document.scrollingElement,
            scrollY,
            scrollPercent:
              maxScroll > 0 ? Math.round((scrollY / maxScroll) * 100) : 0,
          })
          scrollTimeout = null
        }, 500)
      }
    },
    options
  )

  // Modal observation - track modal stack for context.
  // Note: useClientLog handles modal open/close logging, we just track the stack here.
  document.addEventListener('shown.bs.modal', (e) => {
    const modalEl = e.target
    const modalName = getModalName(modalEl)
    ctx.pushModal(modalName)
  })

  document.addEventListener('hidden.bs.modal', () => {
    ctx.popModal()
  })

  function captureInteraction(type, event) {
    const target = event.target
    if (!target || target === document.body) return
    if (isUxIgnored(target)) return

    // Scroll events use document.scrollingElement as target, which is
    // document.documentElement — extractElementInfo rejects that as
    // "non-meaningful". Log scroll events directly with page context.
    if (type === 'scroll') {
      // Scrolling is high volume, so only a sample of sessions send it.
      if (!sessionSampled()) return

      logInteraction({
        sampleRate: SAMPLE_RATE,
        type,
        scrollY: event.scrollY,
        scrollPercent: event.scrollPercent,
      })
      return
    }

    const info = extractElementInfo(target)
    if (!info) return // Ignore non-meaningful elements.

    if (type === 'click') noteClickComponent(info.component)

    logInteraction({
      type,
      ...info,
      ...(type === 'click' && clickSignals(info.el, info.label)),
      ...(event.direction && { direction: event.direction }),
      ...(event.distance && { distance: event.distance }),
    })
  }

  function logInteraction(entry) {
    // Log to console in development.
    if (import.meta.dev) {
      console.log('[interaction]', entry)
    }

    // Build action name from type and label.
    const actionName = entry.label
      ? `${entry.type}: ${entry.label}`
      : entry.type

    // Use the existing client log infrastructure.
    action(actionName, {
      event_type: 'interaction',
      interaction_type: entry.type,
      component: entry.component,
      tag: entry.tag,
      element_id: entry.id,
      classes: entry.classes,
      href: entry.href,
      input_type: entry.inputType,
      interactive: entry.interactive,
      label: entry.label,
      rage: entry.rage,
      repeat_no_api: entry.repeat_no_api,
      sample_rate: entry.sampleRate,
      direction: entry.direction,
      distance: entry.distance,
      scroll_y: entry.scrollY,
      scroll_percent: entry.scrollPercent,
      ...ctx.getContext(),
    })
  }
})

// Helper functions.

// The UX tab of ModTools marks itself data-ux-ignore, so looking at the figures does not become
// figures.
function isUxIgnored(el) {
  const element = el instanceof Element ? el : el?.parentElement
  return !!element?.closest?.('[data-ux-ignore]')
}

function getModalName(el) {
  // Try to get a meaningful name from the modal element.
  return (
    el.getAttribute('aria-label') ||
    el.getAttribute('data-modal-name') ||
    el.querySelector('.modal-title')?.textContent?.trim() ||
    getVueComponentName(el) ||
    el.id ||
    'Modal'
  )
}

export function extractElementInfo(el) {
  // Text nodes don't have .closest(); walk up to the parent element.
  if (!(el instanceof Element)) {
    el = el.parentElement
    if (!el) return null
  }

  // Guard against old browsers (e.g. Android WebView pre-2016, old iOS Safari)
  // where certain Element subtypes don't implement closest().
  if (typeof el.closest !== 'function') return null

  // Find meaningful interactive element.
  const interactive =
    el.closest(
      'button, a, [role="button"], input, select, textarea, ' +
        '[onclick], [tabindex]:not([tabindex="-1"]), label, ' +
        '.btn, [data-bs-toggle]'
    ) || pointerTarget(el)

  const target = interactive || el

  // Skip non-meaningful elements.
  if (target === document.body || target === document.documentElement) {
    return null
  }

  return {
    el: target,
    component: getVueComponentName(target),
    label: getLabel(target),
    tag: target.tagName.toLowerCase(),
    id: target.id || null,
    classes: getCleanClasses(target),
    href: target.href ? stripQueryParams(target.href) : null,
    inputType: target.type || null,
    // Anything the closest() above matched is a control, whatever its tag.
    interactive: !!interactive,
  }
}

// Up to this many ancestors are checked for a pointer cursor.
const POINTER_DEPTH = 12

function hasPointerCursor(node) {
  try {
    return window.getComputedStyle(node).cursor === 'pointer'
  } catch {
    return false
  }
}

// A Vue @click on a div leaves nothing in the DOM for the selector above to find, so a list row
// or a post card that works perfectly read as a dead click on every click. Such elements are
// styled cursor: pointer, which is what tells the member they can be clicked, so that is the
// signal used here: the outermost element of the run of pointer-cursor ancestors is the control.
function pointerTarget(el) {
  let node = el
  for (let depth = 0; node && depth < POINTER_DEPTH; depth++) {
    if (node === document.body || node === document.documentElement) {
      return null
    }
    if (hasPointerCursor(node)) {
      // The cursor is inherited, so climb to the element that set it.
      let top = node
      while (
        top.parentElement &&
        top.parentElement !== document.body &&
        hasPointerCursor(top.parentElement)
      ) {
        top = top.parentElement
      }
      return top
    }
    node = node.parentElement
  }
  return null
}

function getVueComponentName(el) {
  // Production builds stamp each component's root element with data-component (see
  // build/componentNameTransform.js).
  const stamped = el.closest?.('[data-component]')?.dataset?.component
  if (stamped) return stamped

  // Dev builds: Vue 3 keeps the component on the element, so walk up to find it.
  let current = el
  while (current && current !== document.body) {
    // Vue 3 attaches component to __vueParentComponent.
    const vueComponent = current.__vueParentComponent
    if (vueComponent) {
      const name =
        vueComponent.type?.name ||
        vueComponent.type?.__name ||
        vueComponent.type?.__file?.match(/([^/]+)\.vue$/)?.[1]
      if (name && !name.startsWith('_')) {
        return name
      }
    }
    current = current.parentElement
  }
  return null
}

function getLabel(el) {
  // Priority order for human-readable label.
  return (
    el.getAttribute('aria-label') ||
    el.getAttribute('title') ||
    el.getAttribute('data-label') ||
    getVisibleText(el) ||
    getAssociatedLabelText(el) ||
    el.getAttribute('alt') ||
    el.getAttribute('placeholder') ||
    el.getAttribute('name') ||
    null
  )
}

// Form controls are usually labelled by a separate <label for="id"> or by a wrapping <label>,
// not by their own text.
function getAssociatedLabelText(el) {
  let label = null

  if (el.id) {
    label = [...el.ownerDocument.getElementsByTagName('label')].find(
      (l) => l.htmlFor === el.id
    )
  }

  label = label || el.closest?.('label')

  return label && label !== el ? getVisibleText(label) : null
}

function getVisibleText(el) {
  const text = el.innerText?.trim()
  if (!text) return null

  // Clean up whitespace.
  const clean = text.replace(/\s+/g, ' ')

  // Skip if too long (probably not a label).
  if (clean.length > 100) return null

  // Skip if looks like content not a label.
  if (clean.includes('\n') && clean.length > 50) return null

  return clean
}

function getCleanClasses(el) {
  // Return meaningful classes, skip utility classes.
  const skip = /^(d-|p-|m-|col-|row|container|flex|text-|bg-|border-)/
  return (
    [...el.classList]
      .filter((c) => !skip.test(c))
      .slice(0, 3)
      .join(' ') || null
  )
}

function stripQueryParams(url) {
  try {
    const u = new URL(url)
    return u.pathname
  } catch {
    return url
  }
}
