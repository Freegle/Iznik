import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import {
  scrollToAndPin,
  fixedHeaderOffset,
  SETTLE_MS,
  MAX_PIN_MS,
} from '~/composables/useScrollAnchor.js'

// The pin loop runs on requestAnimationFrame. Fake it alongside timers so
// advanceTimersByTime drives frames deterministically.
function fakeAllTimers() {
  vi.useFakeTimers({
    toFake: [
      'setTimeout',
      'clearTimeout',
      'setInterval',
      'clearInterval',
      'requestAnimationFrame',
      'cancelAnimationFrame',
      'performance',
    ],
  })
}

// A stand-in for a rendered reply row. documentTop is its offset in the
// document; getBoundingClientRect derives the viewport-relative top from
// the current (mocked) window.scrollY, like a real element.
function makeElement({ documentTop = 1000, height = 120 } = {}) {
  return {
    documentTop,
    getBoundingClientRect() {
      return {
        top: this.documentTop - window.scrollY,
        height,
        width: 300,
        bottom: this.documentTop - window.scrollY + height,
      }
    },
  }
}

describe('scrollToAndPin', () => {
  let scrollCalls
  let cancelPin

  beforeEach(() => {
    fakeAllTimers()
    scrollCalls = []
    Object.defineProperty(window, 'scrollY', {
      value: 0,
      writable: true,
      configurable: true,
    })
    Object.defineProperty(window, 'innerHeight', {
      value: 667,
      writable: true,
      configurable: true,
    })
    vi.spyOn(window, 'scrollTo').mockImplementation((opts) => {
      scrollCalls.push(opts)
      window.scrollY = Math.max(0, opts.top)
    })
    cancelPin = null
  })

  afterEach(() => {
    if (cancelPin) cancelPin()
    vi.restoreAllMocks()
    vi.useRealTimers()
  })

  it('pins the element to the requested viewport offset instantly', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 74 })

    vi.advanceTimersByTime(20)

    expect(scrollCalls.length).toBe(1)
    expect(scrollCalls[0]).toEqual({ top: 1000 - 74, behavior: 'instant' })
  })

  it('waits for the element to appear, then pins (no settle timeout while absent)', () => {
    let el = null
    cancelPin = scrollToAndPin(() => el, { offset: 0 })

    // Element absent for longer than the settle window - the pin must not
    // give up, because async chunks can take a while to mount the target.
    vi.advanceTimersByTime(SETTLE_MS + 500)
    expect(scrollCalls.length).toBe(0)

    el = makeElement({ documentTop: 2000 })
    vi.advanceTimersByTime(50)
    expect(scrollCalls.length).toBe(1)
    expect(scrollCalls[0].top).toBe(2000)
  })

  it('re-pins when late-rendering content moves the element in the document', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0 })
    vi.advanceTimersByTime(20)
    expect(scrollCalls.length).toBe(1)

    // Content above the target grows by 800px (images/async replies).
    el.documentTop = 1800
    vi.advanceTimersByTime(50)

    expect(scrollCalls.length).toBe(2)
    expect(scrollCalls[1].top).toBe(1800)
  })

  it('does NOT re-pin when only the viewport scrolled (element document position unchanged)', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0 })
    vi.advanceTimersByTime(20)
    expect(scrollCalls.length).toBe(1)

    // Simulate a scroll not caused by us: viewport moves, document doesn't.
    window.scrollY = 400
    vi.advanceTimersByTime(200)

    expect(scrollCalls.length).toBe(1)
  })

  it('stops correcting once layout has been quiet for the settle window', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0 })
    vi.advanceTimersByTime(20)

    vi.advanceTimersByTime(SETTLE_MS + 100)

    // A shift after settling is the user's problem no longer - we must not
    // yank the viewport around once we've declared the scroll done.
    el.documentTop = 3000
    vi.advanceTimersByTime(200)

    expect(scrollCalls.length).toBe(1)
  })

  it('keeps pinning through repeated shifts, then settles', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0 })
    vi.advanceTimersByTime(20)

    for (let i = 1; i <= 5; i++) {
      el.documentTop = 1000 + i * 300
      vi.advanceTimersByTime(SETTLE_MS - 100) // each shift inside the window
    }

    expect(scrollCalls.length).toBe(6)
    expect(scrollCalls[5].top).toBe(2500)
  })

  it('cancels immediately on user input', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0 })
    vi.advanceTimersByTime(20)
    expect(scrollCalls.length).toBe(1)

    window.dispatchEvent(new Event('wheel'))

    el.documentTop = 2000
    vi.advanceTimersByTime(200)
    expect(scrollCalls.length).toBe(1)
  })

  it('gives up at the hard cap even if layout never settles', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0 })

    // Perpetually shifting layout - move the target every frame-ish.
    for (let t = 0; t < MAX_PIN_MS + 1000; t += 100) {
      el.documentTop += 50
      vi.advanceTimersByTime(100)
    }
    const callsAtCap = scrollCalls.length

    el.documentTop += 500
    vi.advanceTimersByTime(500)
    expect(scrollCalls.length).toBe(callsAtCap)
  })

  it('centers the element when block is center', () => {
    const el = makeElement({ documentTop: 5000, height: 100 })
    cancelPin = scrollToAndPin(() => el, { block: 'center' })
    vi.advanceTimersByTime(20)

    // Centered: element top sits at (innerHeight - height) / 2.
    const expectedViewportTop = (667 - 100) / 2
    expect(scrollCalls[0].top).toBe(5000 - expectedViewportTop)
  })

  it('starting a new pin cancels the previous one', () => {
    const elA = makeElement({ documentTop: 1000 })
    const elB = makeElement({ documentTop: 4000 })
    cancelPin = scrollToAndPin(() => elA, { offset: 0 })
    vi.advanceTimersByTime(20)

    cancelPin = scrollToAndPin(() => elB, { offset: 0 })
    vi.advanceTimersByTime(20)
    expect(scrollCalls.length).toBe(2)

    // Only B is live: A's shifts are ignored, B's are corrected.
    elA.documentTop = 1500
    vi.advanceTimersByTime(100)
    expect(scrollCalls.length).toBe(2)

    elB.documentTop = 4500
    vi.advanceTimersByTime(100)
    expect(scrollCalls.length).toBe(3)
    expect(scrollCalls[2].top).toBe(4500)
  })

  it('returned cancel stops the pin', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0 })
    vi.advanceTimersByTime(20)

    cancelPin()

    el.documentTop = 2000
    vi.advanceTimersByTime(200)
    expect(scrollCalls.length).toBe(1)
  })

  it('never scrolls to a negative offset', () => {
    const el = makeElement({ documentTop: 20 })
    cancelPin = scrollToAndPin(() => el, { offset: 74 })
    vi.advanceTimersByTime(20)

    expect(scrollCalls[0].top).toBe(0)
  })
})

describe('fixedHeaderOffset', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('returns the fixed navbar height plus breathing room', () => {
    const nav = document.createElement('nav')
    nav.className = 'navbar fixed-top'
    Object.defineProperty(nav, 'offsetHeight', { value: 66 })
    document.body.appendChild(nav)

    expect(fixedHeaderOffset()).toBe(66 + 8)
  })

  it('uses the visible navbar when a hidden one precedes it in the DOM', () => {
    // Desktop and mobile navbars coexist; the hidden one reports
    // offsetHeight 0 and must not win just because it comes first.
    const desktopNav = document.createElement('nav')
    desktopNav.className = 'navbar fixed-top d-none d-xl-flex'
    Object.defineProperty(desktopNav, 'offsetHeight', { value: 0 })
    document.body.appendChild(desktopNav)

    const mobileNav = document.createElement('nav')
    mobileNav.className = 'navbar fixed-top'
    Object.defineProperty(mobileNav, 'offsetHeight', { value: 66 })
    document.body.appendChild(mobileNav)

    expect(fixedHeaderOffset()).toBe(66 + 8)
  })

  it('returns just the breathing room when there is no fixed navbar', () => {
    expect(fixedHeaderOffset()).toBe(8)
  })
})
