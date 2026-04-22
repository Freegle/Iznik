import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import {
  suppressException,
  suppressSentryEvent,
} from '~/composables/useSuppressException'

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

  it('suppresses Freestar ftUtils.js getInnerDimensions null errors (Firefox phrasing, NUXT3-D2H)', () => {
    // Sentry issue NUXT3-D2H (7372854976): 337 events / 119 users.
    // Firefox surfaces null-property TypeErrors as:
    //   "can't access property \"display\", t is null"
    // Culprit: getInnerDimensions(ftUtils) in /ftUtils.js.
    expect(
      suppressException({
        name: 'TypeError',
        message: 'can\'t access property "display", t is null',
        stack:
          'TypeError: can\'t access property "display", t is null\n' +
          '    at getInnerDimensions (https://a.pub.network/.../ftUtils.js:1:4567)',
      })
    ).toBe(true)
  })

  it('suppresses Freestar ftUtils.js getInnerDimensions null errors (Chrome phrasing, NUXT3-D2H)', () => {
    // Chrome phrasing of the same Freestar ftUtils.js getInnerDimensions crash.
    expect(
      suppressException({
        name: 'TypeError',
        message: "Cannot read properties of null (reading 'display')",
        stack:
          "TypeError: Cannot read properties of null (reading 'display')\n" +
          '    at Object.getInnerDimensions (https://a.pub.network/.../ftUtils.js:1:4567)',
      })
    ).toBe(true)
  })

  it('suppresses Freestar errors identified by getInnerDimensions alone in stack', () => {
    // If the filename has been stripped (e.g. due to SourceMap rewriting or
    // bundler renaming), the getInnerDimensions function name in the stack is
    // still a Freestar-specific signature.
    expect(
      suppressException({
        name: 'TypeError',
        message: 'can\'t access property "display", t is null',
        stack: '    at getInnerDimensions (something.js:1:1)',
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

  it('suppresses NotReadableError I/O read failures (NUXT3-D2P)', () => {
    // Sentry issue NUXT3-D2P (7372873858): 568 events / 357 users.
    // Mobile Safari/iOS file/camera read failures (permission denied,
    // iCloud Photo not downloaded, file picker cancelled, etc.).
    const err = new TypeError(
      'NotReadableError: The I/O read operation failed.'
    )
    expect(suppressException(err)).toBe(true)
  })

  it('suppresses NotReadableError when surfaced via toString only', () => {
    expect(
      suppressException({
        toString: () =>
          'TypeError: NotReadableError: The I/O read operation failed.',
      })
    ).toBe(true)
  })

  it('does not suppress unrelated NotReadableErrors', () => {
    // A bare NotReadableError without the I/O read phrase should still report,
    // since it may indicate a real bug elsewhere.
    expect(
      suppressException({
        name: 'NotReadableError',
        message: 'Could not start video source',
      })
    ).toBe(false)
  })

  it('does not suppress unrelated TypeErrors', () => {
    expect(
      suppressException({
        name: 'TypeError',
        message: "Cannot read properties of undefined (reading 'foo')",
      })
    ).toBe(false)
  })
})

describe('suppressSentryEvent', () => {
  it('returns false for falsy input', () => {
    expect(suppressSentryEvent(null)).toBe(false)
    expect(suppressSentryEvent(undefined)).toBe(false)
    expect(suppressSentryEvent({})).toBe(false)
  })

  it('returns false when exception has no values', () => {
    expect(suppressSentryEvent({ exception: {} })).toBe(false)
    expect(suppressSentryEvent({ exception: { values: [] } })).toBe(false)
  })

  it('suppresses NUXT3-CES: ftUtils.js getPlacementPosition frame', () => {
    // Synthetic Sentry event matching the NUXT3-CES signature (issue 6579683231):
    // TypeError: Cannot read properties of null (reading 'document')
    //   at Object.getPlacementPosition (.../ftUtils.js)
    const event = {
      exception: {
        values: [
          {
            type: 'TypeError',
            value: "Cannot read properties of null (reading 'document')",
            stacktrace: {
              frames: [
                {
                  function: 'Object.getPlacementPosition',
                  filename: 'https://a.pub.network/freegle.org/ftUtils.js',
                  abs_path: 'https://a.pub.network/freegle.org/ftUtils.js',
                },
              ],
            },
          },
        ],
      },
    }
    expect(suppressSentryEvent(event)).toBe(true)
  })

  it('suppresses NUXT3-D2H: ftUtils.js getInnerDimensions frame', () => {
    const event = {
      exception: {
        values: [
          {
            type: 'TypeError',
            value: "Cannot read properties of null (reading 'display')",
            stacktrace: {
              frames: [
                {
                  function: 'getInnerDimensions',
                  filename: 'https://a.pub.network/freegle.org/ftUtils.js',
                },
              ],
            },
          },
        ],
      },
    }
    expect(suppressSentryEvent(event)).toBe(true)
  })

  it('matches when Freestar frame is not the top frame', () => {
    const event = {
      exception: {
        values: [
          {
            stacktrace: {
              frames: [
                { function: 'userCode', filename: '/app.js' },
                {
                  function: 'getPlacementPosition',
                  filename: '/ftUtils.js',
                },
                { function: 'innerWrapper', filename: '/lib.js' },
              ],
            },
          },
        ],
      },
    }
    expect(suppressSentryEvent(event)).toBe(true)
  })

  it('does not suppress ftUtils.js frames with unknown function names', () => {
    // Narrow match: a new bug in ftUtils.js with a different function should
    // still be reported so we notice it rather than masking it.
    const event = {
      exception: {
        values: [
          {
            stacktrace: {
              frames: [
                {
                  function: 'someNewFunction',
                  filename: 'https://a.pub.network/freegle.org/ftUtils.js',
                },
              ],
            },
          },
        ],
      },
    }
    expect(suppressSentryEvent(event)).toBe(false)
  })

  it('does not suppress getPlacementPosition in a non-ftUtils file', () => {
    // Narrow match: another script happening to define a function called
    // getPlacementPosition shouldn't be silently dropped.
    const event = {
      exception: {
        values: [
          {
            stacktrace: {
              frames: [
                {
                  function: 'getPlacementPosition',
                  filename: '/app/MyComponent.vue',
                },
              ],
            },
          },
        ],
      },
    }
    expect(suppressSentryEvent(event)).toBe(false)
  })

  it('does not suppress unrelated events', () => {
    const event = {
      exception: {
        values: [
          {
            type: 'TypeError',
            value: "Cannot read properties of null (reading 'foo')",
            stacktrace: {
              frames: [
                { function: 'myHandler', filename: '/app/MyComponent.vue' },
              ],
            },
          },
        ],
      },
    }
    expect(suppressSentryEvent(event)).toBe(false)
  })
})
