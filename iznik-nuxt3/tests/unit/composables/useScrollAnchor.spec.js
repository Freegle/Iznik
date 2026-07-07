import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import {
  scrollToAndPin,
  fixedHeaderOffset,
  imagesComplete,
  whenImagesComplete,
  whenAllSettled,
} from '~/composables/useScrollAnchor.js'

// The pin is event-driven: corrections fire from ResizeObserver /
// MutationObserver callbacks, release from the done promise. jsdom has no
// ResizeObserver, so install a controllable fake and drive corrections by
// triggering it - no timers anywhere, faked or otherwise.
const resizeObservers = []

class FakeResizeObserver {
  constructor(callback) {
    this.callback = callback
    this.disconnected = false
    resizeObservers.push(this)
  }

  observe() {}

  disconnect() {
    this.disconnected = true
  }
}

function triggerLayoutEvent() {
  for (const ro of resizeObservers) {
    if (!ro.disconnected) ro.callback([])
  }
}

const microtasks = () => Promise.resolve().then(() => Promise.resolve())

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
    resizeObservers.length = 0
    vi.stubGlobal('ResizeObserver', FakeResizeObserver)
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
    vi.unstubAllGlobals()
  })

  it('pins the element to the requested viewport offset instantly on start', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 74 })

    expect(scrollCalls.length).toBe(1)
    expect(scrollCalls[0]).toEqual({ top: 1000 - 74, behavior: 'instant' })
  })

  it('re-pins when a layout event moves the element in the document', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0 })
    expect(scrollCalls.length).toBe(1)

    // Content above the target grows by 800px (images/async replies), and
    // the observers deliver a layout event.
    el.documentTop = 1800
    triggerLayoutEvent()

    expect(scrollCalls.length).toBe(2)
    expect(scrollCalls[1].top).toBe(1800)
  })

  it('does NOT re-pin on a layout event when the element did not move in the document', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0 })
    expect(scrollCalls.length).toBe(1)

    // A scroll not caused by us: viewport moves, document position doesn't.
    window.scrollY = 400
    triggerLayoutEvent()

    expect(scrollCalls.length).toBe(1)
  })

  it('waits for the element to appear, then pins on the next layout event', () => {
    let el = null
    cancelPin = scrollToAndPin(() => el, { offset: 0 })
    expect(scrollCalls.length).toBe(0)

    triggerLayoutEvent()
    expect(scrollCalls.length).toBe(0)

    // The target's async chunk mounts - observers fire.
    el = makeElement({ documentTop: 2000 })
    triggerLayoutEvent()

    expect(scrollCalls.length).toBe(1)
    expect(scrollCalls[0].top).toBe(2000)
  })

  it('releases when done resolves: one final forced correction, then no more', async () => {
    let resolveDone
    const done = new Promise((resolve) => {
      resolveDone = resolve
    })
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0, done })
    expect(scrollCalls.length).toBe(1)

    resolveDone()
    await microtasks()

    // Final correction is forced even though the position was already right.
    expect(scrollCalls.length).toBe(2)
    expect(resizeObservers[0].disconnected).toBe(true)

    // Anything after release is ignored - never re-yank a released page.
    el.documentTop = 3000
    triggerLayoutEvent()
    expect(scrollCalls.length).toBe(2)
  })

  it('holds indefinitely while done is unresolved - loading, not time, decides', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, {
      offset: 0,
      done: new Promise(() => {}),
    })

    for (let i = 1; i <= 20; i++) {
      el.documentTop = 1000 + i * 250
      triggerLayoutEvent()
    }

    expect(scrollCalls.length).toBe(21)
    expect(scrollCalls[20].top).toBe(6000)
  })

  it('cancels immediately on user input', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0 })
    expect(scrollCalls.length).toBe(1)

    window.dispatchEvent(new Event('wheel'))

    el.documentTop = 2000
    triggerLayoutEvent()
    expect(scrollCalls.length).toBe(1)
  })

  it('does not apply the final correction if the user already took over', async () => {
    let resolveDone
    const done = new Promise((resolve) => {
      resolveDone = resolve
    })
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0, done })

    window.dispatchEvent(new Event('touchstart'))
    resolveDone()
    await microtasks()

    expect(scrollCalls.length).toBe(1)
  })

  it('centers the element when block is center', () => {
    const el = makeElement({ documentTop: 5000, height: 100 })
    cancelPin = scrollToAndPin(() => el, { block: 'center' })

    const expectedViewportTop = (667 - 100) / 2
    expect(scrollCalls[0].top).toBe(5000 - expectedViewportTop)
  })

  it('keeps an over-tall centered element below the fixed header', () => {
    // Taller than the viewport: naive centering would put its top above
    // the viewport (negative), hiding the start of the reply.
    const el = makeElement({ documentTop: 5000, height: 900 })
    cancelPin = scrollToAndPin(() => el, { block: 'center', offset: 74 })

    expect(scrollCalls[0].top).toBe(5000 - 74)
  })

  it('starting a new pin cancels the previous one', () => {
    const elA = makeElement({ documentTop: 1000 })
    const elB = makeElement({ documentTop: 4000 })
    cancelPin = scrollToAndPin(() => elA, { offset: 0 })
    cancelPin = scrollToAndPin(() => elB, { offset: 0 })
    expect(scrollCalls.length).toBe(2)

    // Only B is live: A's shifts are ignored, B's are corrected.
    elA.documentTop = 1500
    triggerLayoutEvent()
    expect(scrollCalls.length).toBe(2)

    elB.documentTop = 4500
    triggerLayoutEvent()
    expect(scrollCalls.length).toBe(3)
    expect(scrollCalls[2].top).toBe(4500)
  })

  it('returned cancel stops the pin and disconnects the observers', () => {
    const el = makeElement({ documentTop: 1000 })
    cancelPin = scrollToAndPin(() => el, { offset: 0 })
    expect(scrollCalls.length).toBe(1)

    cancelPin()
    expect(resizeObservers[0].disconnected).toBe(true)

    el.documentTop = 2000
    triggerLayoutEvent()
    expect(scrollCalls.length).toBe(1)
  })

  it('never scrolls to a negative offset', () => {
    const el = makeElement({ documentTop: 20 })
    cancelPin = scrollToAndPin(() => el, { offset: 74 })

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

// jsdom marks an img as complete only once it has no pending src load; we
// model loading state explicitly.
function makeImg({ complete }) {
  const img = document.createElement('img')
  Object.defineProperty(img, 'complete', {
    value: complete,
    writable: true,
    configurable: true,
  })
  return img
}

describe('imagesComplete', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('is true with no images', () => {
    expect(imagesComplete(document.body)).toBe(true)
  })

  it('is false while an image is still loading', () => {
    document.body.appendChild(makeImg({ complete: false }))
    expect(imagesComplete(document.body)).toBe(false)
  })

  it('is true when all images have finished', () => {
    document.body.appendChild(makeImg({ complete: true }))
    document.body.appendChild(makeImg({ complete: true }))
    expect(imagesComplete(document.body)).toBe(true)
  })
})

describe('whenImagesComplete', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('resolves immediately when nothing is loading', async () => {
    document.body.appendChild(makeImg({ complete: true }))
    await expect(whenImagesComplete(document.body)).resolves.toBeUndefined()
  })

  it('waits for a pending image to load', async () => {
    const img = makeImg({ complete: false })
    document.body.appendChild(img)

    let resolved = false
    const p = whenImagesComplete(document.body).then(() => {
      resolved = true
    })
    await microtasks()
    expect(resolved).toBe(false)

    img.dispatchEvent(new Event('load'))
    await p
    expect(resolved).toBe(true)
  })

  it('counts a failed image as finished', async () => {
    const img = makeImg({ complete: false })
    document.body.appendChild(img)

    const p = whenImagesComplete(document.body)
    img.dispatchEvent(new Event('error'))
    await expect(p).resolves.toBeUndefined()
  })

  it('tracks images added while waiting', async () => {
    const first = makeImg({ complete: false })
    document.body.appendChild(first)

    let resolved = false
    const p = whenImagesComplete(document.body).then(() => {
      resolved = true
    })

    // A late chunk mounts another still-loading image.
    const second = makeImg({ complete: false })
    document.body.appendChild(second)
    // MutationObserver delivery is asynchronous.
    await new Promise((resolve) => setTimeout(resolve, 0))

    first.dispatchEvent(new Event('load'))
    await microtasks()
    expect(resolved).toBe(false)

    second.dispatchEvent(new Event('load'))
    await p
    expect(resolved).toBe(true)
  })
})

describe('whenAllSettled', () => {
  it('resolves once all conditions hold together', async () => {
    let aOk = false
    let releaseA
    const a = {
      ok: () => aOk,
      wait: () =>
        new Promise((resolve) => {
          releaseA = () => {
            aOk = true
            resolve()
          }
        }),
    }
    const b = { ok: () => true, wait: () => Promise.resolve() }

    let settled = false
    const p = whenAllSettled([a, b]).then(() => {
      settled = true
    })
    await microtasks()
    expect(settled).toBe(false)

    releaseA()
    await p
    expect(settled).toBe(true)
  })

  it('goes another round when a condition regresses before the others settle', async () => {
    // b regresses while a is still being waited on: the first joint check
    // fails and whenAllSettled must wait for b's NEXT satisfaction rather
    // than resolving on stale results.
    let aOk = false
    let bOk = true
    let releaseA
    let releaseB
    const a = {
      ok: () => aOk,
      wait: () =>
        new Promise((resolve) => {
          releaseA = () => {
            aOk = true
            resolve()
          }
        }),
    }
    const b = {
      ok: () => bOk,
      wait: () =>
        new Promise((resolve) => {
          releaseB = () => {
            bOk = true
            resolve()
          }
        }),
    }

    let settled = false
    const p = whenAllSettled([a, b]).then(() => {
      settled = true
    })

    bOk = false // a new API call / image appeared mid-wait
    releaseA()
    await microtasks()
    expect(settled).toBe(false)

    releaseB()
    await p
    expect(settled).toBe(true)
  })
})
