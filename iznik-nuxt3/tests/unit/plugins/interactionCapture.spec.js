import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('~/stores/loggingContext', () => ({
  useLoggingContextStore: () => ({
    pushModal: vi.fn(),
    popModal: vi.fn(),
    getContext: () => ({}),
    getHeaders: () => ({}),
  }),
}))

vi.mock('~/composables/useClientLog', () => ({
  action: vi.fn(),
}))

let extractElementInfo

describe('interactionCapture - extractElementInfo', () => {
  beforeEach(async () => {
    vi.resetModules()
    const mod = await import('~/plugins/interactionCapture.client.js')
    extractElementInfo = mod.extractElementInfo
  })

  it('returns null when element has no closest method (old browser compat)', () => {
    // Simulate an Element that passes instanceof check but has no closest() method.
    // This occurs in old mobile browsers (pre-Firefox 49, old Android WebView) where
    // certain Element subtypes don't implement closest() on their prototype.
    const el = document.createElement('div')
    Object.defineProperty(el, 'closest', {
      value: undefined,
      writable: true,
      configurable: true,
    })

    // Should return null gracefully, not throw "closest is not a function"
    expect(extractElementInfo(el)).toBeNull()
  })

  it('returns element info for a normal button element', () => {
    const btn = document.createElement('button')
    btn.textContent = 'Click me'

    const result = extractElementInfo(btn)
    expect(result).not.toBeNull()
    expect(result.tag).toBe('button')
    expect(result.label).toBe('Click me')
  })

  it('returns null for document.body', () => {
    expect(extractElementInfo(document.body)).toBeNull()
  })

  it('flags buttons, links and form controls as inherently interactive', () => {
    for (const tag of ['button', 'a', 'select', 'textarea', 'input']) {
      const el = document.createElement(tag)
      expect(extractElementInfo(el).interactive).toBe(true)
    }
  })

  it('does not flag a plain element as interactive', () => {
    const div = document.createElement('div')
    div.setAttribute('tabindex', '0')
    expect(extractElementInfo(div).interactive).toBe(false)
  })

  it('labels a control from a <label for> pointing at its id', () => {
    const wrap = document.createElement('div')
    wrap.innerHTML =
      '<label for="msg">Your message</label><textarea id="msg"></textarea>'
    document.body.appendChild(wrap)

    expect(extractElementInfo(wrap.querySelector('textarea')).label).toBe(
      'Your message'
    )
    wrap.remove()
  })

  it('labels a control from a wrapping <label>', () => {
    const wrap = document.createElement('div')
    wrap.innerHTML = '<label>Tick me <input type="checkbox" /></label>'
    document.body.appendChild(wrap)

    expect(extractElementInfo(wrap.querySelector('input')).label).toBe(
      'Tick me'
    )
    wrap.remove()
  })

  it('still prefers aria-label over an associated label', () => {
    const wrap = document.createElement('div')
    wrap.innerHTML =
      '<label for="q">Search</label><input id="q" aria-label="Find stuff" />'
    document.body.appendChild(wrap)

    expect(extractElementInfo(wrap.querySelector('input')).label).toBe(
      'Find stuff'
    )
    wrap.remove()
  })

  it('reads the component name from the nearest data-component', () => {
    const outer = document.createElement('div')
    outer.dataset.component = 'Outer'
    outer.innerHTML =
      '<section data-component="MessageSummary"><span><button>Go</button></span></section>'
    document.body.appendChild(outer)

    expect(extractElementInfo(outer.querySelector('button')).component).toBe(
      'MessageSummary'
    )
    outer.remove()
  })

  it('walks up to parent element for non-Element nodes', () => {
    const div = document.createElement('div')
    const text = document.createTextNode('hello')
    div.appendChild(text)

    // Text node walks up to div parent — returns info for the div, not null
    const result = extractElementInfo(text)
    expect(result).not.toBeNull()
    expect(result.tag).toBe('div')
  })
})
