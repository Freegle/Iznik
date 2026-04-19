import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { suppressException } from '~/composables/useSuppressException'

describe('suppressException', () => {
  let logSpy

  beforeEach(() => {
    logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
  })

  afterEach(() => {
    logSpy.mockRestore()
  })

  it('returns false for falsy input', () => {
    expect(suppressException(null)).toBe(false)
    expect(suppressException(undefined)).toBe(false)
    expect(suppressException(0)).toBe(false)
    expect(suppressException('')).toBe(false)
  })

  it('returns false for unrelated errors', () => {
    const err = new Error('Network request failed')
    expect(suppressException(err)).toBe(false)
    expect(logSpy).not.toHaveBeenCalled()
  })

  it('suppresses leaflet errors by message', () => {
    expect(suppressException({ message: 'leaflet exploded' })).toBe(true)
    expect(suppressException({ message: 'bad LatLng' })).toBe(true)
    expect(suppressException({ message: 'Map container not found' })).toBe(true)
  })

  it('suppresses leaflet errors by stack', () => {
    expect(suppressException({ stack: 'at leaflet.js:42' })).toBe(true)
    expect(suppressException({ stack: 'LMap.vue in stack' })).toBe(true)
    expect(suppressException({ stack: 'LMarker.vue in stack' })).toBe(true)
    expect(suppressException({ stack: 'call to layer' })).toBe(true)
  })

  it('logs leaflet suppression to console', () => {
    suppressException({ message: 'leaflet exploded' })
    expect(logSpy).toHaveBeenCalledWith('Leaflet in stack - ignore')
  })

  it('suppresses GChart errors via stack containing "chart element"', () => {
    expect(suppressException({ stack: 'GChart chart element render' })).toBe(
      true
    )
  })

  it('logs chart-element suppression to console', () => {
    suppressException({ stack: 'chart element broke' })
    expect(logSpy).toHaveBeenCalledWith(
      'suppressException chart element - ignore'
    )
  })

  it('does not blow up when message is missing but stack matches', () => {
    expect(suppressException({ stack: 'leaflet' })).toBe(true)
  })

  it('does not blow up when stack is missing but message matches', () => {
    expect(suppressException({ message: 'LatLng failure' })).toBe(true)
  })

  it('returns false when neither message nor stack matches known patterns', () => {
    expect(
      suppressException({ message: 'foo', stack: 'bar' })
    ).toBe(false)
  })

  it('suppresses Freestar ftUtils.js null-document errors', () => {
    // Sentry issue NUXT3-CES (6579683231): 11k events from Freestar third-party JS.
    expect(
      suppressException({
        name: 'TypeError',
        message: "Cannot read properties of null (reading 'document')",
        stack:
          "TypeError: Cannot read properties of null (reading 'document')\n" +
          '    at Object.getPlacementPosition (https://a.pub.network/.../ftUtils.js:1:2345)',
      })
    ).toBe(true)
  })

  it('suppresses Freestar errors identified by getPlacementPosition in stack', () => {
    expect(
      suppressException({
        name: 'TypeError',
        message: "Cannot read properties of null (reading 'document')",
        stack: '    at getPlacementPosition (something.js:1:1)',
      })
    ).toBe(true)
  })

  it('does not suppress unrelated null-document TypeErrors from our code', () => {
    expect(
      suppressException({
        name: 'TypeError',
        message: "Cannot read properties of null (reading 'document')",
        stack: '    at MyComponent.vue:42 (https://example.com/MyComponent.vue)',
      })
    ).toBe(false)
  })
})
