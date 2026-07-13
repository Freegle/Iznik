import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import MessageHistory from '~/components/MessageHistory.vue'

const { mockMessage, mockGroup, mockUser, mockMod } = vi.hoisted(() => {
  return {
    mockMod: ref(false),
    mockMessage: {
      id: 1,
      subject: 'OFFER: Sofa',
      date: '2024-01-15T10:00:00Z',
      source: 'Platform',
      fromip: '192.168.1.1',
      fromcountry: 'United Kingdom',
      groups: [
        {
          groupid: 100,
          arrival: '2024-01-15T10:00:00Z',
          approvedby: 200,
        },
      ],
      postings: [
        {
          namedisplay: 'Freegle London',
          date: '2024-01-14T10:00:00Z',
        },
      ],
    },
    mockGroup: {
      id: 100,
      namedisplay: 'Freegle London',
      nameshort: 'London',
      exploreLink: 'london',
    },
    mockUser: {
      id: 200,
      displayname: 'Mod User',
    },
  }
})

const mockGroupStore = {
  get: vi.fn().mockReturnValue(mockGroup),
}

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

vi.mock('~/stores/group', () => ({
  useGroupStore: () => mockGroupStore,
}))

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
    mod: mockMod,
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
    mockGroupStore.get.mockReturnValue(mockGroup)
    mockAuthStore.user = { id: 1, systemrole: 'User' }
    mockMiscStore.breakpoint = 'lg'
    mockMod.value = false
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
          'nuxt-link': {
            template: '<a :href="to"><slot /></a>',
            props: ['to', 'noPrefetch'],
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

  describe('group display', () => {
    it('shows group link when not summary mode', () => {
      const wrapper = createWrapper({ summary: false })
      expect(wrapper.find('a').exists()).toBe(true)
    })

    it('shows group name', () => {
      const wrapper = createWrapper({ summary: false })
      expect(wrapper.text()).toContain('Freegle London')
    })

    it('links to explore page', () => {
      const wrapper = createWrapper({ summary: false })
      const link = wrapper.find('a')
      expect(link.attributes('href')).toContain('/explore/')
    })
  })

  describe('message ID display', () => {
    it('shows message ID when not summary', () => {
      const wrapper = createWrapper({ summary: false })
      expect(wrapper.text()).toContain('#1')
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

  describe('rippled-in copy attribution (Discourse 9890)', () => {
    // A post approved by a human mod (200) on its origin group (100) rippled
    // into group 300, where it was auto-approved (approvedby is null there -
    // no moderator on 300 has ever seen or approved it).
    const rippledMessage = {
      ...mockMessage,
      groups: [
        {
          groupid: 100,
          arrival: '2024-01-15T10:00:00Z',
          collection: 'Approved',
          approvedby: 200,
          rippled_in: 0,
        },
        {
          groupid: 300,
          arrival: '2024-01-15T10:05:00Z',
          collection: 'Approved',
          approvedby: null,
          rippled_in: 1,
        },
      ],
    }

    beforeEach(() => {
      mockAuthStore.user = { id: 1, systemrole: 'Moderator' }
      mockMod.value = true
      mockMessageStore.byId.mockReturnValue(rippledMessage)
    })

    it('does not attribute the rippled-in copy to the origin group approving mod', () => {
      const wrapper = createWrapper({ modinfo: true, onlyGroupid: 300 })
      expect(wrapper.text()).not.toContain('Approved by')
    })

    it('still attributes the origin group copy to its own approving mod', () => {
      const wrapper = createWrapper({ modinfo: true, onlyGroupid: 100 })
      expect(wrapper.text()).toContain('Approved by Mod User')
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
            namedisplay: 'Freegle London',
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
      expect(wrapper.find('a').exists()).toBe(true)
    })

    it('hides details on xs screens in summary mode', () => {
      mockMiscStore.breakpoint = 'xs'
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('a').exists()).toBe(false)
    })
  })

  describe('message ID link URL for collection types', () => {
    function wrapperWithCollection(collection) {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        groups: [{ groupid: 100, arrival: '2024-01-15T10:00:00Z', collection }],
      })
      return createWrapper({ modinfo: true })
    }

    it('uses /pending/ URL for Pending collection', () => {
      const wrapper = wrapperWithCollection('Pending')
      const btn = wrapper.find('.b-button')
      expect(btn.attributes('to')).toContain('/messages/pending/')
    })

    it('uses /pending/ URL for PendingOther collection', () => {
      const wrapper = wrapperWithCollection('PendingOther')
      const btn = wrapper.find('.b-button')
      expect(btn.attributes('to')).toContain('/messages/pending/')
    })

    it('uses /pending/ URL for Spam collection (not /messages/spam/)', () => {
      const wrapper = wrapperWithCollection('Spam')
      const btn = wrapper.find('.b-button')
      expect(btn.attributes('to')).toContain('/messages/pending/')
      expect(btn.attributes('to')).not.toContain('/messages/spam/')
    })

    it('uses /approved/ URL for Approved collection', () => {
      const wrapper = wrapperWithCollection('Approved')
      const btn = wrapper.find('.b-button')
      expect(btn.attributes('to')).toContain('/messages/approved/')
    })

    it('uses /approved/ URL when collection is null', () => {
      const wrapper = wrapperWithCollection(null)
      const btn = wrapper.find('.b-button')
      expect(btn.attributes('to')).toContain('/messages/approved/')
    })
  })
})
