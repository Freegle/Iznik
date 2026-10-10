import { describe, it, expect, vi, beforeEach } from 'vitest'
import { notOutcomeAlreadyRecorded } from '~/api/outcomeConflict'

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

describe('MessageAPI.update - Sentry filter for outcomes', () => {
  let api

  beforeEach(async () => {
    vi.clearAllMocks()
    const { default: MessageAPI } = await import('~/api/MessageAPI.js')
    api = new MessageAPI({ public: { APIv2: 'https://api.test.com' } })
  })

  it('keeps "Outcome already recorded" out of Sentry for an outcome', async () => {
    const spy = vi.spyOn(api, '$postv2').mockResolvedValue({})
    const event = { action: 'Outcome', id: 1, outcome: 'Taken' }

    await api.update(event)

    expect(spy).toHaveBeenCalledWith(
      '/message',
      event,
      notOutcomeAlreadyRecorded
    )
  })

  it('logs every failure for other actions', async () => {
    const spy = vi.spyOn(api, '$postv2').mockResolvedValue({})
    const event = { action: 'Promise', id: 1 }

    await api.update(event)

    expect(spy).toHaveBeenCalledWith('/message', event, true)
  })
})
