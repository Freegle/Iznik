import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, Suspense, ref } from 'vue'
import ProfileInfo from '~/components/ProfileInfo.vue'

// Mock composables
const mockMe = ref({
  id: 1,
  lat: 51.5,
  lng: -0.1,
})
const mockMyid = ref(1)
const mockMod = ref(false)

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: mockMe,
    myid: mockMyid,
    mod: mockMod,
  }),
}))

// Mock useDistance
vi.mock('~/composables/useDistance', () => ({
  milesAway: vi.fn((lat1, lng1, lat2, lng2) => {
    if (lat1 && lng1 && lat2 && lng2) return 5
    return null
  }),
}))

// Mock useTwem
vi.mock('~/composables/useTwem', () => ({
  twem: (text) => text,
}))

// Mock useTimeFormat
vi.mock('~/composables/useTimeFormat', () => ({
  dateonly: (date) => 'January 2020',
}))

// Mock pluralize
vi.mock('pluralize', () => ({
  default: (word, count, includeCount) =>
    includeCount ? `${count} ${word}${count !== 1 ? 's' : ''}` : word,
}))

// Mock stores
const mockUserById = vi.fn()
const mockPublicLocationById = vi.fn()
const mockFetchPublicLocation = vi.fn()
const mockFetchByUser = vi.fn()
const mockMessageFetch = vi.fn()

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    byId: mockUserById,
    publicLocationById: mockPublicLocationById,
    fetchPublicLocation: mockFetchPublicLocation,
  }),
}))

const mockMessageById = vi.fn()

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetchByUser: mockFetchByUser,
    fetch: mockMessageFetch,
    byId: mockMessageById,
  }),
}))

// Mock child components
vi.mock('~/components/ProfileImage', () => ({
  default: {
    name: 'ProfileImage',
    template: '<div class="profile-image"><img :src="image" /></div>',
    props: ['image', 'name', 'isThumbnail', 'size'],
  },
}))

vi.mock('~/components/UserRatings', () => ({
  default: {
    name: 'UserRatings',
    template: '<div class="user-ratings"></div>',
    props: ['id', 'size', 'disabled'],
  },
}))

const { mockOpenChat } = vi.hoisted(() => ({ mockOpenChat: vi.fn() }))

// The real ChatButton renders our own button through its slot and exposes
// openChat, which the profile calls once it knows what the message is about.
vi.mock('~/components/ChatButton', () => ({
  default: {
    name: 'ChatButton',
    template: '<div class="chat-button"><slot /></div>',
    props: ['userid', 'size', 'title', 'variant'],
    setup(_, { expose }) {
      expose({ openChat: mockOpenChat })
      return {}
    },
  },
}))

// Mock defineAsyncComponent for MessageList
vi.mock('vue', async (importOriginal) => {
  const mod = await importOriginal()
  return {
    ...mod,
    defineAsyncComponent: (loader) => ({
      name: 'MessageList',
      template: '<div class="message-list"><slot /></div>',
      props: ['messagesForList', 'selectedType', 'jobs'],
    }),
  }
})

describe('ProfileInfo', () => {
  const mockUser = {
    id: 42,
    displayname: 'Test User',
    supporter: false,
    showmod: false,
    added: '2020-01-15T10:00:00Z',
    lat: 51.6,
    lng: -0.2,
    aboutme: { text: 'Hello, I love freecycling!' },
    expectedreplies: 0,
    info: {
      offers: 15,
      wanteds: 5,
      collected: 20,
      replytime: 3600, // 1 hour in seconds
    },
    profile: {
      path: '/images/profile.jpg',
    },
  }

  // As fetchByUser returns them: no subject, that comes with the full record.
  const mockMessages = [
    { id: 1, type: 'Offer', successful: false },
    { id: 2, type: 'Wanted', successful: false },
    { id: 3, type: 'Offer', successful: true }, // Should be excluded
  ]
  const mockSubjects = {
    1: 'OFFER: Chairs (BA1)',
    2: 'WANTED: Pump (BA2)',
    3: 'OFFER: Gone (BA1)',
  }

  function createWrapper(
    props = {},
    userData = mockUser,
    messages = mockMessages
  ) {
    mockUserById.mockReturnValue(userData)
    mockPublicLocationById.mockReturnValue({ location: 'Test Town' })
    mockFetchByUser.mockResolvedValue(messages)
    // The full records, as the store holds them once fetched: the list from
    // fetchByUser has no subject.
    mockMessageById.mockImplementation((id) => {
      const m = messages.find((msg) => msg.id === id)
      return m ? { ...m, subject: mockSubjects[id] } : null
    })
    mockFetchPublicLocation.mockResolvedValue()

    const TestWrapper = defineComponent({
      setup() {
        return () =>
          h(
            Suspense,
            {},
            {
              default: () =>
                h(ProfileInfo, {
                  id: 42,
                  ...props,
                }),
              fallback: () => h('div', 'Loading...'),
            }
          )
      },
    })

    return mount(TestWrapper, {
      global: {
        stubs: {
          NuxtLink: {
            template: '<a :href="to" class="nuxt-link"><slot /></a>',
            props: ['to'],
          },
          'v-icon': {
            template: '<i :class="icon"></i>',
            props: ['icon'],
          },
          MessageList: {
            template: '<div class="message-list"></div>',
            props: ['messagesForList', 'selectedType', 'jobs'],
          },
          PostSelectionModal: {
            name: 'PostSelectionModal',
            template: '<div class="post-selection-modal" />',
            props: ['posts', 'name', 'sending', 'error'],
            emits: ['select', 'other', 'hidden'],
          },
          'b-button': {
            template:
              '<button class="b-button" @click="$emit(\'click\', $event)"><slot /></button>',
            props: ['variant', 'size'],
            emits: ['click'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockMe.value = { id: 1, lat: 51.5, lng: -0.1 }
    mockMyid.value = 1
    mockMod.value = false
  })

  describe('rendering', () => {
    it('renders user profile when user exists', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.profile-mobile').exists()).toBe(true)
    })

    it('does not render when user is null', async () => {
      mockUserById.mockReturnValue(null)
      const wrapper = createWrapper({}, null)
      await flushPromises()
      expect(wrapper.find('.profile-mobile').exists()).toBe(false)
    })

    it('displays user display name', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('Test User')
    })

    it('displays member since date', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('Freegler since')
      expect(wrapper.text()).toContain('January 2020')
    })
  })

  describe('supporter badge', () => {
    it('shows supporter badge when user is supporter', async () => {
      const supporterUser = { ...mockUser, supporter: true }
      const wrapper = createWrapper({}, supporterUser)
      await flushPromises()
      expect(wrapper.find('.supporter-badge').exists()).toBe(true)
    })

    it('hides supporter badge when user is not supporter', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.supporter-badge').exists()).toBe(false)
    })

    it('shows supporter notice for supporters', async () => {
      const supporterUser = { ...mockUser, supporter: true }
      const wrapper = createWrapper({}, supporterUser)
      await flushPromises()
      expect(wrapper.find('.supporter-notice').exists()).toBe(true)
    })
  })

  describe('volunteer badge', () => {
    it('shows volunteer badge when showmod is true', async () => {
      const modUser = { ...mockUser, showmod: true }
      const wrapper = createWrapper({}, modUser)
      await flushPromises()
      expect(wrapper.find('.volunteer-badge').exists()).toBe(true)
      expect(wrapper.text()).toContain('Volunteer')
    })

    it('hides volunteer badge when showmod is false', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.volunteer-badge').exists()).toBe(false)
    })
  })

  describe('stats display', () => {
    it('displays offers count', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('15')
      expect(wrapper.text()).toContain('OFFERs')
    })

    it('displays wanteds count', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('5')
      expect(wrapper.text()).toContain('WANTEDs')
    })

    it('displays collected count', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('20')
      expect(wrapper.text()).toContain('Collected')
    })

    it('shows 0 for missing stats', async () => {
      const userNoStats = { ...mockUser, info: null }
      const wrapper = createWrapper({}, userNoStats)
      await flushPromises()
      const statValues = wrapper.findAll('.stat-value')
      expect(statValues[0].text()).toBe('0')
    })
  })

  describe('about me section', () => {
    it('displays about me text', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('Hello, I love freecycling!')
    })

    it('hides about me section when empty', async () => {
      const userNoAbout = { ...mockUser, aboutme: null }
      const wrapper = createWrapper({}, userNoAbout)
      await flushPromises()
      expect(wrapper.find('.about-section').exists()).toBe(false)
    })
  })

  describe('location display', () => {
    it('displays location from public location', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('Test Town')
    })

    it('hides location when not available', async () => {
      mockPublicLocationById.mockReturnValue(null)
      mockUserById.mockReturnValue(mockUser)
      mockFetchByUser.mockResolvedValue(mockMessages)
      mockFetchPublicLocation.mockResolvedValue()

      const TestWrapper = defineComponent({
        setup() {
          return () =>
            h(
              Suspense,
              {},
              {
                default: () => h(ProfileInfo, { id: 42 }),
                fallback: () => h('div', 'Loading...'),
              }
            )
        },
      })

      const wrapper = mount(TestWrapper, {
        global: {
          stubs: {
            NuxtLink: {
              template: '<a :href="to" class="nuxt-link"><slot /></a>',
              props: ['to'],
            },
            'v-icon': {
              template: '<i :class="icon"></i>',
              props: ['icon'],
            },
            MessageList: {
              template: '<div class="message-list"></div>',
              props: ['messagesForList', 'selectedType', 'jobs'],
            },
          },
        },
      })
      await flushPromises()
      expect(wrapper.find('.location-line').exists()).toBe(false)
    })
  })

  describe('distance display', () => {
    it('displays miles away when distance available', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('5 miles')
    })
  })

  describe('reply time display', () => {
    it('displays reply time in hours', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('Replies ~1h')
    })

    it('displays reply time in minutes for short times', async () => {
      const quickUser = {
        ...mockUser,
        info: { ...mockUser.info, replytime: 300 },
      }
      const wrapper = createWrapper({}, quickUser)
      await flushPromises()
      expect(wrapper.text()).toContain('Replies ~5m')
    })

    it('displays reply time in days for long times', async () => {
      const slowUser = {
        ...mockUser,
        info: { ...mockUser.info, replytime: 172800 },
      } // 2 days
      const wrapper = createWrapper({}, slowUser)
      await flushPromises()
      expect(wrapper.text()).toContain('Replies ~2d')
    })

    it('hides reply time when not available', async () => {
      const noReplyUser = {
        ...mockUser,
        info: { ...mockUser.info, replytime: null },
      }
      const wrapper = createWrapper({}, noReplyUser)
      await flushPromises()
      expect(wrapper.text()).not.toContain('Replies ~')
    })
  })

  describe('expected replies warning', () => {
    it('shows warning when user has expected replies', async () => {
      const userWithReplies = { ...mockUser, expectedreplies: 3 }
      const wrapper = createWrapper({}, userWithReplies)
      await flushPromises()
      expect(wrapper.find('.expected-replies-warning').exists()).toBe(true)
      expect(wrapper.text()).toContain('waiting for a reply')
    })

    it('hides warning when no expected replies', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.expected-replies-warning').exists()).toBe(false)
    })
  })

  describe('action buttons', () => {
    it('renders user ratings', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.user-ratings').exists()).toBe(true)
    })

    it('shows chat button when viewing other user profile', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.chat-button').exists()).toBe(true)
    })

    it('hides chat button when viewing own profile', async () => {
      mockMyid.value = 42 // Same as profile id
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.chat-button').exists()).toBe(false)
    })

    // Most messages sent from a profile are about one of the person's posts
    // (#11), so the button asks which one before the chat opens.
    describe('what the message is about', () => {
      it('asks which post it is about when they have posts up', async () => {
        const wrapper = createWrapper()
        await flushPromises()

        await wrapper.find('.message-btn').trigger('click')
        await flushPromises()

        const modal = wrapper.findComponent({ name: 'PostSelectionModal' })
        expect(modal.exists()).toBe(true)
        // Only the posts still open, offers and wanteds together, with the
        // subjects the store holds.
        expect(modal.props('posts')).toEqual([
          { id: 1, type: 'Offer', subject: 'OFFER: Chairs (BA1)' },
          { id: 2, type: 'Wanted', subject: 'WANTED: Pump (BA2)' },
        ])
        expect(modal.props('name')).toBe('Test User')
        expect(mockOpenChat).not.toHaveBeenCalled()
      })

      it('opens the chat straight away when they have nothing up', async () => {
        const wrapper = createWrapper({}, mockUser, [])
        await flushPromises()

        await wrapper.find('.message-btn').trigger('click')
        await flushPromises()

        expect(
          wrapper.findComponent({ name: 'PostSelectionModal' }).exists()
        ).toBe(false)
        expect(mockOpenChat).toHaveBeenCalledWith(null, null, null, false)
      })

      it('sends the message about the chosen post', async () => {
        const wrapper = createWrapper()
        await flushPromises()
        await wrapper.find('.message-btn').trigger('click')
        await flushPromises()

        wrapper
          .findComponent({ name: 'PostSelectionModal' })
          .vm.$emit('select', { id: 2, text: 'I have a pump you can have.' })
        await flushPromises()

        expect(mockOpenChat).toHaveBeenCalledWith(
          null,
          'I have a pump you can have.',
          2,
          false,
          false,
          'profile'
        )
      })

      it('opens a plain chat for something else', async () => {
        const wrapper = createWrapper()
        await flushPromises()
        await wrapper.find('.message-btn').trigger('click')
        await flushPromises()

        wrapper.findComponent({ name: 'PostSelectionModal' }).vm.$emit('other')
        await flushPromises()

        expect(mockOpenChat).toHaveBeenCalledWith(
          null,
          null,
          null,
          false,
          false,
          'profile'
        )
      })

      it('leaves out posts they could not reply to from the post itself', async () => {
        // Promised to somebody else, finished, or past its deadline: the post's
        // own page would not offer a reply, so neither does this route.
        const messages = [
          { id: 1, type: 'Offer', successful: false },
          { id: 4, type: 'Offer', successful: false },
          { id: 5, type: 'Offer', successful: false },
          { id: 6, type: 'Wanted', successful: false, hasoutcome: true },
          { id: 7, type: 'Offer', successful: false },
        ]
        const full = {
          1: { subject: 'OFFER: Chairs (BA1)' },
          4: { subject: 'OFFER: Promised (BA1)', promised: true },
          5: {
            subject: 'OFFER: Late (BA1)',
            deadline: '2000-01-01T00:00:00Z',
          },
          6: { subject: 'WANTED: Done (BA1)' },
          7: {
            subject: 'OFFER: Mine (BA1)',
            promised: true,
            promisedtoyou: true,
          },
        }
        const wrapper = createWrapper({}, mockUser, messages)
        mockMessageById.mockImplementation((id) => full[id] || null)
        await flushPromises()

        await wrapper.find('.message-btn').trigger('click')
        await flushPromises()

        const modal = wrapper.findComponent({ name: 'PostSelectionModal' })
        expect(modal.props('posts').map((p) => p.id)).toEqual([1, 7])
      })

      it('keeps the question open and says so when the send fails', async () => {
        mockOpenChat.mockRejectedValueOnce(new Error('network'))
        const wrapper = createWrapper()
        await flushPromises()
        await wrapper.find('.message-btn').trigger('click')
        await flushPromises()

        const modal = wrapper.findComponent({ name: 'PostSelectionModal' })
        modal.vm.$emit('select', { id: 2, text: 'Still there?' })
        await flushPromises()

        expect(modal.exists()).toBe(true)
        expect(modal.props('sending')).toBe(false)
        expect(modal.props('error')).toContain("didn't send")
      })

      it('a ctrl-click still opens the chat in a new tab without asking', async () => {
        const wrapper = createWrapper()
        await flushPromises()

        await wrapper.find('.message-btn').trigger('click', { ctrlKey: true })
        await flushPromises()

        expect(
          wrapper.findComponent({ name: 'PostSelectionModal' }).exists()
        ).toBe(false)
        expect(mockOpenChat).toHaveBeenCalledWith(null, null, null, true)
      })
    })

    it('hides chat button when not logged in', async () => {
      mockMyid.value = null
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.chat-button').exists()).toBe(false)
    })
  })

  describe('moderator link', () => {
    it('shows member ID link for moderators', async () => {
      mockMod.value = true
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.member-id').exists()).toBe(true)
      expect(wrapper.find('.member-id').text()).toContain('#42')
    })

    it('hides member ID link for non-moderators', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.member-id').exists()).toBe(false)
    })
  })

  describe('active posts', () => {
    it('displays active offers count', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('1 active OFFER')
    })

    it('displays active wanteds count', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('1 active WANTED')
    })

    it('shows "None at the moment" for empty posts', async () => {
      mockFetchByUser.mockResolvedValue([])
      mockUserById.mockReturnValue(mockUser)
      mockPublicLocationById.mockReturnValue({ location: 'Test Town' })
      mockFetchPublicLocation.mockResolvedValue()

      const TestWrapper = defineComponent({
        setup() {
          return () =>
            h(
              Suspense,
              {},
              {
                default: () => h(ProfileInfo, { id: 42 }),
                fallback: () => h('div', 'Loading...'),
              }
            )
        },
      })

      const wrapper = mount(TestWrapper, {
        global: {
          stubs: {
            NuxtLink: {
              template: '<a :href="to" class="nuxt-link"><slot /></a>',
              props: ['to'],
            },
            'v-icon': {
              template: '<i :class="icon"></i>',
              props: ['icon'],
            },
            MessageList: {
              template: '<div class="message-list"></div>',
              props: ['messagesForList', 'selectedType', 'jobs'],
            },
          },
        },
      })
      await flushPromises()
      expect(wrapper.text()).toContain('None at the moment')
    })
  })

  describe('props', () => {
    it('requires id prop', async () => {
      createWrapper({ id: 99 })
      await flushPromises()
      // The id passed in createWrapper is 42, but we override with 99 in props
      // However createWrapper passes id:42 first, so check the store was called
      expect(mockUserById).toHaveBeenCalled()
    })
  })
})
