/**
 * bootSession() - the shared layout boot gate (layouts/default.vue and
 * layouts/login.vue).
 *
 * Contract:
 * - With stored credentials and NO in-memory user: awaits fetchMe(true) so the
 *   session resolves before the layout renders (cold start).
 * - With stored credentials and an in-memory user (layout swap after cold
 *   start): does NOT block; fires a background fetchMe(false) refresh only if
 *   the session data is stale (older than BOOT_SESSION_FRESH_MS).
 * - With no credentials and login state unknown: awaits fetchMe(true), which
 *   short-circuits in fetchUser without a network call but marks
 *   loginStateKnown.
 * - Errors from fetchMe are swallowed (boot must not throw).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

import {
  bootSession,
  bootSessionDeferred,
  BOOT_SESSION_FRESH_MS,
  BOOT_SESSION_TIMEOUT_MS,
} from '~/composables/useBootSession'
import { fetchMe } from '~/composables/useMe'

vi.mock('~/composables/useMe', () => ({
  fetchMe: vi.fn(),
}))

function makeAuthStore(overrides = {}) {
  return {
    user: null,
    auth: { jwt: null, persistent: null },
    loginStateKnown: false,
    userFetchedAt: 0,
    ...overrides,
  }
}

describe('bootSession', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    globalThis.__mockAuthStore = makeAuthStore()
  })

  afterEach(() => {
    delete globalThis.__mockAuthStore
    vi.restoreAllMocks()
  })

  it('awaits a server fetch when credentials exist but no user yet', async () => {
    globalThis.__mockAuthStore = makeAuthStore({
      auth: { jwt: 'token', persistent: null },
    })
    fetchMe.mockImplementation(() => {
      globalThis.__mockAuthStore.user = { id: 123 }
      globalThis.__mockAuthStore.loginStateKnown = true
      return Promise.resolve()
    })

    const user = await bootSession()

    expect(fetchMe).toHaveBeenCalledTimes(1)
    expect(fetchMe).toHaveBeenCalledWith(true)
    expect(user).toEqual({ id: 123 })
  })

  it('does not block or refetch when the user is already known and fresh', async () => {
    globalThis.__mockAuthStore = makeAuthStore({
      user: { id: 123 },
      auth: { jwt: 'token', persistent: null },
      loginStateKnown: true,
      userFetchedAt: Date.now(),
    })

    const user = await bootSession()

    expect(fetchMe).not.toHaveBeenCalled()
    expect(user).toEqual({ id: 123 })
  })

  it('fires a non-blocking background refresh when the user is known but stale', async () => {
    globalThis.__mockAuthStore = makeAuthStore({
      user: { id: 123 },
      auth: { jwt: 'token', persistent: null },
      loginStateKnown: true,
      userFetchedAt: Date.now() - BOOT_SESSION_FRESH_MS - 1000,
    })
    // A hanging refresh must not block bootSession.
    fetchMe.mockReturnValue(new Promise(() => {}))

    const user = await bootSession()

    expect(fetchMe).toHaveBeenCalledTimes(1)
    expect(fetchMe).toHaveBeenCalledWith(false)
    expect(user).toEqual({ id: 123 })
  })

  it('marks login state known without credentials', async () => {
    fetchMe.mockImplementation(() => {
      globalThis.__mockAuthStore.loginStateKnown = true
    })

    const user = await bootSession()

    expect(fetchMe).toHaveBeenCalledTimes(1)
    expect(fetchMe).toHaveBeenCalledWith(true)
    expect(user).toBeNull()
  })

  it('does nothing when logged out and login state already known', async () => {
    globalThis.__mockAuthStore = makeAuthStore({ loginStateKnown: true })

    const user = await bootSession()

    expect(fetchMe).not.toHaveBeenCalled()
    expect(user).toBeNull()
  })

  it('swallows fetch errors and still tries to resolve login state', async () => {
    globalThis.__mockAuthStore = makeAuthStore({
      auth: { jwt: 'token', persistent: null },
    })
    fetchMe
      .mockRejectedValueOnce(new Error('server down'))
      .mockImplementationOnce(() => {
        globalThis.__mockAuthStore.loginStateKnown = true
      })

    const user = await bootSession()

    // First call failed; loginStateKnown still false so it retries once.
    expect(fetchMe).toHaveBeenCalledTimes(2)
    expect(user).toBeNull()
  })
})

describe('bootSessionDeferred', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    globalThis.__mockAuthStore = makeAuthStore()
  })

  afterEach(() => {
    vi.useRealTimers()
    delete globalThis.__mockAuthStore
    vi.restoreAllMocks()
  })

  it('resolves with the user and no timeout when the fetch completes', async () => {
    globalThis.__mockAuthStore = makeAuthStore({
      auth: { jwt: 'token', persistent: null },
    })
    fetchMe.mockImplementation(() => {
      globalThis.__mockAuthStore.user = { id: 7 }
      globalThis.__mockAuthStore.loginStateKnown = true
      return Promise.resolve()
    })

    const result = await bootSessionDeferred()

    expect(result.user).toEqual({ id: 7 })
    expect(result.timedOut).toBe(false)
  })

  it('times out when the session fetch hangs, forcing loginStateKnown so the UI can proceed', async () => {
    globalThis.__mockAuthStore = makeAuthStore({
      auth: { jwt: 'token', persistent: null },
    })
    // Session API hangs indefinitely (the Mar 2026 marketing-optout failure
    // mode) - boot must not strand the user on a skeleton.
    fetchMe.mockReturnValue(new Promise(() => {}))

    const resultPromise = bootSessionDeferred()
    await vi.advanceTimersByTimeAsync(BOOT_SESSION_TIMEOUT_MS + 100)
    const result = await resultPromise

    expect(result.timedOut).toBe(true)
    expect(result.user).toBeNull()
    // Forced so watchers gating on loginStateKnown (login modal, OneTap)
    // proceed as logged out; a late success reconciles via the same watchers.
    expect(globalThis.__mockAuthStore.loginStateKnown).toBe(true)
  })

  it('does not wait for the timeout when there are no credentials', async () => {
    fetchMe.mockImplementation(() => {
      globalThis.__mockAuthStore.loginStateKnown = true
      return Promise.resolve()
    })

    const resultPromise = bootSessionDeferred()
    // No timer advance - must resolve on the fetch path alone.
    const result = await resultPromise

    expect(result.timedOut).toBe(false)
    expect(result.user).toBeNull()
  })
})
