import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

import MarketingOptOutPage from '~/pages/marketing-optout.vue'

// The opt-out link in a Newsletter ADMIN lands here. The page tells the member they will get no
// more newsletter or fundraising mail, so it must withdraw marketing consent as well as turning
// off the setting older senders check.

const mockRoute = reactive({
  query: { u: '42', k: 'key' },
  path: '/marketing-optout',
  fullPath: '/marketing-optout',
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => mockRoute,
  }
})

const authState = vi.hoisted(() => ({
  loginStateKnown: true,
  user: { id: 42 },
  saveAndGet: vi.fn(),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => authState,
}))

function mountPage() {
  return mount(MarketingOptOutPage, {
    global: {
      stubs: {
        'client-only': { template: '<div><slot /></div>' },
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        'b-spinner': { template: '<span />' },
      },
    },
  })
}

describe('marketing-optout page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authState.loginStateKnown = true
    authState.user = { id: 42 }
    authState.saveAndGet.mockResolvedValue({})
  })

  it('withdraws marketing consent for the member the link was for', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(authState.saveAndGet).toHaveBeenCalledWith({
      relevantallowed: false,
      marketingconsent: false,
    })
    expect(wrapper.text()).toContain("You've been opted out")
  })

  it('changes nothing when someone else is logged in', async () => {
    authState.user = { id: 7 }
    const wrapper = mountPage()
    await flushPromises()

    expect(authState.saveAndGet).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Opt-out failed')
  })
})
