import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// The Block Store native plugin, as Capacitor would proxy it.
const mockSetSession = vi.fn()
const mockGetSession = vi.fn()
const mockClearSession = vi.fn()

// Which platform the code under test believes it is on. Mutable so each test can pick.
let platform = 'android'

vi.mock('@capacitor/core', () => ({
  Capacitor: {
    getPlatform: () => platform,
  },
  registerPlugin: () => ({
    setSession: mockSetSession,
    getSession: mockGetSession,
    clearSession: mockClearSession,
  }),
}))

// The composable keeps module-scope state (what Block Store already holds), so each test gets
// a fresh copy of the module.
async function load() {
  vi.resetModules()
  return await import('~/composables/useSessionRestore.js')
}

const PERSISTENT = { id: 42, series: 'abc', token: 'def' }
const ENVELOPE = JSON.stringify({ v: 1, persistent: PERSISTENT })

describe('useSessionRestore', () => {
  beforeEach(() => {
    platform = 'android'
    mockSetSession.mockReset().mockResolvedValue({ saved: true })
    mockGetSession.mockReset().mockResolvedValue({ value: null })
    mockClearSession.mockReset().mockResolvedValue({ cleared: true })
    vi.spyOn(console, 'log').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  describe('sessionRestoreSupported', () => {
    it('is true on Android', async () => {
      const { sessionRestoreSupported } = await load()
      expect(sessionRestoreSupported()).toBe(true)
    })

    it('is false on iOS, which restores via the keychain backup', async () => {
      platform = 'ios'
      const { sessionRestoreSupported } = await load()
      expect(sessionRestoreSupported()).toBe(false)
    })

    it('is false on the web', async () => {
      platform = 'web'
      const { sessionRestoreSupported } = await load()
      expect(sessionRestoreSupported()).toBe(false)
    })
  })

  describe('saveSessionForRestore', () => {
    it('stores the token in a versioned envelope', async () => {
      const { saveSessionForRestore } = await load()

      expect(await saveSessionForRestore(PERSISTENT)).toBe(true)
      expect(mockSetSession).toHaveBeenCalledWith({ value: ENVELOPE })
    })

    it('does not rewrite a token Block Store already holds', async () => {
      const { saveSessionForRestore } = await load()

      await saveSessionForRestore(PERSISTENT)
      expect(await saveSessionForRestore(PERSISTENT)).toBe(false)
      expect(mockSetSession).toHaveBeenCalledTimes(1)
    })

    it('writes again when the token changes', async () => {
      const { saveSessionForRestore } = await load()

      await saveSessionForRestore(PERSISTENT)
      expect(await saveSessionForRestore({ ...PERSISTENT, token: 'new' })).toBe(
        true
      )
      expect(mockSetSession).toHaveBeenCalledTimes(2)
    })

    it('does nothing without a token', async () => {
      const { saveSessionForRestore } = await load()

      expect(await saveSessionForRestore(null)).toBe(false)
      expect(mockSetSession).not.toHaveBeenCalled()
    })

    it('does nothing off Android', async () => {
      platform = 'web'
      const { saveSessionForRestore } = await load()

      expect(await saveSessionForRestore(PERSISTENT)).toBe(false)
      expect(mockSetSession).not.toHaveBeenCalled()
    })

    it('swallows a native failure', async () => {
      mockSetSession.mockRejectedValue(new Error('no Play services'))
      const { saveSessionForRestore } = await load()

      expect(await saveSessionForRestore(PERSISTENT)).toBe(false)
    })

    it('retries after a failure rather than believing it saved', async () => {
      mockSetSession.mockRejectedValueOnce(new Error('transient'))
      const { saveSessionForRestore } = await load()

      await saveSessionForRestore(PERSISTENT)
      expect(await saveSessionForRestore(PERSISTENT)).toBe(true)
      expect(mockSetSession).toHaveBeenCalledTimes(2)
    })
  })

  describe('restoreSessionFromDevice', () => {
    it('returns the token a previous device left', async () => {
      mockGetSession.mockResolvedValue({ value: ENVELOPE })
      const { restoreSessionFromDevice } = await load()

      expect(await restoreSessionFromDevice()).toEqual(PERSISTENT)
    })

    it('returns null when nothing is stored', async () => {
      const { restoreSessionFromDevice } = await load()

      expect(await restoreSessionFromDevice()).toBe(null)
    })

    it('ignores an envelope from a future schema', async () => {
      mockGetSession.mockResolvedValue({
        value: JSON.stringify({ v: 2, persistent: PERSISTENT }),
      })
      const { restoreSessionFromDevice } = await load()

      expect(await restoreSessionFromDevice()).toBe(null)
    })

    it('ignores an envelope with no token', async () => {
      mockGetSession.mockResolvedValue({ value: JSON.stringify({ v: 1 }) })
      const { restoreSessionFromDevice } = await load()

      expect(await restoreSessionFromDevice()).toBe(null)
    })

    it('survives a corrupt blob', async () => {
      mockGetSession.mockResolvedValue({ value: 'not json' })
      const { restoreSessionFromDevice } = await load()

      expect(await restoreSessionFromDevice()).toBe(null)
    })

    it('logs the reason when Block Store reports itself unavailable', async () => {
      mockGetSession.mockResolvedValue({ value: null, error: 'GMS missing' })
      const { restoreSessionFromDevice } = await load()

      expect(await restoreSessionFromDevice()).toBe(null)
      expect(console.log).toHaveBeenCalledWith(
        'Block Store unavailable',
        'GMS missing'
      )
    })

    it('does not call native off Android', async () => {
      platform = 'web'
      const { restoreSessionFromDevice } = await load()

      expect(await restoreSessionFromDevice()).toBe(null)
      expect(mockGetSession).not.toHaveBeenCalled()
    })

    it('does not write back what it just read', async () => {
      mockGetSession.mockResolvedValue({ value: ENVELOPE })
      const { restoreSessionFromDevice, saveSessionForRestore } = await load()

      const restored = await restoreSessionFromDevice()

      expect(await saveSessionForRestore(restored)).toBe(false)
      expect(mockSetSession).not.toHaveBeenCalled()
    })
  })

  describe('clearRestoredSession', () => {
    it('deletes the stored session', async () => {
      const { clearRestoredSession } = await load()

      await clearRestoredSession()
      expect(mockClearSession).toHaveBeenCalled()
    })

    it('lets the same token be stored again afterwards', async () => {
      const { saveSessionForRestore, clearRestoredSession } = await load()

      await saveSessionForRestore(PERSISTENT)
      await clearRestoredSession()

      expect(await saveSessionForRestore(PERSISTENT)).toBe(true)
      expect(mockSetSession).toHaveBeenCalledTimes(2)
    })

    it('swallows a native failure, so logout still completes', async () => {
      mockClearSession.mockRejectedValue(new Error('no Play services'))
      const { clearRestoredSession } = await load()

      await expect(clearRestoredSession()).resolves.toBeUndefined()
    })

    it('does not call native off Android', async () => {
      platform = 'web'
      const { clearRestoredSession } = await load()

      await clearRestoredSession()
      expect(mockClearSession).not.toHaveBeenCalled()
    })
  })
})
