import {
  describe,
  it,
  expect,
  vi,
  beforeAll,
  beforeEach,
  afterEach,
} from 'vitest'

vi.mock('~/stores/loggingContext', () => ({
  useLoggingContextStore: () => ({
    pushModal: vi.fn(),
    popModal: vi.fn(),
    getContext: () => ({}),
    getHeaders: () => ({}),
  }),
}))

const log = vi.hoisted(() => ({
  action: vi.fn(),
  noteClick: vi.fn(),
  apiCount: 0,
  apiStarted: false,
  sampled: true,
}))

vi.mock('~/composables/useClientLog', () => ({
  action: log.action,
  getApiRequestCount: () => log.apiCount,
  apiRequestStartedBetween: (...args) => log.apiStarted(...args),
  noteClickComponent: log.noteClick,
  sessionSampled: () => log.sampled,
  SAMPLE_RATE: 0.1,
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

  it('flags a div styled as clickable (a Vue @click root), so its clicks are not dead', () => {
    // Vue listeners leave no attribute, so ChatListEntry and MessageSummary, both @click divs
    // with cursor: pointer, used to count every click as dead.
    const wrap = document.createElement('div')
    wrap.innerHTML =
      '<div class="entry" style="cursor: pointer"><div class="body"><span class="in">Sam</span></div></div>'
    document.body.appendChild(wrap)
    const result = extractElementInfo(wrap.querySelector('.in'))
    expect(result.interactive).toBe(true)
    expect(result.classes).toContain('entry')
    wrap.remove()
  })

  it('does not flag text under an ordinary cursor as interactive', () => {
    const wrap = document.createElement('div')
    wrap.innerHTML =
      '<div style="cursor: text"><span class="in">Words</span></div>'
    document.body.appendChild(wrap)
    expect(extractElementInfo(wrap.querySelector('.in')).interactive).toBe(
      false
    )
    wrap.remove()
  })

  it('does not flag a plain element as interactive', () => {
    const div = document.createElement('div')
    expect(extractElementInfo(div).interactive).toBe(false)
  })

  it.each([
    ['label', '<label>Tick</label>'],
    ['role=button', '<div role="button">Go</div>'],
    ['tabindex', '<div tabindex="0">Go</div>'],
    ['.btn div', '<div class="btn">Go</div>'],
    ['bs toggle', '<div data-bs-toggle="modal">Go</div>'],
    ['inside a button', '<button><span class="in">Go</span></button>'],
  ])('flags %s as interactive, so it is not a dead click', (_n, html) => {
    const wrap = document.createElement('div')
    wrap.innerHTML = html
    document.body.appendChild(wrap)
    const target = wrap.querySelector('.in') || wrap.firstElementChild
    expect(extractElementInfo(target).interactive).toBe(true)
    wrap.remove()
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

describe('interactionCapture - click and scroll telemetry', () => {
  let button

  beforeAll(async () => {
    vi.resetModules()
    const mod = await import('~/plugins/interactionCapture.client.js')
    mod.default()
  })

  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-12T10:00:00Z'))
    log.action.mockClear()
    log.apiCount = 0
    log.apiStarted = vi.fn(() => false)
    log.sampled = true
    button = document.createElement('button')
    button.textContent = 'Next'
    document.body.appendChild(button)
  })

  afterEach(() => {
    button.remove()
    vi.useRealTimers()
  })

  function click(el = button) {
    el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  }

  function lastClick() {
    return log.action.mock.calls
      .filter((c) => c[1].interaction_type === 'click')
      .at(-1)[1]
  }

  it('remembers the clicked component for flow entry detection', () => {
    button.dataset.component = 'NavbarMobilePost'
    click()
    expect(log.noteClick).toHaveBeenCalledWith('NavbarMobilePost')
  })

  it('sends the label as its own field', () => {
    click()
    expect(lastClick().label).toBe('Next')
  })

  it('is not rage on the first or second click', () => {
    click()
    expect(lastClick().rage).toBe(false)
    vi.advanceTimersByTime(200)
    click()
    expect(lastClick().rage).toBe(false)
  })

  it('is rage on the third click within one second', () => {
    click()
    vi.advanceTimersByTime(300)
    click()
    vi.advanceTimersByTime(300)
    click()
    expect(lastClick().rage).toBe(true)
  })

  it('flags one rage click per burst, not every click after the third', () => {
    const flags = []
    for (let i = 0; i < 6; i++) {
      click()
      flags.push(lastClick().rage)
      vi.advanceTimersByTime(150)
    }
    expect(flags).toEqual([false, false, true, false, false, false])
  })

  it('flags a fresh burst after the first one has ended', () => {
    for (let i = 0; i < 4; i++) {
      click()
      vi.advanceTimersByTime(150)
    }
    vi.advanceTimersByTime(2000)
    click()
    expect(lastClick().rage).toBe(false)
    vi.advanceTimersByTime(150)
    click()
    vi.advanceTimersByTime(150)
    click()
    expect(lastClick().rage).toBe(true)
  })

  it('is not rage when the clicks are spread over more than a second', () => {
    click()
    vi.advanceTimersByTime(600)
    click()
    vi.advanceTimersByTime(600)
    click()
    expect(lastClick().rage).toBe(false)
  })

  it('does not count clicks on different elements together', () => {
    const other = document.createElement('button')
    other.textContent = 'Back'
    document.body.appendChild(other)
    click()
    click(other)
    click()
    expect(lastClick().rage).toBe(false)
    other.remove()
  })

  it('flags repeat_no_api for a second click 2 to 30 s later with no API request between', () => {
    click()
    expect(lastClick().repeat_no_api).toBe(false)
    vi.advanceTimersByTime(5000)
    click()
    expect(lastClick().repeat_no_api).toBe(true)
  })

  it('does not flag repeat_no_api when an API request started in between', () => {
    click()
    vi.advanceTimersByTime(5000)
    log.apiStarted = vi.fn(() => true)
    click()
    expect(lastClick().repeat_no_api).toBe(false)
    // Asked about the 1.5 s after the earlier click, so background polling later does not count.
    const [from, to] = log.apiStarted.mock.calls[0]
    expect(to - from).toBe(1500)
  })

  it('does not flag repeat_no_api inside 2 s or after 30 s', () => {
    click()
    vi.advanceTimersByTime(1500)
    click()
    expect(lastClick().repeat_no_api).toBe(false)
    vi.advanceTimersByTime(31000)
    click()
    expect(lastClick().repeat_no_api).toBe(false)
  })

  it('does not flag repeat_no_api for an element with no label', () => {
    const bare = document.createElement('button')
    document.body.appendChild(bare)
    click(bare)
    vi.advanceTimersByTime(5000)
    click(bare)
    expect(lastClick().repeat_no_api).toBe(false)
    bare.remove()
  })

  it('never samples clicks, even in a sampled-out session', () => {
    log.sampled = false
    click()
    expect(lastClick().label).toBe('Next')
  })

  describe('[data-ux-ignore]', () => {
    let area
    let inner

    beforeEach(() => {
      area = document.createElement('div')
      area.setAttribute('data-ux-ignore', '')
      inner = document.createElement('button')
      inner.textContent = 'Inside'
      area.appendChild(inner)
      document.body.appendChild(area)
    })

    afterEach(() => area.remove())

    it('logs no click inside it', () => {
      click(inner)
      expect(log.action).not.toHaveBeenCalled()
    })

    it('still logs a click outside it', () => {
      click()
      expect(log.action).toHaveBeenCalled()
    })

    it('logs no scroll whose target is inside it', () => {
      inner.dispatchEvent(new Event('scroll'))
      vi.advanceTimersByTime(600)
      expect(log.action).not.toHaveBeenCalled()
    })
  })

  describe('scroll', () => {
    function scroll() {
      document.dispatchEvent(new Event('scroll'))
      vi.advanceTimersByTime(600)
    }

    function scrolls() {
      return log.action.mock.calls.filter(
        (c) => c[1].interaction_type === 'scroll'
      )
    }

    it('is sent with sample_rate when the session is sampled in', () => {
      scroll()
      expect(scrolls()).toHaveLength(1)
      expect(scrolls()[0][1].sample_rate).toBe(0.1)
    })

    it('is not sent when the session is sampled out', () => {
      log.sampled = false
      scroll()
      expect(scrolls()).toHaveLength(0)
    })
  })
})
