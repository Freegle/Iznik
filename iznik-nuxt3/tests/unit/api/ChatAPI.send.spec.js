import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    auth: { jwt: 'test-jwt', persistent: 'test-persistent' },
    user: { id: 123 },
  }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    modtools: false,
    api: vi.fn(),
    waitForOnline: vi.fn().mockResolvedValue(),
  }),
}))

vi.mock('~/stores/loggingContext', () => ({
  useLoggingContextStore: () => ({
    getHeaders: () => ({}),
  }),
}))

vi.mock('~/composables/useTrace', () => ({
  getTraceHeaders: () => ({}),
}))

vi.mock('@sentry/vue', () => ({
  captureMessage: vi.fn(),
}))

vi.mock('~/composables/useFetchRetry', () => ({
  fetchRetry: () => vi.fn(),
}))

describe('ChatAPI.send - Sentry filter for a repeated image', () => {
  let api
  const used = { error: 409, message: 'Image already used' }

  beforeEach(async () => {
    vi.clearAllMocks()
    const { default: ChatAPI } = await import('~/api/ChatAPI.js')
    api = new ChatAPI({ public: { APIv2: 'https://api.test.com' } })
  })

  function filterFor(data) {
    const spy = vi.spyOn(api, '$postv2').mockResolvedValue({})
    api.send(data)
    return spy.mock.calls[0][2]
  }

  it('keeps "Image already used" out of Sentry for an image send', () => {
    const logError = filterFor({ roomid: 7, imageid: 42 })
    expect(logError(used)).toBe(false)
  })

  it('still logs any other failure of an image send', () => {
    const logError = filterFor({ roomid: 7, imageid: 42 })
    expect(logError({ error: 400, message: 'Invalid imageid' })).toBe(true)
    expect(logError(null)).toBe(true)
  })

  it('still logs "Image already used" on a send without an image', () => {
    const logError = filterFor({ roomid: 7, message: 'hello' })
    expect(logError(used)).toBe(true)
  })
})
