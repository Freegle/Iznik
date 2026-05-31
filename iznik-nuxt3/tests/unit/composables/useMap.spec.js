import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import {
  toRadian,
  getDistance,
  attribution,
  calculateMapHeight,
  osmtile,
  loadLeaflet,
} from '~/composables/useMap'

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
    [
      'London → Paris ~340 km',
      [51.5074, -0.1278],
      [48.8566, 2.3522],
      340,
      20,
    ],
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
      expect(osmtile()).toBe(
        'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
      )
    } finally {
      globalThis.useRuntimeConfig = originalConfig
    }
  })
})

// ============================================================
// calculateMapHeight
// ============================================================
describe('calculateMapHeight', () => {
  afterEach(() => {
    // Remove any process.client stub we set
    try {
      delete process.client
    } catch {
      // Some environments may not allow deletion
    }
  })

  it('returns 0 when process.client is falsy (server-side render)', () => {
    // In Node.js / vitest, process.client is undefined → falsy
    // The function guard prevents window access entirely
    expect(calculateMapHeight(4)).toBe(0)
    expect(calculateMapHeight(2)).toBe(0)
    expect(calculateMapHeight(1)).toBe(0)
  })

  describe('client-side behaviour (process.client = true)', () => {
    beforeEach(() => {
      Object.defineProperty(process, 'client', {
        value: true,
        configurable: true,
        writable: true,
      })
    })

    afterEach(() => {
      try {
        delete process.client
      } catch {
        Object.defineProperty(process, 'client', {
          value: undefined,
          configurable: true,
          writable: true,
        })
      }
    })

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

// loadLeaflet — async; only test the server-side (process.client=false) path
//               to avoid pulling in real dynamic imports in vitest
// ---------------------------------------------------------------------------
describe('loadLeaflet', () => {
  const originalClientFlag = process.client

  afterEach(() => {
    process.client = originalClientFlag
  })

  it('does nothing and resolves when process.client is false', async () => {
    process.client = false
    // Should resolve without throwing
    await expect(loadLeaflet()).resolves.toBeUndefined()
  })

  it('does nothing when process.client is false even if window.L exists', async () => {
    process.client = false
    // Ensure window.L presence doesn't cause issues in SSR path
    global.L = {}
    await expect(loadLeaflet()).resolves.toBeUndefined()
    delete global.L
  })
})
