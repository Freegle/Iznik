import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'

import { useNavbar } from '~/composables/useNavbar'

// On mobile the back button REPLACES the notification bell: NavbarMobile renders
// NotificationOptions only when showBackButton is false. So on every sub-page -
// an individual post, an event, Volunteering - an arriving notification has
// nowhere to show itself, and the member has no reason to go back and look.
// The badge on the back button is that reason.

const mockNotificationStore = {
  count: 0,
  fetchCount: vi.fn(),
  fetchList: vi.fn(),
}
const mockChatStore = { unreadCount: 0, byChatId: vi.fn(() => null) }

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({ online: true, get: () => null }),
}))
vi.mock('~/stores/newsfeed', () => ({
  useNewsfeedStore: () => ({ count: 0, fetchCount: vi.fn() }),
}))
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    count: 0,
    activePostsCounter: 0,
    fetchCount: vi.fn(),
    fetchActivePostCount: vi.fn(),
  }),
}))
vi.mock('~/stores/notification', () => ({
  useNotificationStore: () => mockNotificationStore,
}))
vi.mock('~/stores/chat', () => ({ useChatStore: () => mockChatStore }))
// Logged out, so useNavbar's onMounted getCounts() short-circuits and no store
// fetching happens - this spec is about the computed, not the fetching.
vi.mock('~/stores/auth', () => ({ useAuthStore: () => ({ user: null }) }))
vi.mock('~/stores/communityevent', () => ({
  useCommunityEventStore: () => ({ list: [], fetchList: vi.fn() }),
}))
vi.mock('~/stores/volunteering', () => ({
  useVolunteeringStore: () => ({ list: [], fetchList: vi.fn() }),
}))
vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({ isApp: false, setBadgeCount: vi.fn() }),
}))
vi.mock('~/composables/useMe', () => ({ fetchMe: vi.fn() }))
vi.mock('~/composables/useStaleBuild', () => ({
  classifyStaleBuild: () => ({ stale: false }),
}))

function countOn(path) {
  globalThis.useRoute = () => ({ path, params: {}, query: {}, fullPath: path })
  globalThis.useRouter = () => ({
    push: vi.fn(),
    currentRoute: { value: { path } },
  })

  const Harness = defineComponent({
    setup() {
      const { backButtonCount } = useNavbar()
      return { backButtonCount }
    },
    template: '<span>{{ backButtonCount }}</span>',
  })

  return mount(Harness).vm.backButtonCount
}

describe('backButtonCount surfaces unread notifications where the bell is hidden', () => {
  beforeEach(() => {
    mockNotificationStore.count = 0
    mockChatStore.unreadCount = 0
    mockChatStore.byChatId = vi.fn(() => null)
  })

  afterEach(() => {
    delete globalThis.useRoute
    delete globalThis.useRouter
    vi.clearAllMocks()
  })

  it('shows the unread notification count on a sub-page, where the bell is not rendered', () => {
    mockNotificationStore.count = 4
    expect(countOn('/mypost/123')).toBe(4)
  })

  it('shows it on the other sub-pages reached from the + menu', () => {
    mockNotificationStore.count = 2
    expect(countOn('/communityevents')).toBe(2)
    expect(countOn('/volunteerings')).toBe(2)
    expect(countOn('/chats')).toBe(2)
  })

  it('shows nothing when there are no unread notifications, so no empty badge appears', () => {
    mockNotificationStore.count = 0
    expect(countOn('/mypost/123')).toBe(0)
  })

  it('tolerates a count the store has not fetched yet', () => {
    mockNotificationStore.count = undefined
    expect(countOn('/mypost/123')).toBe(0)
  })

  it('caps at 99, matching the other navbar counts', () => {
    mockNotificationStore.count = 250
    expect(countOn('/mypost/123')).toBe(99)
  })

  it('leaves a specific chat showing the OTHER unread chats, not notifications', () => {
    // In a chat the whole navbar goes; the badge stands in for the chats you
    // can no longer see, and must not be hijacked by the notification count.
    mockNotificationStore.count = 7
    mockChatStore.unreadCount = 5
    mockChatStore.byChatId = vi.fn(() => ({ unseen: 2 }))
    expect(countOn('/chats/123')).toBe(3)
  })
})
