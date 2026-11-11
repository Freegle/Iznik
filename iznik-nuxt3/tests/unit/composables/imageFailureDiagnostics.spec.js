import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

import {
  reportImageFailure,
  probeUrl,
  checkImageHost,
  resetImageFailureDiagnostics,
  useImageHostBlocked,
  noteImageLoaded,
  isCrawler,
  normaliseDeliveryMessage,
  MAX_INDIVIDUAL_REPORTS,
  AGGREGATE_EVERY,
} from '~/composables/useImageFailureDiagnostics'

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
// answers 404, 'heic' answers 400 with a delivery error body, 'cors' rejects cors mode but answers opaquely in no-cors mode,
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
    if (verdict === 'heic') {
      return Promise.resolve({
        status: 400,
        type: 'cors',
        text: () =>
          Promise.resolve(
            JSON.stringify({
              status: 'error',
              code: 400,
              message:
                'libvips error: heif: Unsupported feature: Unsupported codec (4.3000)\n',
            })
          ),
      })
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

    it('reads the delivery error message from a 4xx body', async () => {
      const fetch = fakeFetch(() => 'heic')
      expect(await probeUrl(IMAGE_URL, fetch)).toEqual({
        probed: true,
        reachable: true,
        status: 400,
        message:
          'libvips error: heif: Unsupported feature: Unsupported codec (4.3000)',
      })
    })

    it('still reports the status when the error body cannot be read', async () => {
      const fetch = vi.fn(() =>
        Promise.resolve({
          status: 502,
          type: 'cors',
          text: () => Promise.reject(new Error('stream closed')),
        })
      )
      expect(await probeUrl(IMAGE_URL, fetch)).toEqual({
        probed: true,
        reachable: true,
        status: 502,
      })
    })

    it('keeps a non-JSON error body as the message', async () => {
      const fetch = vi.fn(() =>
        Promise.resolve({
          status: 404,
          type: 'cors',
          text: () => Promise.resolve('Not Found'),
        })
      )
      const probe = await probeUrl(IMAGE_URL, fetch)
      expect(probe.message).toBe('Not Found')
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

    it('sends a delivery 4xx to Sentry, fingerprinted by status and message', async () => {
      const fetch = fakeFetch(() => 'heic')
      await reportImageFailure(
        { src: 'freegletusd-abc', url: IMAGE_URL },
        fetch
      )

      expect(mockCaptureMessage).toHaveBeenCalledTimes(1)
      expect(mockCaptureMessage).toHaveBeenCalledWith(
        'Image delivery error 400: libvips error: heif: Unsupported feature: Unsupported codec (N)',
        expect.objectContaining({
          level: 'warning',
          fingerprint: [
            'image-delivery-error',
            '400',
            'libvips error: heif: Unsupported feature: Unsupported codec (N)',
          ],
          extra: expect.objectContaining({
            src: 'freegletusd-abc',
            url: IMAGE_URL,
            probe: expect.objectContaining({ reachable: true, status: 400 }),
            failures_in_session: 1,
            image_host_blocked: false,
          }),
        })
      )
      expect(mockClientWarn).not.toHaveBeenCalled()
      // The host answered, so the API/delivery comparison never runs.
      expect(fetch).toHaveBeenCalledTimes(1)
      expect(useImageHostBlocked().value).toBe(false)
    })

    it('gives a 4xx with no readable message its own fingerprint', async () => {
      await reportImageFailure(
        { src: 'freegletusd-abc', url: IMAGE_URL },
        fakeFetch(() => 'notfound')
      )
      expect(mockCaptureMessage).toHaveBeenCalledWith(
        'Image delivery error 404: no message',
        expect.objectContaining({
          fingerprint: ['image-delivery-error', '404', 'no message'],
        })
      )
    })

    it('keeps a reachable host out of Sentry and logs it to Loki', async () => {
      // The delivery host serves the image (200) but the img element still failed: a renderer
      // that does not paint images, for instance. That says nothing about delivery.
      const fetch = fakeFetch(() => 'ok')
      await reportImageFailure(
        { src: 'freegletusd-abc', url: IMAGE_URL },
        fetch
      )

      expect(mockCaptureMessage).not.toHaveBeenCalled()
      expect(mockClientWarn).toHaveBeenCalledWith(
        'image_failed',
        expect.objectContaining({
          src: 'freegletusd-abc',
          url: IMAGE_URL,
          probe: expect.objectContaining({ reachable: true, status: 200 }),
          failures_in_session: 1,
        })
      )
    })

    it('keeps an opaque (CORS-blocked) probe out of Sentry', async () => {
      await reportImageFailure(
        { src: 'freegletusd-abc', url: IMAGE_URL },
        fakeFetch(() => 'cors')
      )
      expect(mockCaptureMessage).not.toHaveBeenCalled()
      expect(mockClientWarn).toHaveBeenCalledWith(
        'image_failed',
        expect.objectContaining({
          probe: expect.objectContaining({ opaque: true }),
        })
      )
    })

    it('flags the session once when delivery is unreachable but the API is not', async () => {
      const fetch = fakeFetch((url) => (isApi(url) ? 'ok' : 'dead'))

      await reportImageFailure(
        { src: 'freegletusd-abc', url: IMAGE_URL },
        fetch
      )

      expect(useImageHostBlocked().value).toBe(true)
      // The per-image network failure is Loki only; the one-off host message is Sentry.
      expect(mockCaptureMessage.mock.calls.map((c) => c[0])).toEqual([
        'Image host unreachable: delivery blocked on this device or network',
      ])
      expect(mockClientWarn.mock.calls.map((c) => c[0])).toEqual([
        'image_failed',
        'image_host_unreachable',
      ])
      expect(mockClientWarn).toHaveBeenCalledWith(
        'image_host_unreachable',
        expect.objectContaining({
          delivery_error: 'Load failed',
          failures_so_far: 1,
          first_failed_url: IMAGE_URL,
          images_loaded_before: 0,
        })
      )
      const apiProbes = fetch.mock.calls.filter((c) => isApi(c[0])).length
      expect(apiProbes).toBe(1)

      // A second failure is logged individually but the host check is not repeated.
      await reportImageFailure(
        { src: 'freegletusd-def', url: IMAGE_URL },
        fetch
      )
      expect(mockCaptureMessage).toHaveBeenCalledTimes(1)
      expect(mockClientWarn.mock.calls.map((c) => c[0])).toEqual([
        'image_failed',
        'image_host_unreachable',
        'image_failed',
      ])
      expect(mockClientWarn.mock.calls[2][1].image_host_blocked).toBe(true)
      expect(fetch.mock.calls.filter((c) => isApi(c[0])).length).toBe(1)
    })

    // A device that blocks the image host never loads an image. One that loaded
    // images and then lost the host dropped its connection partway through, and
    // calling that a block sends support looking for a filter that isn't there.
    it('reports a mid-session drop, not a block, when images had loaded before', async () => {
      const fetch = fakeFetch((url) => (isApi(url) ? 'ok' : 'dead'))
      noteImageLoaded()
      noteImageLoaded()

      await reportImageFailure(
        { src: 'freegletusd-abc', url: IMAGE_URL },
        fetch
      )

      expect(useImageHostBlocked().value).toBe(false)
      expect(mockCaptureMessage.mock.calls.map((c) => c[0])).toEqual([
        'Image host stopped answering mid-session: images had loaded earlier',
      ])
      expect(mockClientWarn).toHaveBeenCalledWith(
        'image_host_dropped',
        expect.objectContaining({ images_loaded_before: 2 })
      )
    })

    it('does not flag the session when the API is unreachable too (offline)', async () => {
      const fetch = fakeFetch(() => 'dead')

      await reportImageFailure(
        { src: 'freegletusd-abc', url: IMAGE_URL },
        fetch
      )

      expect(useImageHostBlocked().value).toBe(false)
      expect(mockCaptureMessage).not.toHaveBeenCalled()
      expect(mockClientWarn.mock.calls.map((c) => c[0])).toEqual([
        'image_failed',
      ])
    })

    it('caps individual reports and then logs an aggregate count to Loki', async () => {
      const fetch = fakeFetch(() => 'notfound')
      const total = MAX_INDIVIDUAL_REPORTS + AGGREGATE_EVERY

      for (let i = 1; i <= total; i++) {
        await reportImageFailure(
          { src: 'freegletusd-' + i, url: IMAGE_URL },
          fetch
        )
      }

      // MAX_INDIVIDUAL_REPORTS individual Sentry reports, then nothing in Sentry.
      expect(mockCaptureMessage).toHaveBeenCalledTimes(MAX_INDIVIDUAL_REPORTS)
      // One aggregate line at the AGGREGATE_EVERY boundary, and nothing in between.
      expect(mockClientWarn).toHaveBeenCalledTimes(1)
      const [name, context] = mockClientWarn.mock.calls[0]
      expect(name).toBe('image_failures_continuing')
      expect(context.failures_in_session).toBe(AGGREGATE_EVERY)
      expect(context.latest_src).toBe('freegletusd-' + AGGREGATE_EVERY)
      // Only the individually reported failures were probed.
      expect(fetch).toHaveBeenCalledTimes(MAX_INDIVIDUAL_REPORTS)
    })
  })

  describe('normaliseDeliveryMessage', () => {
    it('masks numbers so one reason is one issue', () => {
      expect(
        normaliseDeliveryMessage('Input image exceeds pixel limit 1234 x 5678')
      ).toBe('Input image exceeds pixel limit N x N')
    })

    it('masks URLs and image ids so one reason is one issue', () => {
      expect(
        normaliseDeliveryMessage(
          'Unable to fetch https://uploads.example/7fb318f3d31a06ba5b37255ef737c55e for 7fb318f3d31a06ba'
        )
      ).toBe('Unable to fetch URL for ID')
    })

    it('has a stable value for a missing message', () => {
      expect(normaliseDeliveryMessage(null)).toBe('no message')
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
