import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, Suspense } from 'vue'
import NotificationOne from '~/components/NotificationOne.vue'

const { mockNotification, mockNewsfeed } = vi.hoisted(() => {
  const { ref } = require('vue')
  return {
    mockNotification: ref({
      id: 123,
      type: 'LovedPost',
      seen: false,
      url: null,
    }),
    mockNewsfeed: ref({ id: 456 }),
  }
})

const mockNotificationStore = {
  seen: vi.fn(),
}

const mockRouter = {
  push: vi.fn(),
}

vi.mock('~/composables/useNotification', () => ({
  setupNotification: () =>
    Promise.resolve({
      notification: mockNotification,
      notificationStore: mockNotificationStore,
      newsfeed: mockNewsfeed,
    }),
}))

vi.hoisted(() => {
  vi.resetModules()
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRouter: () => mockRouter,
  }
})

globalThis.__testUseRouter = () => mockRouter

const USER_SITE = 'https://www.ilovefreegle.org'

describe('NotificationOne', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockNotification.value = {
      id: 123,
      type: 'LovedPost',
      seen: false,
      url: null,
    }
    mockNewsfeed.value = { id: 456 }
    globalThis.__testRuntimeConfig = () => ({ public: { USER_SITE } })
  })

  afterEach(() => {
    delete globalThis.__testRuntimeConfig
  })

  async function createWrapper(props = {}) {
    const TestWrapper = defineComponent({
      setup() {
        return () =>
          h(Suspense, null, {
            default: () => h(NotificationOne, { id: 123, ...props }),
            fallback: () => h('div', 'Loading...'),
          })
      },
    })

    const wrapper = mount(TestWrapper, {
      global: {
        stubs: {
          NotificationLovedPost: {
            template: '<div class="notification-loved-post" />',
            props: ['id'],
          },
          NotificationLovedComment: {
            template: '<div class="notification-loved-comment" />',
            props: ['id'],
          },
          NotificationCommentOnPost: {
            template: '<div class="notification-comment-post" />',
            props: ['id'],
          },
          NotificationCommentOnComment: {
            template: '<div class="notification-comment-comment" />',
            props: ['id'],
          },
          NotificationExhort: {
            template: '<div class="notification-exhort" />',
            props: ['id'],
          },
          NotificationAboutMe: {
            template:
              '<div class="notification-about-me" @click="$emit(\'show-modal\')" />',
            props: ['id'],
            emits: ['show-modal'],
          },
          NotificationGiftAid: {
            template: '<div class="notification-giftaid" />',
            props: ['id'],
          },
          NotificationOpenPosts: {
            template: '<div class="notification-open-posts" />',
            props: ['id'],
          },
        },
      },
    })

    await flushPromises()
    return wrapper
  }

  describe('rendering', () => {
    it('renders wrapper when notification exists', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('.notification__wrapper').exists()).toBe(true)
    })

    it('does not render when notification is null', async () => {
      mockNotification.value = null
      const wrapper = await createWrapper()
      expect(wrapper.find('.notification__wrapper').exists()).toBe(false)
    })
  })

  describe('notification types', () => {
    it('renders LovedPost notification', async () => {
      mockNotification.value.type = 'LovedPost'
      const wrapper = await createWrapper()
      expect(wrapper.find('.notification-loved-post').exists()).toBe(true)
    })

    it('renders LovedComment notification', async () => {
      mockNotification.value.type = 'LovedComment'
      const wrapper = await createWrapper()
      expect(wrapper.find('.notification-loved-comment').exists()).toBe(true)
    })

    it('renders CommentOnYourPost notification', async () => {
      mockNotification.value.type = 'CommentOnYourPost'
      const wrapper = await createWrapper()
      expect(wrapper.find('.notification-comment-post').exists()).toBe(true)
    })

    it('renders CommentOnCommented notification', async () => {
      mockNotification.value.type = 'CommentOnCommented'
      const wrapper = await createWrapper()
      expect(wrapper.find('.notification-comment-comment').exists()).toBe(true)
    })

    it('renders Exhort notification', async () => {
      mockNotification.value.type = 'Exhort'
      const wrapper = await createWrapper()
      expect(wrapper.find('.notification-exhort').exists()).toBe(true)
    })

    it('renders AboutMe notification', async () => {
      mockNotification.value.type = 'AboutMe'
      const wrapper = await createWrapper()
      expect(wrapper.find('.notification-about-me').exists()).toBe(true)
    })

    it('renders GiftAid notification', async () => {
      mockNotification.value.type = 'GiftAid'
      const wrapper = await createWrapper()
      expect(wrapper.find('.notification-giftaid').exists()).toBe(true)
    })

    it('renders OpenPosts notification', async () => {
      mockNotification.value.type = 'OpenPosts'
      const wrapper = await createWrapper()
      expect(wrapper.find('.notification-open-posts').exists()).toBe(true)
    })

    it('renders empty span for TryFeed type', async () => {
      mockNotification.value.type = 'TryFeed'
      const wrapper = await createWrapper()
      // TryFeed renders an empty span, no specific content
      expect(wrapper.find('.notification__wrapper').exists()).toBe(true)
    })

    it('shows unknown notification message for unrecognized types', async () => {
      mockNotification.value.type = 'UnknownType'
      const wrapper = await createWrapper()
      expect(wrapper.text()).toContain('Unknown notification UnknownType')
    })
  })

  describe('seen state', () => {
    it('applies bg-info class when not seen', async () => {
      mockNotification.value.seen = false
      const wrapper = await createWrapper()
      expect(wrapper.find('.bg-info').exists()).toBe(true)
    })

    it('does not apply bg-info class when seen', async () => {
      mockNotification.value.seen = true
      const wrapper = await createWrapper()
      expect(wrapper.find('.bg-info').exists()).toBe(false)
    })
  })

  describe('markSeen on hover', () => {
    it('calls notificationStore.seen on mouseover when not seen', async () => {
      mockNotification.value.seen = false
      const wrapper = await createWrapper()

      await wrapper.find('.notification__wrapper').trigger('mouseover')

      expect(mockNotificationStore.seen).toHaveBeenCalledWith(123)
    })

    it('does not call seen when already seen', async () => {
      mockNotification.value.seen = true
      const wrapper = await createWrapper()

      await wrapper.find('.notification__wrapper').trigger('mouseover')

      expect(mockNotificationStore.seen).not.toHaveBeenCalled()
    })
  })

  describe('click handling', () => {
    it('marks as seen on click', async () => {
      mockNotification.value.seen = false
      const wrapper = await createWrapper()

      await wrapper.find('.notification__wrapper').trigger('click')

      expect(mockNotificationStore.seen).toHaveBeenCalledWith(123)
    })

    it('opens URL when notification has url', async () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => {})
      mockNotification.value.url = 'https://example.com/notification'
      const wrapper = await createWrapper()

      await wrapper.find('.notification__wrapper').trigger('click')

      expect(openSpy).toHaveBeenCalledWith('https://example.com/notification')
      expect(mockRouter.push).not.toHaveBeenCalled()
      openSpy.mockRestore()
    })

    // The stories exhort is scheduled with a full https://www.ilovefreegle.org
    // URL. window.open() on that launches the system browser, which in the app
    // means leaving the app for a session where you're not logged in.
    it('routes internally for a url on our own site', async () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => {})
      mockNotification.value.url = USER_SITE + '/stories'
      const wrapper = await createWrapper()

      await wrapper.find('.notification__wrapper').trigger('click')

      expect(mockRouter.push).toHaveBeenCalledWith('/stories')
      expect(openSpy).not.toHaveBeenCalled()
      openSpy.mockRestore()
    })

    it('keeps the query and hash when routing internally', async () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => {})
      mockNotification.value.url = USER_SITE + '/stories?src=exhort#top'
      const wrapper = await createWrapper()

      await wrapper.find('.notification__wrapper').trigger('click')

      expect(mockRouter.push).toHaveBeenCalledWith('/stories?src=exhort#top')
      expect(openSpy).not.toHaveBeenCalled()
      openSpy.mockRestore()
    })

    it('routes internally for a url on the site we are being served from', async () => {
      // Dev and the app are served from somewhere other than USER_SITE.
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => {})
      mockNotification.value.url = window.location.origin + '/stories'
      const wrapper = await createWrapper()

      await wrapper.find('.notification__wrapper').trigger('click')

      expect(mockRouter.push).toHaveBeenCalledWith('/stories')
      expect(openSpy).not.toHaveBeenCalled()
      openSpy.mockRestore()
    })

    it('routes internally for a url that is already a path', async () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => {})
      mockNotification.value.url = '/stories'
      const wrapper = await createWrapper()

      await wrapper.find('.notification__wrapper').trigger('click')

      expect(mockRouter.push).toHaveBeenCalledWith('/stories')
      expect(openSpy).not.toHaveBeenCalled()
      openSpy.mockRestore()
    })

    it('opens externally when we have no site configured to compare against', async () => {
      globalThis.__testRuntimeConfig = () => ({ public: {} })
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => {})
      mockNotification.value.url = USER_SITE + '/stories'
      const wrapper = await createWrapper()

      await wrapper.find('.notification__wrapper').trigger('click')

      expect(openSpy).toHaveBeenCalledWith(USER_SITE + '/stories')
      expect(mockRouter.push).not.toHaveBeenCalled()
      openSpy.mockRestore()
    })

    it('opens externally for a url we cannot parse', async () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => {})
      mockNotification.value.url = 'not a url'
      const wrapper = await createWrapper()

      await wrapper.find('.notification__wrapper').trigger('click')

      expect(openSpy).toHaveBeenCalledWith('not a url')
      expect(mockRouter.push).not.toHaveBeenCalled()
      openSpy.mockRestore()
    })

    it('navigates to chitchat when no url but newsfeed exists', async () => {
      mockNotification.value.url = null
      mockNewsfeed.value = { id: 789 }
      const wrapper = await createWrapper()

      await wrapper.find('.notification__wrapper').trigger('click')

      expect(mockRouter.push).toHaveBeenCalledWith('/chitchat/789')
    })
  })

  describe('showModal emit', () => {
    it('emits showModal when AboutMe component emits', async () => {
      mockNotification.value.type = 'AboutMe'
      const wrapper = await createWrapper()

      await wrapper.find('.notification-about-me').trigger('click')

      const component = wrapper.findComponent(NotificationOne)
      expect(component.emitted('showModal')).toHaveLength(1)
    })
  })
})
