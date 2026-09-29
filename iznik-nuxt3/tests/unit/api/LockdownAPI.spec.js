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

const mockCaptureMessage = vi.fn()
vi.mock('@sentry/vue', () => ({
  captureMessage: mockCaptureMessage,
}))

const mockFetch = vi.fn()
vi.mock('~/composables/useFetchRetry', () => ({
  fetchRetry: () => mockFetch,
}))

let LockdownAPI

describe('LockdownAPI', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    vi.resetModules()
    const mod = await import('~/api/LockdownAPI.js')
    LockdownAPI = mod.default
  })

  function createApi() {
    return new LockdownAPI({
      public: { APIv2: 'https://api.test.com' },
    })
  }

  it('fetches the public notice from GET /lockdown', async () => {
    mockFetch.mockResolvedValue([200, { notice: null }])

    const ret = await createApi().fetch()

    expect(mockFetch).toHaveBeenCalledTimes(1)
    expect(mockFetch.mock.calls[0][0]).toContain(
      'https://api.test.com/lockdown'
    )
    expect(mockFetch.mock.calls[0][1].method).toBe('GET')
    expect(ret.notice).toBeNull()
  })

  it('fetches the mod state from GET /modtools/lockdown', async () => {
    mockFetch.mockResolvedValue([
      200,
      {
        active: true,
        incidentid: 5,
        surfaces: { mods: true },
        reason: 'spam wave',
        // GET /modtools/lockdown returns the notice text itself, unlike the
        // public GET /lockdown endpoint, which wraps it as {text}.
        notice: 'Messages may be delayed.',
        startedat: '2026-09-27 10:00:00',
        startedby: 1,
        startedbyname: 'Support',
      },
    ])

    const ret = await createApi().fetchMod()

    expect(mockFetch.mock.calls[0][0]).toContain(
      'https://api.test.com/modtools/lockdown'
    )
    expect(mockFetch.mock.calls[0][0]).not.toContain('/modtools/lockdown/stats')
    expect(ret.active).toBe(true)
    expect(ret.incidentid).toBe(5)
  })

  it('fetches stats from GET /modtools/lockdown/stats', async () => {
    mockFetch.mockResolvedValue([200, { counts: {} }])

    await createApi().fetchStats()

    expect(mockFetch.mock.calls[0][0]).toContain(
      'https://api.test.com/modtools/lockdown/stats'
    )
    expect(mockFetch.mock.calls[0][1].method).toBe('GET')
  })

  it('fetches history from GET /modtools/lockdown/history', async () => {
    mockFetch.mockResolvedValue([200, { history: [] }])

    await createApi().fetchHistory()

    expect(mockFetch.mock.calls[0][0]).toContain(
      'https://api.test.com/modtools/lockdown/history'
    )
    expect(mockFetch.mock.calls[0][1].method).toBe('GET')
  })

  it('fetches held items from GET /modtools/lockdown/held with the search', async () => {
    mockFetch.mockResolvedValue([200, { items: [], next: null }])

    await createApi().fetchHeld({ kind: 'post', q: 'voucher', before: 10 })

    const url = mockFetch.mock.calls[0][0]
    expect(url).toContain('https://api.test.com/modtools/lockdown/held')
    expect(url).toContain('kind=post')
    expect(url).toContain('q=voucher')
    expect(url).toContain('before=10')
    expect(mockFetch.mock.calls[0][1].method).toBe('GET')
  })

  it('patches PATCH /lockdown with the action body', async () => {
    mockFetch.mockResolvedValue([200, { active: true }])

    await createApi().patch({ action: 'press', reason: 'spam wave' })

    expect(mockFetch.mock.calls[0][0]).toContain(
      'https://api.test.com/lockdown'
    )
    expect(mockFetch.mock.calls[0][1].method).toBe('PATCH')
  })

  it('does not report a lockdown 409 to Sentry', async () => {
    mockFetch.mockResolvedValue([
      409,
      {
        ret: 409,
        status:
          'Changes are paused for a few hours while we deal with a security incident.',
        lockdown: true,
      },
    ])

    await expect(
      createApi().patch({ action: 'press', reason: 'spam wave' })
    ).rejects.toThrow()

    expect(mockCaptureMessage).not.toHaveBeenCalled()
  })

  it('still reports a non-lockdown failure to Sentry', async () => {
    mockFetch.mockResolvedValue([500, { status: 'Server error' }])

    await expect(
      createApi().patch({ action: 'press', reason: 'spam wave' })
    ).rejects.toThrow()

    expect(mockCaptureMessage).toHaveBeenCalled()
  })
})
