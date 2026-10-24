import { describe, it, expect, vi, beforeEach } from 'vitest'

const { mockAuth, mockAction } = vi.hoisted(() => ({
  mockAuth: { user: null, saveAndGet: vi.fn() },
  mockAction: vi.fn(),
}))

vi.mock('~/stores/auth', () => ({ useAuthStore: () => mockAuth }))
vi.mock('~/composables/useClientLog', () => ({ action: mockAction }))

const { useAskName } = await import('~/composables/useAskName')

describe('useAskName (experiment join-name-optional)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAuth.saveAndGet.mockImplementation(async (change) => {
      if (change.settings) mockAuth.user.settings = change.settings
    })
    mockAuth.user = { settings: { mylocation: { id: 1 } } }
    useAskName().askNameOpen.value = false
  })

  it('marks a member to be asked, keeping their other settings', async () => {
    await useAskName().markAskNameLater()
    expect(mockAuth.saveAndGet).toHaveBeenCalledWith({
      settings: { mylocation: { id: 1 }, asknamelater: true },
    })
  })

  it('does not ask a member who was never marked', () => {
    const { askNameOpen, maybeAskName } = useAskName()
    maybeAskName('reply')
    expect(askNameOpen.value).toBe(false)
    expect(mockAction).not.toHaveBeenCalled()
  })

  it('asks a marked member after a reply or a post, once at a time', async () => {
    mockAuth.user.settings.asknamelater = true
    const { askNameOpen, maybeAskName } = useAskName()
    maybeAskName('post')
    maybeAskName('post')
    expect(askNameOpen.value).toBe(true)
    expect(mockAction).toHaveBeenCalledTimes(1)
    expect(mockAction).toHaveBeenCalledWith('ask_name_shown', { after: 'post' })
  })

  it('saves the name and stops asking', async () => {
    mockAuth.user.settings.asknamelater = true
    const { askNameOpen, maybeAskName, saveName } = useAskName()
    maybeAskName('reply')
    await saveName('Sam')
    expect(mockAuth.saveAndGet).toHaveBeenCalledWith({ displayname: 'Sam' })
    expect(mockAuth.user.settings.asknamelater).toBe(false)
    expect(askNameOpen.value).toBe(false)
    maybeAskName('reply')
    expect(askNameOpen.value).toBe(false)
  })

  it('asks again next time after Not now', () => {
    mockAuth.user.settings.asknamelater = true
    const { askNameOpen, maybeAskName, skipName } = useAskName()
    maybeAskName('reply')
    skipName()
    expect(askNameOpen.value).toBe(false)
    expect(mockAuth.saveAndGet).not.toHaveBeenCalled()
    maybeAskName('post')
    expect(askNameOpen.value).toBe(true)
  })
})
