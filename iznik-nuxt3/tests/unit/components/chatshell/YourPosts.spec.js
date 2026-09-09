import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import YourPosts from '~/components/chatshell/YourPosts.vue'

// The store keeps lean summaries per user id and full records by post id; the screen
// must read both, or it shows blank titles or, worse, nothing at all.
const full = {
  5: {
    id: 5,
    type: 'Offer',
    subject: 'OFFER: Grey sofa (EH3)',
    arrival: '2026-09-01T10:00:00Z',
    availablenow: 1,
    replies: [],
    promises: [],
    outcomes: [],
  },
}
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    byUserList: { 7: [{ id: 5, replycount: 0 }] },
    byId: (id) => full[id] || null,
    fetch: async (id) => full[id] || null,
  }),
}))
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ byId: () => null, fetch: async () => {} }),
}))
vi.mock('~/stores/tryst', () => ({
  useTrystStore: () => ({ list: {}, fetch: async () => {} }),
}))
vi.mock('~/stores/compose', () => ({ useComposeStore: () => ({}) }))
vi.mock('~/stores/location', () => ({ useLocationStore: () => ({}) }))
vi.mock('~/composables/useCompose', () => ({ loadOwnActivePosts: vi.fn() }))

const stubs = {
  ShellHeader: true,
  ShellComposer: true,
  ChooserSheet: true,
  PersonCard: true,
  ProxyImage: true,
  OutcomeModal: true,
  MessageEditModal: true,
  PromiseModal: true,
  'b-dropdown': true,
  'b-dropdown-item': true,
}

describe('YourPosts', () => {
  beforeEach(() => {
    globalThis.__mockAuthStore = {
      user: { id: 7, displayname: 'Test', settings: {} },
      saveAndGet: async () => {},
    }
    globalThis.__mockChatStore = { list: {}, listChats: async () => {} }
  })

  it('shows my open post with its title from the full record', async () => {
    const w = mount(YourPosts, { global: { stubs } })
    await flushPromises()
    expect(w.text()).toContain('Grey sofa')
    expect(w.text()).not.toContain('Nothing open just now')
  })
})
