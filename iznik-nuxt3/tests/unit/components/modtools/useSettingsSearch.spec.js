import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref, nextTick } from 'vue'
import {
  searchSettings,
  useSettingsSearch,
  revealSetting,
} from '~/modtools/composables/useSettingsSearch'

describe('searchSettings', () => {
  it('returns nothing for an empty query', () => {
    expect(searchSettings('')).toEqual([])
    expect(searchSettings('   ')).toEqual([])
  })

  it('finds a setting by its label', () => {
    const results = searchSettings('tagline')

    expect(results[0].id).toBe('tagline')
    expect(results[0].label).toBe('Tagline')
  })

  it('ranks a label match above a description-only match', () => {
    const results = searchSettings('welcome')
    const welcomeMail = results.findIndex((r) => r.id === 'welcomemail')

    expect(welcomeMail).toBe(0)

    // Other settings mention the welcome mail in their description; they may
    // appear, but never above the setting actually called "Welcome email".
    const descriptionOnly = results.findIndex(
      (r) => !r.label.toLowerCase().includes('welcome')
    )

    if (descriptionOnly !== -1) {
      expect(descriptionOnly).toBeGreaterThan(welcomeMail)
    }
  })

  it('matches text in descriptions, not just labels', () => {
    // "autoreposts" appears in the expiry setting's description but not its
    // label - the case that makes description matching worth having.
    const results = searchSettings('autoreposts')

    expect(results.length).toBeGreaterThan(0)
  })

  it('narrows rather than widens as terms are added', () => {
    const broad = searchSettings('members')
    const narrow = searchSettings('members edit')

    expect(narrow.length).toBeLessThan(broad.length)
    expect(narrow.length).toBeGreaterThan(0)
  })

  it('requires every term to match something', () => {
    expect(searchSettings('tagline zzzznotathing')).toEqual([])
  })

  it('is case insensitive', () => {
    expect(searchSettings('TAGLINE')[0].id).toBe('tagline')
  })

  it('labels each result with the tab it lives on', () => {
    const results = searchSettings('tagline')

    expect(results[0].tabLabel).toBe('Community')
  })

  it('caps the number of results', () => {
    // A term this common would otherwise return most of the index.
    expect(searchSettings('the').length).toBeLessThanOrEqual(12)
  })
})

describe('useSettingsSearch', () => {
  it('exposes reactive results that update when the query ref changes', async () => {
    const query = ref('tagline')
    const { results } = useSettingsSearch(query)

    expect(results.value[0].id).toBe('tagline')

    query.value = 'zzzznotathing'
    await nextTick()

    expect(results.value).toEqual([])
  })

  it('treats a missing/falsy query as empty rather than throwing', () => {
    const { results } = useSettingsSearch(ref())

    expect(results.value).toEqual([])
  })

  it('accepts a plain string as well as a ref (unref passes plain values through)', () => {
    const { results } = useSettingsSearch('tagline')

    expect(results.value[0].id).toBe('tagline')
  })
})

describe('revealSetting', () => {
  const HIGHLIGHT_CLASS = 'setting-found'
  // Mirrors the module's private constants (REVEAL_TIMEOUT_MS, SETTLED_FRAMES,
  // HIGHLIGHT_MS) - not exported, so pinned here from the source.
  const REVEAL_TIMEOUT_MS = 2000
  const HIGHLIGHT_MS = 3000

  beforeEach(() => {
    vi.useFakeTimers()
    // happy-dom doesn't drive a real frame loop; a poll every 16ms under the
    // fake clock is a faithful-enough stand-in and lets advanceTimersByTimeAsync
    // step through the whole wait deterministically.
    vi.stubGlobal('requestAnimationFrame', (cb) => setTimeout(cb, 16))
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  function addSetting(id, { height = 40, top = 0 } = {}) {
    const el = document.createElement('div')
    el.setAttribute('data-setting-id', id)
    el.getBoundingClientRect = () => ({ height, top })
    el.scrollIntoView = vi.fn()
    document.body.appendChild(el)
    return el
  }

  it('returns false when the setting never appears in the DOM', async () => {
    const promise = revealSetting('missing')

    await vi.advanceTimersByTimeAsync(REVEAL_TIMEOUT_MS + 500)
    const result = await promise

    expect(result).toBe(false)
  })

  it('returns false when the setting is in the DOM but never gets a height (hidden tab)', async () => {
    addSetting('hidden', { height: 0 })

    const promise = revealSetting('hidden')

    await vi.advanceTimersByTimeAsync(REVEAL_TIMEOUT_MS + 500)
    const result = await promise

    expect(result).toBe(false)
  })

  it('scrolls to and flashes the element once its position settles', async () => {
    const el = addSetting('foo', { height: 40, top: 100 })

    const promise = revealSetting('foo')

    // A handful of stable rAF ticks - well under the timeout - is enough for
    // the 3-consecutive-equal-top check to be satisfied.
    await vi.advanceTimersByTimeAsync(200)
    const result = await promise

    expect(result).toBe(true)
    expect(el.scrollIntoView).toHaveBeenCalledWith({
      behavior: 'smooth',
      block: 'center',
    })
    expect(el.classList.contains(HIGHLIGHT_CLASS)).toBe(true)

    await vi.advanceTimersByTimeAsync(HIGHLIGHT_MS + 100)
    expect(el.classList.contains(HIGHLIGHT_CLASS)).toBe(false)
  })

  it('removes the highlight class before re-adding it, to restart the flash animation', async () => {
    const el = addSetting('foo', { height: 40, top: 0 })
    el.classList.add(HIGHLIGHT_CLASS)
    const removeSpy = vi.spyOn(el.classList, 'remove')
    const addSpy = vi.spyOn(el.classList, 'add')

    const promise = revealSetting('foo')
    await vi.advanceTimersByTimeAsync(200)
    await promise

    expect(removeSpy).toHaveBeenCalledWith(HIGHLIGHT_CLASS)
    expect(addSpy).toHaveBeenCalledWith(HIGHLIGHT_CLASS)
    expect(removeSpy.mock.invocationCallOrder[0]).toBeLessThan(
      addSpy.mock.invocationCallOrder[0]
    )
  })

  it('still reveals the element once time runs out even if its position never stabilizes', async () => {
    let top = 0
    const el = document.createElement('div')
    el.setAttribute('data-setting-id', 'jittery')
    el.getBoundingClientRect = () => ({ height: 40, top: top++ })
    el.scrollIntoView = vi.fn()
    document.body.appendChild(el)

    const promise = revealSetting('jittery')

    await vi.advanceTimersByTimeAsync(REVEAL_TIMEOUT_MS + 500)
    const result = await promise

    expect(result).toBe(true)
    expect(el.scrollIntoView).toHaveBeenCalled()
  })
})
