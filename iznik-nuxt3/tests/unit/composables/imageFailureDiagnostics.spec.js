import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const { mockCaptureMessage, mockClientWarn } = vi.hoisted(() => ({
  mockCaptureMessage: vi.fn(),
  mockClientWarn: vi.fn(),
}))

vi.mock('@sentry/browser', () => ({
  captureMessage: mockCaptureMessage,
}))

vi.mock('~/composables/useClientLog', () => ({
  warn: mockClientWarn,
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({
    public: {
      IMAGE_DELIVERY: 'https://delivery.test',
      USER_SITE: 'https://www.test',
      APIv2: 'https://api.test/apiv2',
    },
  }),
}))

import {
  reportImageFailure,
  probeUrl,
  checkImageHost,
  resetImageFailureDiagnostics,
  useImageHostBlocked,
  isCrawler,
  MAX_INDIVIDUAL_REPORTS,
  AGGREGATE_EVERY,
} from '~/composables/useImageFailureDiagnostics'

const IPHONE_UA =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148'
const GOOGLEBOT_UA =
  'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/W.X.Y.Z Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
const IMAGE_URL =
  'https://delivery.test/?url=https%3A%2F%2Fuploads.test%2Fabc&w=400'

function setUserAgent(ua) {
  Object.defineProperty(window.navigator, 'userAgent', {
    value: ua,
    configurable: true,
  })
}

// A fetch stand-in driven by a per-URL decision: 'ok' answers 200, 'notfound'
// answers 404, 'cors' rejects cors mode but answers opaquely in no-cors mode,
// 'dead' rejects every mode like an unreachable host.
function fakeFetch(decide) {
  return vi.fn((url, opts = {}) => {
    const verdict = decide(url)
    if (verdict === 'ok') {
      return Promise.resolve({ status: 200, type: 'cors' })
    }
    if (verdict === 'notfound') {
      return Promise.resolve({ status: 404, type: 'cors' })
    }
    if (verdict === 'cors') {
      return opts.mode === 'no-cors'
        ? Promise.resolve({ status: 0, type: 'opaque' })
        : Promise.reject(new TypeError('Load failed'))
    }
    return Promise.reject(new TypeError('Load failed'))
  })
}

const isDelivery = (url) => url.startsWith('https://delivery.test/')
const isApi = (url) => url.startsWith('https://api.test/')

describe('useImageFailureDiagnostics', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    resetImageFailureDiagnostics()
    setUserAgent(IPHONE_UA)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  describe('isCrawler', () => {
    it('recognises crawler user agents', () => {
      expect(isCrawler(GOOGLEBOT_UA)).toBe(true)
      expect(isCrawler('Mozilla/5.0 HeadlessChrome/120')).toBe(true)
      expect(isCrawler(IPHONE_UA)).toBe(false)
    })
  })

  describe('probeUrl', () => {
    it('reports the HTTP status when the host answers', async () => {
      const fetch = fakeFetch(() => 'notfound')
      expect(await probeUrl(IMAGE_URL, fetch)).toEqual({
        probed: true,
        reachable: true,
        status: 404,
      })
      expect(fetch).toHaveBeenCalledTimes(1)
    })

    it('treats a CORS refusal as reachable via an opaque no-cors fetch', async () => {
      const fetch = fakeFetch(() => 'cors')
      expect(await probeUrl(IMAGE_URL, fetch)).toEqual({
        probed: true,
        reachable: true,
        status: null,
        opaque: true,
      })
      expect(fetch).toHaveBeenCalledTimes(2)
      expect(fetch.mock.calls[1][1].mode).toBe('no-cors')
    })

    it('reports an unreachable host when both modes fail', async () => {
      const fetch = fakeFetch(() => 'dead')
      expect(await probeUrl(IMAGE_URL, fetch)).toEqual({
        probed: true,
        reachable: false,
        error: 'Load failed',
      })
    })

    it('gives up after the timeout without a second attempt', async () => {
      vi.useFakeTimers()
      const fetch = vi.fn(
        (url, { signal }) =>
          new Promise((resolve, reject) => {
            signal.addEventListener('abort', () =>
              reject(
                Object.assign(new Error('aborted'), { name: 'AbortError' })
              )
            )
          })
      )
      const pending = probeUrl(IMAGE_URL, fetch)
      await vi.advanceTimersByTimeAsync(9000)
      expect(await pending).toEqual({
        probed: true,
        reachable: false,
        error: 'timeout',
      })
      expect(fetch).toHaveBeenCalledTimes(1)
    })

    it('does nothing without a URL or a fetch implementation', async () => {
      expect(
        await probeUrl(
          null,
          fakeFetch(() => 'ok')
        )
      ).toEqual({
        probed: false,
      })
      expect(await probeUrl(IMAGE_URL, null)).toEqual({ probed: false })
    })

    it('never uses the real fetch under Vitest when no fetch is supplied', async () => {
      // Other component specs fire image errors without mocking this module;
      // a real network probe there would leak timers past the spec.
      const realFetch = vi.fn()
      vi.stubGlobal('fetch', realFetch)
      try {
        expect(await probeUrl(IMAGE_URL)).toEqual({ probed: false })
        expect(realFetch).not.toHaveBeenCalled()
      } finally {
        vi.unstubAllGlobals()
      }
    })
  })

  describe('reportImageFailure', () => {
    it('ignores crawlers entirely', async () => {
      setUserAgent(GOOGLEBOT_UA)
      const fetch = fakeFetch(() => 'notfound')
      expect(
        await reportImageFailure(
          { src: 'freegletusd-abc', url: IMAGE_URL },
          fetch
        )
      ).toBeNull()
      expect(fetch).not.toHaveBeenCalled()
      expect(mockCaptureMessage).not.toHaveBeenCalled()
    })

    it('reports a missing image with its probe result and no host check', async () => {
      const fetch = fakeFetch(() => 'notfound')
      await reportImageFailure(
        { src: 'freegletusd-abc', url: IMAGE_URL },
        fetch
      )

      expect(mockCaptureMessage).toHaveBeenCalledTimes(1)
      expect(mockCaptureMessage).toHaveBeenCalledWith(
        'Failed to fetch image freegletusd-abc',
        expect.objectContaining({
          level: 'warning',
          extra: expect.objectContaining({
            url: IMAGE_URL,
            probe: expect.objectContaining({ reachable: true, status: 404 }),
            failures_in_session: 1,
            image_host_blocked: false,
          }),
        })
      )
      // The host answered, so the API/delivery comparison never runs.
      expect(fetch).toHaveBeenCalledTimes(1)
      expect(useImageHostBlocked().value).toBe(false)
    })

    it('flags the session once when delivery is unreachable but the API is not', async () => {
      const fetch = fakeFetch((url) => (isApi(url) ? 'ok' : 'dead'))

      await reportImageFailure(
        { src: 'freegletusd-abc', url: IMAGE_URL },
        fetch
      )

      expect(useImageHostBlocked().value).toBe(true)
      const messages = mockCaptureMessage.mock.calls.map((c) => c[0])
      expect(messages).toEqual([
        'Failed to fetch image freegletusd-abc',
        'Image host unreachable: delivery blocked on this device or network',
      ])
      expect(mockClientWarn).toHaveBeenCalledTimes(1)
      expect(mockClientWarn).toHaveBeenCalledWith(
        'image_host_unreachable',
        expect.objectContaining({
          delivery_error: 'Load failed',
          failures_so_far: 1,
          first_failed_url: IMAGE_URL,
        })
      )
      const apiProbes = fetch.mock.calls.filter((c) => isApi(c[0])).length
      expect(apiProbes).toBe(1)

      // A second failure is reported individually but the host check is not
      // repeated.
      await reportImageFailure(
        { src: 'freegletusd-def', url: IMAGE_URL },
        fetch
      )
      expect(mockCaptureMessage).toHaveBeenCalledTimes(3)
      expect(mockCaptureMessage.mock.calls[2][0]).toBe(
        'Failed to fetch image freegletusd-def'
      )
      expect(mockCaptureMessage.mock.calls[2][1].extra.image_host_blocked).toBe(
        true
      )
      expect(fetch.mock.calls.filter((c) => isApi(c[0])).length).toBe(1)
      expect(mockClientWarn).toHaveBeenCalledTimes(1)
    })

    it('does not flag the session when the API is unreachable too (offline)', async () => {
      const fetch = fakeFetch(() => 'dead')

      await reportImageFailure(
        { src: 'freegletusd-abc', url: IMAGE_URL },
        fetch
      )

      expect(useImageHostBlocked().value).toBe(false)
      expect(mockCaptureMessage).toHaveBeenCalledTimes(1)
      expect(mockClientWarn).not.toHaveBeenCalled()
    })

    it('caps individual reports and then reports an aggregate count', async () => {
      const fetch = fakeFetch(() => 'notfound')
      const total = MAX_INDIVIDUAL_REPORTS + AGGREGATE_EVERY

      for (let i = 1; i <= total; i++) {
        await reportImageFailure(
          { src: 'freegletusd-' + i, url: IMAGE_URL },
          fetch
        )
      }

      // MAX_INDIVIDUAL_REPORTS individual reports, then one aggregate at the
      // AGGREGATE_EVERY boundary, and nothing in between.
      expect(mockCaptureMessage).toHaveBeenCalledTimes(
        MAX_INDIVIDUAL_REPORTS + 1
      )
      const last = mockCaptureMessage.mock.calls[MAX_INDIVIDUAL_REPORTS]
      expect(last[0]).toBe(
        'Image failures continuing: ' + AGGREGATE_EVERY + ' this session'
      )
      expect(last[1].extra.latest_src).toBe('freegletusd-' + AGGREGATE_EVERY)
      // Only the individually reported failures were probed.
      expect(fetch).toHaveBeenCalledTimes(MAX_INDIVIDUAL_REPORTS)
    })
  })

  describe('checkImageHost', () => {
    it('probes the delivery and API hosts from runtime config', async () => {
      const fetch = fakeFetch(() => 'ok')
      const result = await checkImageHost(fetch)

      const urls = fetch.mock.calls.map((c) => c[0])
      expect(urls.some(isDelivery)).toBe(true)
      expect(urls.some(isApi)).toBe(true)
      expect(urls.find(isDelivery)).toContain(
        encodeURIComponent('https://www.test/defaultprofile.png')
      )
      expect(urls.find(isApi)).toBe('https://api.test/apiv2/online')
      expect(result.delivery.reachable).toBe(true)
      expect(result.api.reachable).toBe(true)
      expect(useImageHostBlocked().value).toBe(false)
    })

    it('runs only once per session', async () => {
      const fetch = fakeFetch(() => 'ok')
      const first = await checkImageHost(fetch)
      const second = await checkImageHost(fetch)
      expect(second).toBe(first)
      expect(fetch).toHaveBeenCalledTimes(2)
    })
  })
})
