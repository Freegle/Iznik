import { describe, it, expect, vi, beforeEach } from 'vitest'

import { useDonationAskModal } from '~/composables/useDonationAskModal'

// ============================================================
// Store / API / bus mocks — must be declared before any vi.mock() calls
// ============================================================
let mockUser
let mockIsApp
const mockMiscGet = vi.fn()
const mockMiscSet = vi.fn()
const mockBanditChoose = vi.fn()
const mockBanditShown = vi.fn()

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    get user() {
      return mockUser
    },
  }),
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    get: mockMiscGet,
    set: mockMiscSet,
  }),
}))

vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({
    get isApp() {
      return mockIsApp
    },
  }),
}))

vi.mock('~/api', () => ({
  default: () => ({
    bandit: {
      choose: mockBanditChoose,
      shown: mockBanditShown,
    },
  }),
}))

let outcomeHandler
const mockBusOn = vi.fn((event, handler) => {
  if (event === 'outcome') outcomeHandler = handler
})
vi.stubGlobal('useNuxtApp', () => ({ $bus: { $on: mockBusOn } }))

describe('useDonationAskModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUser = { donorrecurring: false }
    mockIsApp = false
    mockMiscGet.mockReturnValue(null)
    mockBanditChoose.mockResolvedValue({ variant: 'stripe' })
    mockBanditShown.mockResolvedValue(undefined)
    window.localStorage.removeItem('rateappnotagain')
    outcomeHandler = undefined
  })

  it('registers an outcome listener on the bus', () => {
    useDonationAskModal()
    expect(mockBusOn).toHaveBeenCalledWith('outcome', expect.any(Function))
  })

  it('ignores outcomes that are neither Taken nor Received', async () => {
    useDonationAskModal()
    await outcomeHandler({ groupid: 5, outcome: 'Ongoing' })
    expect(mockMiscSet).not.toHaveBeenCalled()
    expect(mockBanditShown).not.toHaveBeenCalled()
  })

  it.each(['Taken', 'Received'])(
    'shows the ask on a %s outcome when nothing blocks it',
    async (outcome) => {
      const c = useDonationAskModal()
      await outcomeHandler({ groupid: 9, outcome })
      await vi.waitFor(() => expect(mockBanditShown).toHaveBeenCalled())
      expect(c.groupId.value).toBe(9)
      expect(c.showDonationAskModal.value).toBe(true)
    }
  )

  it('does not ask again if the user has recurring donations set up', async () => {
    mockUser = { donorrecurring: true }
    useDonationAskModal()
    await outcomeHandler({ groupid: 1, outcome: 'Taken' })
    expect(mockMiscSet).not.toHaveBeenCalled()
    expect(mockBanditShown).not.toHaveBeenCalled()
  })

  it('does not ask again within a week of the last ask', async () => {
    mockMiscGet.mockReturnValue(new Date().getTime() - 1000)
    useDonationAskModal()
    await outcomeHandler({ groupid: 1, outcome: 'Taken' })
    expect(mockBanditShown).not.toHaveBeenCalled()
  })

  it('asks again once a week has passed since the last ask', async () => {
    mockMiscGet.mockReturnValue(new Date().getTime() - 60 * 60 * 1000 * 24 * 8)
    useDonationAskModal()
    await outcomeHandler({ groupid: 1, outcome: 'Taken' })
    await vi.waitFor(() => expect(mockBanditShown).toHaveBeenCalled())
  })

  it('show() uses an explicitly requested variant without consulting the bandit', async () => {
    const c = useDonationAskModal()
    await c.show('rateapp')
    expect(mockBanditChoose).not.toHaveBeenCalled()
    expect(c.variant.value).toBe('rateapp')
    expect(mockBanditShown).toHaveBeenCalledWith({
      uid: 'donation',
      variant: 'rateapp',
    })
    expect(c.showDonationAskModal.value).toBe(true)
  })

  it('show() keeps a pre-set variant and skips the bandit call entirely', async () => {
    const c = useDonationAskModal('stripe')
    await c.show()
    expect(mockBanditChoose).not.toHaveBeenCalled()
    expect(c.variant.value).toBe('stripe')
  })

  it('show() asks the bandit to choose when there is no variant yet', async () => {
    mockBanditChoose.mockResolvedValue({ variant: 'paypal' })
    const c = useDonationAskModal()
    await c.show()
    expect(mockBanditChoose).toHaveBeenCalledWith({ uid: 'donation' })
    expect(c.variant.value).toBe('paypal')
  })

  it('show() leaves variant unset when the bandit returns nothing', async () => {
    mockBanditChoose.mockResolvedValue(null)
    const c = useDonationAskModal()
    await c.show()
    expect(c.variant.value).toBeNull()
    expect(mockBanditShown).toHaveBeenCalledWith({
      uid: 'donation',
      variant: null,
    })
  })

  it('show() swallows a bandit-choose failure and still shows the modal', async () => {
    mockBanditChoose.mockRejectedValue(new Error('network down'))
    const c = useDonationAskModal()
    await c.show()
    expect(c.showDonationAskModal.value).toBe(true)
    expect(mockBanditShown).toHaveBeenCalled()
  })

  it('show() on a mobile app rolls the rate-app dice and can win it', async () => {
    mockIsApp = true
    vi.spyOn(Math, 'random').mockReturnValueOnce(0.99)
    const c = useDonationAskModal()
    await c.show()
    expect(mockBanditChoose).not.toHaveBeenCalled()
    expect(c.variant.value).toBe('rateapp')
  })

  it('show() on a mobile app can lose the rate-app dice and fall back to the bandit', async () => {
    mockIsApp = true
    vi.spyOn(Math, 'random').mockReturnValueOnce(0.1)
    mockBanditChoose.mockResolvedValue({ variant: 'stripe' })
    const c = useDonationAskModal()
    await c.show()
    expect(mockBanditChoose).toHaveBeenCalledWith({ uid: 'donation' })
    expect(c.variant.value).toBe('stripe')
  })

  it('show() on a mobile app that already declined rate-app skips straight to the bandit', async () => {
    mockIsApp = true
    window.localStorage.setItem('rateappnotagain', '1')
    mockBanditChoose.mockResolvedValue({ variant: 'stripe' })
    const c = useDonationAskModal()
    await c.show()
    expect(mockBanditChoose).toHaveBeenCalledWith({ uid: 'donation' })
    expect(c.variant.value).toBe('stripe')
  })
})
