import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createImpressionTracker } from '~/composables/useImpressionTracker'

let observers
let observed

class FakeIO {
  constructor(cb) {
    this.cb = cb
    observers.push(this)
  }
  observe(el) {
    observed.add(el)
  }
  unobserve(el) {
    observed.delete(el)
  }
  disconnect() {
    observed.clear()
  }
}

let mutationCb

class FakeMO {
  constructor(cb) {
    mutationCb = cb
  }
  observe() {}
  disconnect() {}
}

function add(name, parent = document.body) {
  const el = document.createElement('div')
  el.dataset.component = name
  parent.appendChild(el)
  return el
}

function see(el, isIntersecting = true) {
  observers[0].cb([{ target: el, isIntersecting }])
}

function mutate(el) {
  mutationCb([{ addedNodes: [el], removedNodes: [], target: document.body }])
  vi.advanceTimersByTime(150)
}

describe('createImpressionTracker', () => {
  let report

  beforeEach(() => {
    vi.useFakeTimers()
    observers = []
    observed = new Set()
    vi.stubGlobal('IntersectionObserver', FakeIO)
    vi.stubGlobal('MutationObserver', FakeMO)
    document.body.innerHTML = ''
    report = vi.fn()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('observes only the first instance of each component type', () => {
    const first = add('MessageSummary')
    for (let i = 0; i < 200; i++) add('MessageSummary')
    const other = add('DonateButton')

    createImpressionTracker({ report }).start()

    expect([...observed]).toEqual([first, other])
  })

  it('uses a single IntersectionObserver', () => {
    add('A')
    add('B')
    createImpressionTracker({ report }).start()
    expect(observers).toHaveLength(1)
  })

  it('discovers components added after start', () => {
    const t = createImpressionTracker({ report })
    t.start()
    const el = add('Late')
    mutate(el)
    expect(observed.has(el)).toBe(true)
  })

  it('finds a component nested inside an added subtree', () => {
    const t = createImpressionTracker({ report })
    t.start()
    const wrapper = document.createElement('section')
    const inner = add('Inner', wrapper)
    document.body.appendChild(wrapper)
    mutate(wrapper)
    expect(observed.has(inner)).toBe(true)
  })

  it('counts an impression each time the element comes into view', () => {
    const el = add('DonateButton')
    const t = createImpressionTracker({ report })
    t.start()

    see(el)
    see(el) // Repeat callback while still visible is not a new impression.
    see(el, false)
    see(el)
    t.flush()

    expect(report).toHaveBeenCalledWith({ DonateButton: 2 })
  })

  it('reports nothing when nothing was seen', () => {
    add('A')
    const t = createImpressionTracker({ report })
    t.start()
    t.flush()
    expect(report).not.toHaveBeenCalled()
  })

  it('reports a summary on the flush interval and then starts again from zero', () => {
    const el = add('A')
    createImpressionTracker({ report, flushMs: 30000 }).start()
    see(el)

    vi.advanceTimersByTime(30000)
    expect(report).toHaveBeenCalledTimes(1)
    expect(report).toHaveBeenLastCalledWith({ A: 1 })

    vi.advanceTimersByTime(30000)
    expect(report).toHaveBeenCalledTimes(1)
  })

  it('moves to the next instance when the observed one is removed', () => {
    const first = add('Card')
    const second = add('Card')
    const t = createImpressionTracker({ report })
    t.start()
    expect([...observed]).toEqual([first])

    first.remove()
    mutationCb([
      { addedNodes: [], removedNodes: [first], target: document.body },
    ])
    vi.advanceTimersByTime(150)

    expect([...observed]).toEqual([second])
  })

  it('reports the old page and re-finds persistent components on a page change', () => {
    const nav = add('Navbar')
    const page = add('PageThing')
    const t = createImpressionTracker({ report })
    t.start()
    see(nav)
    see(page)

    t.pageChanged()
    expect(report).toHaveBeenCalledWith({ Navbar: 1, PageThing: 1 })
    expect(observed.size).toBe(0)

    page.remove()
    t.rescan()
    expect([...observed]).toEqual([nav])
  })

  it('does nothing where the observers are unavailable', () => {
    vi.stubGlobal('IntersectionObserver', undefined)
    expect(createImpressionTracker({ report }).start()).toBe(false)
  })
})
