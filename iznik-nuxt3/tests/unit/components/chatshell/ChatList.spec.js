import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ChatList from '~/components/chatshell/ChatList.vue'

// byUserList is keyed by user id in the real store. A flat array here would hide the
// bug that took the whole chat list down for every signed-in member.
let byUserList = {}
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    get byUserList() {
      return byUserList
    },
    byId: () => null,
  }),
}))
vi.mock('~/stores/assistant', () => ({
  useAssistantStore: () => ({ lastFreegleLine: null }),
}))
vi.mock('~/stores/newsfeed', () => ({
  useNewsfeedStore: () => ({ count: 0, fetchCount: async () => {} }),
}))
vi.mock('~/composables/useUiMode', () => ({
  useUiMode: () => ({
    isChat: { value: true },
    mode: { value: 'chat' },
    setMode: vi.fn(),
  }),
}))
vi.mock('~/composables/useCompose', () => ({ loadOwnActivePosts: vi.fn() }))

const stubs = {
  ShellHeader: true,
  ChatListEntry: true,
  ProfileImage: true,
  'b-dropdown': true,
  'b-dropdown-item': true,
}

describe('ChatList', () => {
  beforeEach(() => {
    globalThis.__mockAuthStore = { user: { id: 7, displayname: 'Test' } }
    globalThis.__mockChatStore = {
      list: {
        11: {
          id: 11,
          name: 'Ali',
          unseen: 1,
          lastdate: '2026-09-01T10:00:00Z',
          status: 'Active',
        },
        12: {
          id: 12,
          name: 'Jane',
          unseen: 0,
          lastdate: '2026-09-02T10:00:00Z',
          status: 'Active',
        },
      },
      unreadCount: 1,
      listChats: async () => {},
      markAllRead: async () => {},
    }
    byUserList = {
      7: [
        { id: 1, replycount: 2, unseenreplies: 1 },
        { id: 2, replycount: 0 },
        { id: 3, replycount: 0, outcomes: [{ outcome: 'Taken' }] },
      ],
    }
  })

  it('summarises the open posts from the posts keyed by my user id', async () => {
    const w = mount(ChatList, { global: { stubs } })
    await flushPromises()
    expect(w.text()).toContain('1 of your 2 posts has replies')
    expect(w.findAll('.list-row-wrap')).toHaveLength(2)
  })

  it('copes with no posts loaded yet: no Your posts row, no crash', async () => {
    byUserList = {}
    const w = mount(ChatList, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-testid="row-yourposts"]').exists()).toBe(false)
    expect(w.find('[data-testid="row-freegle"]').exists()).toBe(true)
  })

  it('the Unread filter keeps only chats with something unseen', async () => {
    const w = mount(ChatList, { global: { stubs } })
    await flushPromises()
    await w.find('[data-testid="filter-unread"]').trigger('click')
    expect(w.findAll('.list-row-wrap')).toHaveLength(1)
  })
})
