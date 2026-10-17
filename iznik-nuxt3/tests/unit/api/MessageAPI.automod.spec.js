import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    auth: { jwt: 'test-jwt', persistent: 'test-persistent' },
    user: { id: 123 },
  }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    modtools: true,
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

// The Check queue and automated review calls ModTools makes. Each goes to the route the Go
// API registers, with the body or query it reads.
describe('MessageAPI - Check queue and automated review', () => {
  let api

  beforeEach(async () => {
    vi.clearAllMocks()
    const { default: MessageAPI } = await import('~/api/MessageAPI.js')
    api = new MessageAPI({ public: { APIv2: 'https://api.test.com' } })
  })

  it('posts the posts to mark checked, or to pull back', async () => {
    const spy = vi.spyOn(api, '$postv2').mockResolvedValue({})
    const body = { groupid: 0, ids: [4, 5], reject: true }

    await api.markChecked(body)

    expect(spy).toHaveBeenCalledWith('/modtools/messages/markchecked', body)
  })

  it('posts a mark against one step of a decision', async () => {
    const spy = vi.spyOn(api, '$postv2').mockResolvedValue({})
    const body = { msgid: 5, groupid: 3, node: 'APPROVE' }

    await api.automodFeedback(body)

    expect(spy).toHaveBeenCalledWith('/modtools/automod/feedback', body)
  })

  it('takes a mark back with a DELETE carrying the same body', async () => {
    const spy = vi.spyOn(api, '$delv2').mockResolvedValue({})
    const body = { msgid: 5, groupid: 3, node: 'APPROVE' }

    await api.automodFeedbackUndo(body)

    expect(spy).toHaveBeenCalledWith(
      '/modtools/automod/feedback',
      body,
      expect.any(Function)
    )
  })

  it('keeps a 404 on taking a mark back out of Sentry', async () => {
    // 404 is how the server says there was no mark to take back, which the store counts as
    // done. Any other failure is still worth reporting.
    const spy = vi.spyOn(api, '$delv2').mockResolvedValue({})

    await api.automodFeedbackUndo({ msgid: 5, groupid: 3, node: 'APPROVE' })

    const logError = spy.mock.calls[0][2]
    expect(logError({ error: 404, message: 'No such mark' })).toBe(false)
    expect(logError({ error: 500, message: 'Boom' })).toBe(true)
    expect(logError(undefined)).toBe(true)
  })

  it('asks for the agreement report with its window and community', async () => {
    const spy = vi.spyOn(api, '$getv2').mockResolvedValue({})

    await api.automodAgreement({ days: 30, groupid: 7 })

    expect(spy).toHaveBeenCalledWith('/modtools/automod/agreement', {
      days: 30,
      groupid: 7,
    })
  })

  it('sends a DELETE body as JSON, so the route can read the node', async () => {
    // $delv2 puts its data in config.params, which $requestv2 JSON-encodes into the body for
    // every method but GET and POST: the feedback route reads a body, not a query string.
    const spy = vi.spyOn(api, '$requestv2').mockResolvedValue({})
    const body = { msgid: 5, groupid: 3, node: 'APPROVE' }

    await api.automodFeedbackUndo(body)

    expect(spy).toHaveBeenCalledWith(
      'DELETE',
      '/modtools/automod/feedback',
      {
        headers: { 'Content-Type': 'application/json' },
        params: body,
      },
      expect.any(Function)
    )
  })
})
