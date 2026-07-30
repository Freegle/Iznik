import { describe, it, expect, afterEach, vi } from 'vitest'
import {
  toRadian,
  getDistance,
  attribution,
  calculateMapHeight,
  osmtile,
  loadLeaflet,
  nearbyGroups,
} from '~/composables/useMap'

// Keep loadLeaflet's dynamic imports away from the real bundles. wicket's
// leaflet plugin extends real Leaflet classes at import time.
vi.mock('wicket', () => ({ default: {} }))
vi.mock('wicket/wicket-leaflet', () => ({}))
// The leaflet mock carries just enough surface for registerGestureHandling().
vi.mock('leaflet/dist/leaflet-src.esm', () => ({
  __mockLeaflet: true,
  Map: { mergeOptions: vi.fn(), addInitHook: vi.fn() },
  Handler: { extend: vi.fn(() => class {}) },
  DomEvent: { on: vi.fn(), off: vi.fn() },
  DomUtil: { addClass: vi.fn(), removeClass: vi.fn(), hasClass: vi.fn() },
}))

// ============================================================
// toRadian
// ============================================================
describe('toRadian', () => {
  it.each([
    [0, 0],
    [90, Math.PI / 2],
    [180, Math.PI],
    [270, (3 * Math.PI) / 2],
    [360, 2 * Math.PI],
    [-90, -Math.PI / 2],
    [-180, -Math.PI],
    [45, Math.PI / 4],
    [30, Math.PI / 6],
    [60, Math.PI / 3],
  ])('toRadian(%s) ≈ %s', (degrees, expected) => {
    expect(toRadian(degrees)).toBeCloseTo(expected, 10)
  })

  it('satisfies the identity: toRadian(180) === π', () => {
    expect(toRadian(180)).toBeCloseTo(Math.PI, 14)
  })

  it('satisfies 2π for a full circle (360°)', () => {
    expect(toRadian(360)).toBeCloseTo(2 * Math.PI, 14)
  })

  it('is a linear transformation: toRadian(2x) === 2 * toRadian(x)', () => {
    const x = 37
    expect(toRadian(2 * x)).toBeCloseTo(2 * toRadian(x), 10)
  })
})

// ============================================================
// getDistance — Haversine-based, returns metres
// ============================================================
describe('getDistance', () => {
  it('returns 0 for the same point', () => {
    expect(getDistance([51.5074, -0.1278], [51.5074, -0.1278])).toBeCloseTo(
      0,
      5
    )
  })

  it('is symmetric: A→B === B→A', () => {
    const london = [51.5074, -0.1278]
    const paris = [48.8566, 2.3522]
    const ab = getDistance(london, paris)
    const ba = getDistance(paris, london)
    expect(ab).toBeCloseTo(ba, 3)
  })

  // Known distances with loose tolerances to be robust across floating-point
  it.each([
    // [label, origin, destination, expectedKm, toleranceKm]
    ['London → Paris ~340 km', [51.5074, -0.1278], [48.8566, 2.3522], 340, 20],
    [
      'London → Manchester ~260 km',
      [51.5074, -0.1278],
      [53.4808, -2.2426],
      260,
      20,
    ],
    [
      'New York → Los Angeles ~3940 km',
      [40.7128, -74.006],
      [34.0522, -118.2437],
      3940,
      100,
    ],
    [
      'Sydney → Auckland ~2160 km',
      [-33.8688, 151.2093],
      [-36.8485, 174.7633],
      2160,
      100,
    ],
    // Short distance: ~1 km walk within London
    [
      'Close points in London ~900 m',
      [51.5074, -0.1278],
      [51.5073, -0.1175],
      0.9,
      0.3,
    ],
  ])('%s', (label, origin, destination, expectedKm, toleranceKm) => {
    const distanceMetres = getDistance(origin, destination)
    const distanceKm = distanceMetres / 1000
    expect(distanceKm).toBeGreaterThan(expectedKm - toleranceKm)
    expect(distanceKm).toBeLessThan(expectedKm + toleranceKm)
  })

  it('handles points on the equator (0° latitude)', () => {
    // Two points on the equator 1° apart ≈ 111.3 km
    const d = getDistance([0, 0], [0, 1])
    expect(d / 1000).toBeGreaterThan(100)
    expect(d / 1000).toBeLessThan(125)
  })

  it('handles southern hemisphere coordinates', () => {
    // Buenos Aires to Cape Town ≈ 6870 km
    const d = getDistance([-34.6037, -58.3816], [-33.9249, 18.4241])
    expect(d / 1000).toBeGreaterThan(6500)
    expect(d / 1000).toBeLessThan(7200)
  })

  it('handles antipodal points (maximum distance ≈ 20,015 km)', () => {
    // True antipodes of the equator/prime meridian
    const d = getDistance([0, 0], [0, 180])
    // Half circumference ≈ 20,015 km
    expect(d / 1000).toBeGreaterThan(19000)
    expect(d / 1000).toBeLessThan(21000)
  })

  it('returns a finite number for all ordinary inputs', () => {
    const d = getDistance([51.5, -0.1], [48.8, 2.3])
    expect(Number.isFinite(d)).toBe(true)
    expect(d).toBeGreaterThan(0)
  })
})

// ============================================================
// attribution
// ============================================================
describe('attribution', () => {
  it('returns a non-empty string', () => {
    const result = attribution()
    expect(typeof result).toBe('string')
    expect(result.length).toBeGreaterThan(0)
  })

  it('mentions OpenStreetMap', () => {
    expect(attribution()).toContain('OpenStreetMap')
  })

  it('contains an anchor link to openstreetmap.org', () => {
    expect(attribution()).toContain('https://www.openstreetmap.org/')
  })

  it('returns the same value on repeated calls (idempotent)', () => {
    expect(attribution()).toBe(attribution())
  })
})

// ============================================================
// osmtile
// ============================================================
describe('osmtile', () => {
  it('returns the OSM_TILE value from runtimeConfig.public', () => {
    // The global useRuntimeConfig mock (tests/unit/setup.ts) does NOT set
    // OSM_TILE, so the function returns undefined in the base test fixture.
    // We test both: the base case (undefined) and a configured value.
    const result = osmtile()
    // Base mock has no OSM_TILE key → undefined
    expect(result).toBeUndefined()
  })

  it('returns the configured tile URL when OSM_TILE is set', () => {
    const originalConfig = globalThis.useRuntimeConfig
    globalThis.useRuntimeConfig = () => ({
      public: { OSM_TILE: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' },
    })
    try {
      expect(osmtile()).toBe('https://tile.openstreetmap.org/{z}/{x}/{y}.png')
    } finally {
      globalThis.useRuntimeConfig = originalConfig
    }
  })
})

// ============================================================
// calculateMapHeight
// ============================================================
describe('calculateMapHeight', () => {
  // import.meta.client is substituted to true in source under the unit-test
  // transform (see nuxt-import-meta-flags in the config), matching the client
  // build — only client behaviour is testable; the server branch is
  // compile-time dead here.
  describe('client-side behaviour', () => {
    it('computes height as (window.innerHeight / fraction) - 70', () => {
      // Set innerHeight to a value that produces a height > 200
      Object.defineProperty(window, 'innerHeight', {
        value: 1080,
        configurable: true,
        writable: true,
      })
      // fraction=4: 1080/4 - 70 = 270 - 70 = 200. Actually 270 - 70 = 200.
      // Use fraction=2: 1080/2 - 70 = 540 - 70 = 470
      expect(calculateMapHeight(2)).toBe(470)
    })

    it('enforces a minimum height of 200 when computed value is smaller', () => {
      // Make the window tiny so height < 200
      Object.defineProperty(window, 'innerHeight', {
        value: 300,
        configurable: true,
        writable: true,
      })
      // fraction=4: 300/4 - 70 = 75 - 70 = 5 → clamp to 200
      expect(calculateMapHeight(4)).toBe(200)
    })

    it('enforces a minimum height of 200 when calculation is exactly 200', () => {
      // Make calculation land on exactly 200
      // height = innerHeight/fraction - 70 = 200 → innerHeight = 270 * fraction
      Object.defineProperty(window, 'innerHeight', {
        value: 1080,
        configurable: true,
        writable: true,
      })
      // 1080/4 - 70 = 270 - 70 = 200 → not < 200, so returns 200
      expect(calculateMapHeight(4)).toBe(200)
    })

    it('returns height above minimum for large window sizes', () => {
      Object.defineProperty(window, 'innerHeight', {
        value: 900,
        configurable: true,
        writable: true,
      })
      // fraction=3: 900/3 - 70 = 300 - 70 = 230 > 200
      expect(calculateMapHeight(3)).toBe(230)
    })

    it('handles fractional division correctly (non-integer fraction)', () => {
      Object.defineProperty(window, 'innerHeight', {
        value: 600,
        configurable: true,
        writable: true,
      })
      // fraction=1.5: 600/1.5 - 70 = 400 - 70 = 330
      expect(calculateMapHeight(1.5)).toBeCloseTo(330, 5)
    })
  })
})

// ============================================================
// nearbyGroups — find joinable communities near a point
// ============================================================
describe('nearbyGroups', () => {
  // Reference search point: Manchester city centre
  const MANCHESTER = { lat: 53.4808, lng: -2.2426 }

  // A small fixture of groups at known locations
  const manchesterGroup = {
    id: 1,
    namedisplay: 'Manchester Freegle',
    nameshort: 'Manchester',
    lat: 53.4808,
    lng: -2.2426,
    onmap: 1,
    publish: 1,
  }
  const stockportGroup = {
    id: 2,
    namedisplay: 'Stockport Freegle',
    nameshort: 'Stockport',
    lat: 53.4106,
    lng: -2.1575,
    onmap: 1,
    publish: 1,
  }
  const londonGroup = {
    id: 3,
    namedisplay: 'London Freegle',
    nameshort: 'London',
    lat: 51.5074,
    lng: -0.1278,
    onmap: 1,
    publish: 1,
  }

  it('returns an empty array when the point is missing or incomplete', () => {
    expect(nearbyGroups(null, [manchesterGroup])).toEqual([])
    expect(nearbyGroups(undefined, [manchesterGroup])).toEqual([])
    expect(nearbyGroups({ lat: 53.48 }, [manchesterGroup])).toEqual([])
    expect(nearbyGroups({ lng: -2.24 }, [manchesterGroup])).toEqual([])
  })

  it('returns an empty array when there are no groups', () => {
    expect(nearbyGroups(MANCHESTER, [])).toEqual([])
    expect(nearbyGroups(MANCHESTER, null)).toEqual([])
    expect(nearbyGroups(MANCHESTER, undefined)).toEqual([])
  })

  it('sorts groups by ascending distance from the point', () => {
    const result = nearbyGroups(MANCHESTER, [
      londonGroup,
      manchesterGroup,
      stockportGroup,
    ])
    expect(result.map((g) => g.id)).toEqual([1, 2, 3])
  })

  it('accepts groups passed as an object map (e.g. summaryList)', () => {
    const result = nearbyGroups(MANCHESTER, {
      3: londonGroup,
      1: manchesterGroup,
      2: stockportGroup,
    })
    expect(result.map((g) => g.id)).toEqual([1, 2, 3])
  })

  it('excludes groups that are not on the map', () => {
    const hidden = { ...stockportGroup, id: 9, onmap: 0 }
    const result = nearbyGroups(MANCHESTER, [manchesterGroup, hidden])
    expect(result.map((g) => g.id)).toEqual([1])
  })

  it('excludes groups that are not published', () => {
    const unpublished = { ...stockportGroup, id: 9, publish: 0 }
    const result = nearbyGroups(MANCHESTER, [manchesterGroup, unpublished])
    expect(result.map((g) => g.id)).toEqual([1])
  })

  it('excludes groups without coordinates', () => {
    const noCoords = { ...stockportGroup, id: 9, lat: null, lng: null }
    const result = nearbyGroups(MANCHESTER, [manchesterGroup, noCoords])
    expect(result.map((g) => g.id)).toEqual([1])
  })

  it('respects showjoin: excludes groups further than showjoin miles away', () => {
    // London is ~260 km (~160 miles) from Manchester. A showjoin of 20 miles
    // means London will not offer to be joined from Manchester.
    const londonLimited = { ...londonGroup, showjoin: 20 }
    const result = nearbyGroups(MANCHESTER, [manchesterGroup, londonLimited])
    expect(result.map((g) => g.id)).toEqual([1])
  })

  it('respects showjoin: includes groups within showjoin miles', () => {
    // Stockport is ~8 km (~5 miles) from Manchester; showjoin 20 includes it.
    const stockportLimited = { ...stockportGroup, showjoin: 20 }
    const result = nearbyGroups(MANCHESTER, [stockportLimited])
    expect(result.map((g) => g.id)).toEqual([2])
  })

  it('treats a falsy showjoin as no distance limit', () => {
    const farNoLimit = { ...londonGroup, showjoin: 0 }
    const result = nearbyGroups(MANCHESTER, [farNoLimit])
    expect(result.map((g) => g.id)).toEqual([3])
  })

  it('uses the nearer of the main and secondary (alt) centres', () => {
    // Main centre in London (far) but an alt centre near Manchester (close),
    // with a tight showjoin that only the alt centre satisfies.
    const twoCentres = {
      ...londonGroup,
      id: 9,
      showjoin: 20,
      altlat: 53.4808,
      altlng: -2.2426,
    }
    const result = nearbyGroups(MANCHESTER, [twoCentres])
    expect(result.map((g) => g.id)).toEqual([9])
  })

  it('excludes groups the user is already a member of', () => {
    const result = nearbyGroups(
      MANCHESTER,
      [manchesterGroup, stockportGroup, londonGroup],
      { isMember: (id) => id === 1 }
    )
    expect(result.map((g) => g.id)).toEqual([2, 3])
  })

  it('limits the number of results returned', () => {
    const result = nearbyGroups(
      MANCHESTER,
      [londonGroup, manchesterGroup, stockportGroup],
      { limit: 2 }
    )
    expect(result.map((g) => g.id)).toEqual([1, 2])
  })

  it('returns the group objects unchanged', () => {
    const result = nearbyGroups(MANCHESTER, [manchesterGroup])
    expect(result[0]).toBe(manchesterGroup)
  })
})

// loadLeaflet — the unit-test transform substitutes the client flag, so the
// client path runs; the leaflet module is mocked at the top of this file so no
// real bundle loads.
// ---------------------------------------------------------------------------
describe('loadLeaflet', () => {
  afterEach(() => {
    delete window.L
  })

  it('sets window.L from the leaflet module when unset', async () => {
    delete window.L
    await loadLeaflet()
    expect(window.L).toBeTruthy()
    expect(window.L.__mockLeaflet).toBe(true)
  })

  it('does not replace an existing window.L', async () => {
    const existing = { existing: true }
    window.L = existing
    await loadLeaflet()
    expect(window.L).toBe(existing)
  })
})
