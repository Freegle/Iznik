import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { registerGestureHandling } from '~/composables/gestureHandling'

// ---------- helpers ----------

/**
 * Build a minimal Leaflet L mock. Each call returns a fresh mock so tests are
 * independent. The addInitHook captures what handler name and factory are
 * registered so we can assert on them.
 */
function makeL() {
  const L = {
    Handler: {
      extend(proto) {
        function GestureHandling(map) {
          this._map = map
        }
        Object.assign(GestureHandling.prototype, proto)
        return GestureHandling
      },
    },
    Map: {
      mergeOptions: vi.fn(),
      addInitHook: vi.fn(),
    },
    DomEvent: {
      on: vi.fn(),
      off: vi.fn(),
    },
    DomUtil: {
      addClass: vi.fn((el, cls) => {
        el._classes = el._classes ?? new Set()
        el._classes.add(cls)
      }),
      removeClass: vi.fn((el, cls) => {
        el._classes = el._classes ?? new Set()
        el._classes.delete(cls)
      }),
      hasClass: vi.fn((el, cls) => {
        el._classes = el._classes ?? new Set()
        return el._classes.has(cls)
      }),
    },
  }
  return L
}

/**
 * Build a minimal mock map with a container and interaction controls.
 * opts is merged into map.options (in addition to the defaults already set
 * by mergeOptions — we replicate the default gestureHandlingOptions here).
 */
function makeMap(extraOptions = {}) {
  const container = {
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    setAttribute: vi.fn(),
    getAttribute: vi.fn(),
    _classes: new Set(),
  }

  return {
    _container: container,
    options: {
      gestureHandlingOptions: {
        text: {},
        duration: 1000,
      },
      ...extraOptions,
    },
    dragging: { enable: vi.fn(), disable: vi.fn() },
    scrollWheelZoom: { enable: vi.fn(), disable: vi.fn() },
    tap: { enable: vi.fn(), disable: vi.fn() },
  }
}

/** Create a simple touch event stub. */
function makeTouchEvent(type, classes = [], touchCount = 1) {
  const target = { _classes: new Set(classes) }
  return {
    type,
    target,
    touches: { length: touchCount },
    preventDefault: vi.fn(),
  }
}

/** Restore navigator properties after tests override them. */
const originalNavigator = {
  languages: navigator.languages,
  language: navigator.language,
  platform: navigator.platform,
}

function stubNavigator(overrides) {
  for (const [key, value] of Object.entries(overrides)) {
    Object.defineProperty(navigator, key, {
      get: () => value,
      configurable: true,
    })
  }
}

function restoreNavigator() {
  for (const [key, value] of Object.entries(originalNavigator)) {
    Object.defineProperty(navigator, key, {
      get: () => value,
      configurable: true,
    })
  }
}

// ---------- tests ----------

describe('registerGestureHandling', () => {
  let L

  beforeEach(() => {
    L = makeL()
  })

  afterEach(() => {
    restoreNavigator()
    vi.useRealTimers()
  })

  // ---------- Registration ----------

  describe('map registration', () => {
    it('calls L.Map.mergeOptions with default gestureHandlingOptions', () => {
      registerGestureHandling(L)
      expect(L.Map.mergeOptions).toHaveBeenCalledWith({
        gestureHandlingOptions: { text: {}, duration: 1000 },
      })
    })

    it('calls L.Map.addInitHook to register the handler', () => {
      registerGestureHandling(L)
      expect(L.Map.addInitHook).toHaveBeenCalledWith(
        'addHandler',
        'gestureHandling',
        expect.any(Function)
      )
    })

    it('returns the GestureHandling class', () => {
      const GH = registerGestureHandling(L)
      expect(typeof GH).toBe('function')
    })

    it('instantiates with a map reference', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      expect(handler._map).toBe(map)
    })
  })

  // ---------- addHooks / removeHooks ----------

  describe('addHooks', () => {
    let GH, map, handler

    beforeEach(() => {
      stubNavigator({ languages: ['en'], platform: 'Win32' })
      GH = registerGestureHandling(L)
      map = makeMap()
      handler = new GH(map)
      handler.addHooks()
    })

    it('attaches touchstart/touchmove/touchend/touchcancel/click to container', () => {
      const calls = map._container.addEventListener.mock.calls.map((c) => c[0])
      expect(calls).toContain('touchstart')
      expect(calls).toContain('touchmove')
      expect(calls).toContain('touchend')
      expect(calls).toContain('touchcancel')
      expect(calls).toContain('click')
    })

    it('wires DomEvent.on for wheel, mouseover, mouseout, movestart, move, moveend', () => {
      const eventNames = L.DomEvent.on.mock.calls.map((c) => c[1])
      expect(eventNames).toContain('wheel')
      expect(eventNames).toContain('mouseover')
      expect(eventNames).toContain('mouseout')
      expect(eventNames).toContain('movestart')
      expect(eventNames).toContain('move')
      expect(eventNames).toContain('moveend')
    })

    it('disables dragging and scroll wheel zoom', () => {
      expect(map.dragging.disable).toHaveBeenCalled()
      expect(map.scrollWheelZoom.disable).toHaveBeenCalled()
    })

    it('disables tap when tap is present', () => {
      expect(map.tap.disable).toHaveBeenCalled()
    })

    it('skips tap when map.tap is undefined', () => {
      const GH2 = registerGestureHandling(makeL())
      const mapNoTap = makeMap()
      delete mapNoTap.tap
      const h = new GH2(mapNoTap)
      expect(() => h.addHooks()).not.toThrow()
    })
  })

  describe('removeHooks', () => {
    let GH, map, handler

    beforeEach(() => {
      stubNavigator({ languages: ['en'], platform: 'Win32' })
      GH = registerGestureHandling(L)
      map = makeMap()
      handler = new GH(map)
      handler.addHooks()
      handler.removeHooks()
    })

    it('removes all touch/click listeners from container', () => {
      const calls = map._container.removeEventListener.mock.calls.map((c) => c[0])
      expect(calls).toContain('touchstart')
      expect(calls).toContain('touchmove')
      expect(calls).toContain('touchend')
      expect(calls).toContain('touchcancel')
      expect(calls).toContain('click')
    })

    it('wires DomEvent.off for wheel, mouseover, mouseout, move events', () => {
      const eventNames = L.DomEvent.off.mock.calls.map((c) => c[1])
      expect(eventNames).toContain('wheel')
      expect(eventNames).toContain('mouseover')
      expect(eventNames).toContain('mouseout')
      expect(eventNames).toContain('movestart')
      expect(eventNames).toContain('move')
      expect(eventNames).toContain('moveend')
    })

    it('re-enables dragging and scroll wheel zoom', () => {
      expect(map.dragging.enable).toHaveBeenCalled()
      expect(map.scrollWheelZoom.enable).toHaveBeenCalled()
    })

    it('re-enables tap when present', () => {
      expect(map.tap.enable).toHaveBeenCalled()
    })

    it('skips tap.enable when tap is undefined', () => {
      const GH2 = registerGestureHandling(makeL())
      const mapNoTap = makeMap()
      delete mapNoTap.tap
      const h = new GH2(mapNoTap)
      // addHooks + removeHooks must not throw even without tap
      expect(() => {
        h.addHooks()
        h.removeHooks()
      }).not.toThrow()
    })
  })

  // ---------- _setupPluginOptions ----------

  describe('_setupPluginOptions', () => {
    let GH, map, handler

    function makeHandlerWithOptions(extraOpts) {
      const G = registerGestureHandling(L)
      const m = makeMap(extraOpts)
      const h = new G(m)
      h._setupPluginOptions()
      return { handler: h, map: m }
    }

    it('copies gestureHandlingText into gestureHandlingOptions.text', () => {
      const text = { touch: 'T', scroll: 'S', scrollMac: 'M' }
      const { map } = makeHandlerWithOptions({ gestureHandlingText: text })
      expect(map.options.gestureHandlingOptions.text).toBe(text)
    })

    it('leaves text unchanged when gestureHandlingText is not set', () => {
      const { map } = makeHandlerWithOptions({})
      expect(map.options.gestureHandlingOptions.text).toEqual({})
    })
  })

  // ---------- _setLanguageContent ----------

  describe('_setLanguageContent', () => {
    const table = [
      {
        label: 'known language — en',
        languages: ['en'],
        platform: 'Win32',
        expectTouch: 'Use two fingers to move the map',
        expectScrollKey: 'ctrl',
      },
      {
        label: 'known region — en-GB',
        languages: ['en-GB'],
        platform: 'Win32',
        expectTouch: 'Use two fingers to move the map',
        expectScrollKey: 'ctrl',
      },
      {
        label: 'known language — fr',
        languages: ['fr'],
        platform: 'Win32',
        expectTouch: 'Utilisez deux doigts',
        expectScrollKey: 'CTRL',
      },
      {
        label: 'language with unknown region falls back to base — cy-GB',
        languages: ['cy-GB'],
        platform: 'Win32',
        expectTouch: 'Defnyddiwch ddau fys',
        expectScrollKey: 'ctrl',
      },
      {
        label: 'completely unknown language falls back to en',
        languages: ['xx'],
        platform: 'Win32',
        expectTouch: 'Use two fingers to move the map',
        expectScrollKey: 'ctrl',
      },
      {
        label: 'Mac platform uses scrollMac text for en',
        languages: ['en'],
        platform: 'MacIntel',
        expectTouch: 'Use two fingers to move the map',
        expectScrollKey: '⌘',
      },
      {
        label: 'Mac platform uses scrollMac text for fr',
        languages: ['fr'],
        platform: 'MacIntel',
        expectTouch: 'Utilisez deux doigts',
        expectScrollKey: '⌘',
      },
    ]

    it.each(table)('$label', ({ languages, platform, expectTouch, expectScrollKey }) => {
      stubNavigator({ languages, language: languages[0], platform })

      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      handler._setupPluginOptions()
      handler._setLanguageContent()

      const touchCalls = map._container.setAttribute.mock.calls.filter(
        (c) => c[0] === 'data-gesture-handling-touch-content'
      )
      const scrollCalls = map._container.setAttribute.mock.calls.filter(
        (c) => c[0] === 'data-gesture-handling-scroll-content'
      )

      expect(touchCalls.length).toBe(1)
      expect(scrollCalls.length).toBe(1)
      expect(touchCalls[0][1]).toContain(expectTouch)
      expect(scrollCalls[0][1]).toContain(expectScrollKey)

      map._container.setAttribute.mockClear()
    })

    it('uses custom text when all three keys are provided', () => {
      stubNavigator({ languages: ['en'], platform: 'Win32' })

      const GH = registerGestureHandling(L)
      const customText = { touch: 'CUSTOM TOUCH', scroll: 'CUSTOM SCROLL', scrollMac: 'CUSTOM MAC' }
      const map = makeMap({ gestureHandlingText: customText })
      const handler = new GH(map)
      handler._setupPluginOptions()
      handler._setLanguageContent()

      const touchCalls = map._container.setAttribute.mock.calls.filter(
        (c) => c[0] === 'data-gesture-handling-touch-content'
      )
      const scrollCalls = map._container.setAttribute.mock.calls.filter(
        (c) => c[0] === 'data-gesture-handling-scroll-content'
      )

      expect(touchCalls[0][1]).toBe('CUSTOM TOUCH')
      // On Win32, scroll (not scrollMac) is used for non-Mac
      expect(scrollCalls[0][1]).toBe('CUSTOM SCROLL')
    })

    it('falls back to en when navigator.languages is empty and navigator.language is not set', () => {
      stubNavigator({ languages: [], language: undefined, userLanguage: undefined, platform: 'Win32' })

      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      handler._setLanguageContent()

      const touchCalls = map._container.setAttribute.mock.calls.filter(
        (c) => c[0] === 'data-gesture-handling-touch-content'
      )
      expect(touchCalls[0][1]).toBe('Use two fingers to move the map')
    })

    it('uses navigator.language when navigator.languages is null/undefined', () => {
      // Covers the ternary false-branch on line 150
      stubNavigator({ languages: null, language: 'de', userLanguage: undefined, platform: 'Win32' })

      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      handler._setLanguageContent()

      const touchCalls = map._container.setAttribute.mock.calls.filter(
        (c) => c[0] === 'data-gesture-handling-touch-content'
      )
      expect(touchCalls[0][1]).toContain('Verschieben')
    })

    it('uses navigator.userLanguage when both navigator.languages and navigator.language are absent', () => {
      stubNavigator({ languages: null, language: undefined, userLanguage: 'cy', platform: 'Win32' })

      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      handler._setLanguageContent()

      const touchCalls = map._container.setAttribute.mock.calls.filter(
        (c) => c[0] === 'data-gesture-handling-touch-content'
      )
      expect(touchCalls[0][1]).toContain('Defnyddiwch')
    })
  })

  // ---------- _disableInteractions / _enableInteractions ----------

  describe('_disableInteractions', () => {
    it('disables dragging and scrollWheelZoom', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      handler._disableInteractions()
      expect(map.dragging.disable).toHaveBeenCalled()
      expect(map.scrollWheelZoom.disable).toHaveBeenCalled()
    })

    it('disables tap when present', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      handler._disableInteractions()
      expect(map.tap.disable).toHaveBeenCalled()
    })

    it('does not throw when tap is absent', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      delete map.tap
      const handler = new GH(map)
      expect(() => handler._disableInteractions()).not.toThrow()
    })
  })

  describe('_enableInteractions', () => {
    it('enables dragging and scrollWheelZoom', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      handler._enableInteractions()
      expect(map.dragging.enable).toHaveBeenCalled()
      expect(map.scrollWheelZoom.enable).toHaveBeenCalled()
    })

    it('enables tap when present', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      handler._enableInteractions()
      expect(map.tap.enable).toHaveBeenCalled()
    })

    it('does not throw when tap is absent', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      delete map.tap
      const handler = new GH(map)
      expect(() => handler._enableInteractions()).not.toThrow()
    })
  })

  // ---------- _handleDragging ----------

  describe('_handleDragging', () => {
    it('sets dragging state on movestart and move', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)

      // After movestart, mouseout should NOT disable (dragging=true)
      handler._handleDragging({ type: 'movestart' })
      handler._handleMouseOut()
      expect(map.dragging.disable).not.toHaveBeenCalled()
    })

    it('sets dragging state on move', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)

      handler._handleDragging({ type: 'move' })
      handler._handleMouseOut()
      expect(map.dragging.disable).not.toHaveBeenCalled()
    })

    it('clears dragging state on moveend — mouseout then disables', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)

      handler._handleDragging({ type: 'movestart' })
      handler._handleDragging({ type: 'moveend' })
      handler._handleMouseOut()
      expect(map.dragging.disable).toHaveBeenCalled()
    })
  })

  // ---------- _handleTouch ----------

  describe('_handleTouch', () => {
    let GH, map, handler

    beforeEach(() => {
      GH = registerGestureHandling(L)
      map = makeMap()
      handler = new GH(map)
    })

    describe('non-ignored elements', () => {
      it('single touchmove → adds warning + disables interactions', () => {
        const event = makeTouchEvent('touchmove', [], 1)
        handler._handleTouch(event)
        expect(L.DomUtil.addClass).toHaveBeenCalledWith(
          map._container,
          'leaflet-gesture-handling-touch-warning'
        )
        expect(map.dragging.disable).toHaveBeenCalled()
      })

      it('single touchstart → adds warning + disables interactions', () => {
        const event = makeTouchEvent('touchstart', [], 1)
        handler._handleTouch(event)
        expect(L.DomUtil.addClass).toHaveBeenCalledWith(
          map._container,
          'leaflet-gesture-handling-touch-warning'
        )
        expect(map.dragging.disable).toHaveBeenCalled()
      })

      it('multi-touch → calls preventDefault + enables + removes warning', () => {
        const event = makeTouchEvent('touchmove', [], 2)
        handler._handleTouch(event)
        expect(event.preventDefault).toHaveBeenCalled()
        expect(map.dragging.enable).toHaveBeenCalled()
        expect(L.DomUtil.removeClass).toHaveBeenCalledWith(
          map._container,
          'leaflet-gesture-handling-touch-warning'
        )
      })

      it('non-touch event (click) → removes warning and returns', () => {
        const event = makeTouchEvent('click', [], 0)
        handler._handleTouch(event)
        expect(L.DomUtil.removeClass).toHaveBeenCalledWith(
          map._container,
          'leaflet-gesture-handling-touch-warning'
        )
        expect(map.dragging.disable).not.toHaveBeenCalled()
      })

      it('touchend → removes warning and returns (no disable)', () => {
        const event = makeTouchEvent('touchend', [], 0)
        handler._handleTouch(event)
        expect(L.DomUtil.removeClass).toHaveBeenCalledWith(
          map._container,
          'leaflet-gesture-handling-touch-warning'
        )
        expect(map.dragging.disable).not.toHaveBeenCalled()
      })
    })

    describe('ignored elements', () => {
      it('leaflet-popup-content (ignore but not interactive) → removes warning, returns early', () => {
        const event = makeTouchEvent('touchmove', ['leaflet-popup-content'], 1)
        handler._handleTouch(event)
        expect(L.DomUtil.removeClass).toHaveBeenCalledWith(
          map._container,
          'leaflet-gesture-handling-touch-warning'
        )
        // Should not interact further — dragging disable not called
        expect(map.dragging.disable).not.toHaveBeenCalled()
      })

      it('leaflet-interactive + touchmove + 1 touch → adds warning + disables', () => {
        const event = makeTouchEvent('touchmove', ['leaflet-interactive'], 1)
        handler._handleTouch(event)
        expect(L.DomUtil.addClass).toHaveBeenCalledWith(
          map._container,
          'leaflet-gesture-handling-touch-warning'
        )
        expect(map.dragging.disable).toHaveBeenCalled()
      })

      it('leaflet-interactive + touchstart (not touchmove) → removes warning, returns early', () => {
        const event = makeTouchEvent('touchstart', ['leaflet-interactive'], 1)
        handler._handleTouch(event)
        expect(L.DomUtil.removeClass).toHaveBeenCalledWith(
          map._container,
          'leaflet-gesture-handling-touch-warning'
        )
        // Not the leaflet-interactive + touchmove + 1 branch, so no addClass
        expect(map.dragging.disable).not.toHaveBeenCalled()
      })

      it('leaflet-interactive + touchmove + 2 touches → removes warning (not the single-touch branch)', () => {
        const event = makeTouchEvent('touchmove', ['leaflet-interactive'], 2)
        handler._handleTouch(event)
        expect(L.DomUtil.removeClass).toHaveBeenCalledWith(
          map._container,
          'leaflet-gesture-handling-touch-warning'
        )
        expect(map.dragging.disable).not.toHaveBeenCalled()
      })

      it('leaflet-control-zoom-in + click → removes warning, returns early', () => {
        const event = makeTouchEvent('click', ['leaflet-control-zoom-in'], 0)
        handler._handleTouch(event)
        expect(L.DomUtil.removeClass).toHaveBeenCalledWith(
          map._container,
          'leaflet-gesture-handling-touch-warning'
        )
      })
    })
  })

  // ---------- _handleScroll ----------

  describe('_handleScroll', () => {
    let GH, map, handler

    beforeEach(() => {
      vi.useFakeTimers()
      GH = registerGestureHandling(L)
      map = makeMap()
      handler = new GH(map)
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    it('ctrl key → prevents default, removes warning, enables scroll zoom', () => {
      const event = { ctrlKey: true, metaKey: false, preventDefault: vi.fn() }
      handler._handleScroll(event)
      expect(event.preventDefault).toHaveBeenCalled()
      expect(L.DomUtil.removeClass).toHaveBeenCalledWith(
        map._container,
        'leaflet-gesture-handling-scroll-warning'
      )
      expect(map.scrollWheelZoom.enable).toHaveBeenCalled()
    })

    it('meta key (Mac cmd) → prevents default, removes warning, enables scroll zoom', () => {
      const event = { ctrlKey: false, metaKey: true, preventDefault: vi.fn() }
      handler._handleScroll(event)
      expect(event.preventDefault).toHaveBeenCalled()
      expect(map.scrollWheelZoom.enable).toHaveBeenCalled()
    })

    it('no modifier → adds scroll warning, disables scroll zoom', () => {
      const event = { ctrlKey: false, metaKey: false, preventDefault: vi.fn() }
      handler._handleScroll(event)
      expect(L.DomUtil.addClass).toHaveBeenCalledWith(
        map._container,
        'leaflet-gesture-handling-scroll-warning'
      )
      expect(map.scrollWheelZoom.disable).toHaveBeenCalled()
    })

    it('no modifier → sets a timeout that removes all scroll warnings', () => {
      const fakeWarning = { _classes: new Set(['leaflet-gesture-handling-scroll-warning']) }
      const mockGetByClassName = vi.spyOn(document, 'getElementsByClassName').mockReturnValue([fakeWarning])

      const event = { ctrlKey: false, metaKey: false, preventDefault: vi.fn() }
      handler._handleScroll(event)

      // Advance timers to trigger the timeout callback
      vi.advanceTimersByTime(1000)

      expect(mockGetByClassName).toHaveBeenCalledWith('leaflet-gesture-handling-scroll-warning')
      expect(L.DomUtil.removeClass).toHaveBeenCalledWith(
        fakeWarning,
        'leaflet-gesture-handling-scroll-warning'
      )

      mockGetByClassName.mockRestore()
    })

    it('repeated scroll clears previous timeout before setting a new one', () => {
      const event = { ctrlKey: false, metaKey: false, preventDefault: vi.fn() }
      // First scroll
      handler._handleScroll(event)
      const firstScrollingId = handler._isScrolling

      // Second scroll before timeout fires
      handler._handleScroll(event)
      const secondScrollingId = handler._isScrolling

      expect(secondScrollingId).not.toBe(firstScrollingId)
    })
  })

  // ---------- _handleMouseOver / _handleMouseOut ----------

  describe('_handleMouseOver', () => {
    it('enables interactions', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      handler._handleMouseOver()
      expect(map.dragging.enable).toHaveBeenCalled()
      expect(map.scrollWheelZoom.enable).toHaveBeenCalled()
    })
  })

  describe('_handleMouseOut', () => {
    it('disables interactions when not dragging', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      // draggingMap is false by default
      handler._handleMouseOut()
      expect(map.dragging.disable).toHaveBeenCalled()
      expect(map.scrollWheelZoom.disable).toHaveBeenCalled()
    })

    it('does NOT disable interactions while the map is being dragged', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      handler._handleDragging({ type: 'movestart' })
      handler._handleMouseOut()
      expect(map.dragging.disable).not.toHaveBeenCalled()
    })

    it('disables again after dragging ends', () => {
      const GH = registerGestureHandling(L)
      const map = makeMap()
      const handler = new GH(map)
      handler._handleDragging({ type: 'movestart' })
      handler._handleDragging({ type: 'moveend' })
      handler._handleMouseOut()
      expect(map.dragging.disable).toHaveBeenCalled()
    })
  })
})
