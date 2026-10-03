import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import MessageHistory from '~/components/MessageHistory.vue'

const { mockMessage, mockUser } = vi.hoisted(() => {
  return {
    mockMessage: {
      id: 1,
      subject: 'OFFER: Sofa',
      date: '2024-01-15T10:00:00Z',
      source: 'Platform',
      fromip: '192.168.1.1',
      fromcountry: 'United Kingdom',
      arrival: '2024-01-15T10:00:00Z',
      approvedby: 200,
      postings: [
        {
          date: '2024-01-14T10:00:00Z',
        },
      ],
    },
    mockUser: {
      id: 200,
      displayname: 'Mod User',
    },
  }
})

const mockMessageStore = {
  byId: vi.fn().mockReturnValue(mockMessage),
}

const mockAuthStore = {
  user: { id: 1, systemrole: 'User' },
}

const mockUserStore = {
  fetch: vi.fn().mockResolvedValue(mockUser),
  byId: vi.fn().mockReturnValue(mockUser),
}

const mockMiscStore = {
  breakpoint: 'lg',
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

vi.mock('~/composables/useTimeFormat', () => ({
  timeago: vi.fn().mockReturnValue('2 days ago'),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    mod: ref(false),
  }),
}))

vi.mock('pinia', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    storeToRefs: (store) => ({ breakpoint: ref(store.breakpoint) }),
  }
})

describe('MessageHistory', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMessageStore.byId.mockReturnValue(mockMessage)
    mockAuthStore.user = { id: 1, systemrole: 'User' }
    mockMiscStore.breakpoint = 'lg'
  })

  function createWrapper(props = {}) {
    return mount(MessageHistory, {
      props: {
        id: 1,
        ...props,
      },
      global: {
        mocks: {
          datetime: vi.fn().mockReturnValue('15 Jan 2024'),
        },
        stubs: {
          'client-only': {
            template: '<div><slot /></div>',
          },
          'b-button': {
            template: '<button class="b-button" :to="to"><slot /></button>',
            props: ['variant', 'to', 'size'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders message history container', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.text--small').exists()).toBe(true)
    })

    it('shows time ago', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('2 days ago')
    })
  })

  describe('message ID display', () => {
    it('shows message ID when not summary', () => {
      const wrapper = createWrapper({ summary: false })
      expect(wrapper.text()).toContain('#1')
    })

    it('links to the single message page', () => {
      const wrapper = createWrapper({ summary: false })
      const btn = wrapper.find('.b-button')
      expect(btn.attributes('to')).toBe('/message/1')
    })

    it('hides message ID in summary mode on small screens', () => {
      mockMiscStore.breakpoint = 'xs'
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.text()).not.toContain('#1')
    })
  })

  describe('moderator info', () => {
    it('shows source info when modinfo is true', () => {
      const wrapper = createWrapper({ modinfo: true })
      expect(wrapper.text()).toContain('Freegle website')
    })

    it('shows IP info when modinfo is true', () => {
      const wrapper = createWrapper({ modinfo: true })
      expect(wrapper.text()).toContain('192.168.1.1')
    })

    it('shows country when modinfo is true', () => {
      const wrapper = createWrapper({ modinfo: true })
      expect(wrapper.text()).toContain('United Kingdom')
    })

    it('hides mod info when modinfo is false', () => {
      const wrapper = createWrapper({ modinfo: false })
      expect(wrapper.text()).not.toContain('Freegle website')
    })

    it('shows TrashNothing source', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        source: 'Email',
        fromaddr: 'test@trashnothing.com',
      })
      const wrapper = createWrapper({ modinfo: true })
      expect(wrapper.text()).toContain('TrashNothing')
    })

    it('shows Mobile App source', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        sourceheader: 'Freegle App',
      })
      const wrapper = createWrapper({ modinfo: true })
      expect(wrapper.text()).toContain('Mobile App')
    })
  })

  describe('foreign IP warning', () => {
    it('highlights non-UK country', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        fromcountry: 'Germany',
      })
      const wrapper = createWrapper({ modinfo: true })
      expect(wrapper.find('.text-danger').exists()).toBe(true)
    })

    it('does not highlight UK country', () => {
      const wrapper = createWrapper({ modinfo: true })
      const countrySpan = wrapper
        .findAll('span')
        .find((s) => s.text().includes('United Kingdom'))
      expect(countrySpan?.classes()).not.toContain('text-danger')
    })
  })

  describe('IP hash handling', () => {
    it('shows hash label for long IP', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        fromip: 'abcdefghijklmnopqrstuvwxyz',
      })
      const wrapper = createWrapper({ modinfo: true })
      expect(wrapper.text()).toContain('hash')
    })

    it('shows address label for normal IP', () => {
      const wrapper = createWrapper({ modinfo: true })
      expect(wrapper.text()).toContain('address')
    })
  })

  describe('approved by', () => {
    it('fetches approving mod for moderators', () => {
      mockAuthStore.user = { id: 1, systemrole: 'Moderator' }
      createWrapper()
      expect(mockUserStore.fetch).toHaveBeenCalledWith(200)
    })

    it('does not fetch approving mod for regular users', () => {
      mockAuthStore.user = { id: 1, systemrole: 'User' }
      mockUserStore.fetch.mockClear()
      createWrapper()
      expect(mockUserStore.fetch).not.toHaveBeenCalled()
    })
  })

  describe('postings history', () => {
    it('shows first posted info when dates differ', () => {
      const wrapper = createWrapper({ modinfo: true })
      expect(wrapper.text()).toContain('First posted')
    })

    it('hides first posted when same day', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        postings: [
          {
            date: mockMessage.date,
          },
        ],
      })
      const wrapper = createWrapper({ modinfo: true })
      expect(wrapper.text()).not.toContain('First posted')
    })
  })

  describe('unavailable IP', () => {
    it('shows unavailable when no IP', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        fromip: null,
      })
      const wrapper = createWrapper({ modinfo: true })
      expect(wrapper.text()).toContain('IP unavailable')
    })
  })

  describe('responsive behavior', () => {
    it('shows details on larger screens', () => {
      mockMiscStore.breakpoint = 'lg'
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('.b-button').exists()).toBe(true)
    })

    it('hides details on xs screens in summary mode', () => {
      mockMiscStore.breakpoint = 'xs'
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('.b-button').exists()).toBe(false)
    })
  })
})
