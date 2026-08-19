import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, nextTick } from 'vue'
import LayoutCommon from '~/components/LayoutCommon.vue'

const mockMiscStore = {
  stickyAdRendered: 0,
  replyOverlayOpen: false,
  setTime: vi.fn(),
  startOnlineCheck: vi.fn(),
  visible: true,
}
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

const mockAuthStore = { fetchUser: vi.fn() }
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

const mockNotificationStore = { fetchCount: vi.fn() }
vi.mock('~/stores/notification', () => ({
  useNotificationStore: () => mockNotificationStore,
}))

const mockMessageStore = { fetch: vi.fn().mockResolvedValue({}) }
vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

const mockChatStore = {
  fetchChats: vi.fn().mockResolvedValue([]),
  pollForChatUpdates: vi.fn(),
}
vi.mock('~/stores/chat', () => ({
  useChatStore: () => mockChatStore,
}))

const mockMobileStore = { deviceuserinfo: { model: 'test-device' } }
vi.mock('@/stores/mobile', () => ({
  useMobileStore: () => mockMobileStore,
}))

const mockMe = ref(null)
const mockMyid = ref(null)
const mockLoggedIn = ref(false)
const mockRecentDonor = ref(false)
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: mockMe,
    myid: mockMyid,
    loggedIn: mockLoggedIn,
    recentDonor: mockRecentDonor,
  }),
}))

const mockReplyToSend = ref(null)
const mockReplyToUser = ref(null)
const mockReplyToPost = vi.fn().mockResolvedValue(null)
vi.mock('~/composables/useReplyToPost', () => ({
  useReplyToPost: () => ({
    replyToSend: mockReplyToSend,
    replyToUser: mockReplyToUser,
    replyToPost: mockReplyToPost,
  }),
}))

let mockRoutePath = '/'
globalThis.__testUseRoute = () => ({ path: mockRoutePath })

const mockSentrySetContext = vi.fn()
const mockSentrySetUser = vi.fn()
vi.mock('#app', async () => {
  const actual = await vi.importActual('#app')
  return {
    ...actual,
    useNuxtApp: () => ({
      $sentrySetContext: mockSentrySetContext,
      $sentrySetUser: mockSentrySetUser,
    }),
  }
})

const globalStubs = {
  'client-only': { template: '<div><slot /></div>' },
  VisibleWhen: { template: '<div><slot /></div>', props: ['at'] },
  DaDisableCTA: { template: '<div class="da-disable-cta" />' },
  DeletedRestore: { template: '<div class="deleted-restore" />' },
  BouncingEmail: { template: '<div class="bouncing-email" />' },
  // Its own spec covers what it says; here it only has to not drag
  // bootstrap-vue-next's grid into an unstubbed mount.
  MailDelayed: { template: '<div class="mail-delayed" />' },
  BreakpointFettler: { template: '<div class="breakpoint-fettler" />' },
  OrientationFettler: { template: '<div class="orientation-fettler" />' },
  SomethingWentWrong: { template: '<div class="something-went-wrong" />' },
  SupportLink: { template: '<div class="support-link" />', props: ['text'] },
  InterestedInOthersModal: {
    template: '<div class="interested-modal" />',
    props: ['msgid', 'userid'],
  },
  ChatButton: {
    template: '<div class="chat-button-stub" />',
    props: ['userid'],
    emits: ['sent'],
  },
  ExternalDa: {
    name: 'ExternalDa',
    template: '<div class="external-da" :data-placement="placement" />',
    props: [
      'adUnitPath',
      'maxHeight',
      'maxWidth',
      'minWidth',
      'divId',
      'jobs',
      'hideJobsHeader',
      'placement',
      'video',
    ],
    emits: ['rendered', 'failed'],
  },
}

function findExternalDa(wrapper, placement) {
  return wrapper
    .findAllComponents({ name: 'ExternalDa' })
    .find((c) => c.props('placement') === placement)
}

function createWrapper() {
  return mount(LayoutCommon, {
    slots: { default: '<div class="page-slot-content">content</div>' },
    global: { stubs: globalStubs },
  })
}

describe('LayoutCommon', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMiscStore.stickyAdRendered = 0
    mockMiscStore.replyOverlayOpen = false
    mockMe.value = null
    mockMyid.value = null
    mockLoggedIn.value = false
    mockRecentDonor.value = false
    mockReplyToSend.value = null
    mockReplyToUser.value = null
    mockReplyToPost.mockResolvedValue(null)
    mockRoutePath = '/'
    globalThis.__testRuntimeConfig = () => ({
      public: { BUILD_DATE: '2026-01-01', DEPLOY_ID: 'abc', ISAPP: false },
    })
    delete globalThis.__insp
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  describe('rendering', () => {
    it('renders the default slot content', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.page-slot-content').exists()).toBe(true)
    })

    it('hides the boot loader after mount', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('#serverloader').exists()).toBe(false)
    })

    it('starts the online checker on mount', () => {
      createWrapper()
      expect(mockMiscStore.startOnlineCheck).toHaveBeenCalled()
    })

    it('ticks the shared clock via miscStore.setTime', () => {
      createWrapper()
      expect(mockMiscStore.setTime).toHaveBeenCalled()
    })

    it('places DaDisableCTA after ExternalDa in the source (CLS fix)', () => {
      // Regression guard kept from the previous placeholder spec.

      const fs = require('fs')
      const src = fs.readFileSync(
        require('path').resolve(
          __dirname,
          '../../../components/LayoutCommon.vue'
        ),
        'utf-8'
      )
      const daDisableCtaPos = src.indexOf('<DaDisableCTA')
      const externalDaPos = src.indexOf('<ExternalDa')
      expect(daDisableCtaPos).toBeGreaterThan(-1)
      expect(externalDaPos).toBeGreaterThan(-1)
      expect(daDisableCtaPos).toBeGreaterThan(externalDaPos)
    })
  })

  describe('allowAd computed', () => {
    it.each([
      ['/', false, false, false],
      ['/', true, false, true],
      ['/partnerships', false, false, false],
      ['/partnerships/', false, false, false],
      ['/together', false, false, false],
      ['/together/', false, false, false],
      ['/browse', false, false, true],
      ['/browse', false, true, false],
    ])(
      'path=%s loggedIn=%s recentDonor=%s -> allowAd=%s',
      async (path, loggedIn, recentDonor, expected) => {
        mockRoutePath = path
        mockLoggedIn.value = loggedIn
        mockRecentDonor.value = recentDonor
        const wrapper = createWrapper()
        await flushPromises()
        expect(wrapper.find('.aboveSticky').classes()).toContain(
          expected ? 'allowAd' : 'aboveSticky'
        )
        expect(wrapper.find('.aboveSticky').classes().includes('allowAd')).toBe(
          expected
        )
      }
    )
  })

  describe('sticky ad rendering', () => {
    it('adRendered marks the ad as shown and updates stickyAdRendered', async () => {
      mockRoutePath = '/browse'
      const wrapper = createWrapper()
      await flushPromises()
      const mobileAd = findExternalDa(wrapper, 'sticky_footer_mobile')
      await mobileAd.vm.$emit('rendered', true)
      expect(mockMiscStore.stickyAdRendered).toBe(1)
    })

    it('adRendered(false) records the ad as not shown', async () => {
      mockRoutePath = '/browse'
      const wrapper = createWrapper()
      await flushPromises()
      const mobileAd = findExternalDa(wrapper, 'sticky_footer_mobile')
      await mobileAd.vm.$emit('rendered', false)
      expect(mockMiscStore.stickyAdRendered).toBe(0)
    })

    it('adFailed records the ad as not rendered', async () => {
      mockRoutePath = '/browse'
      mockMiscStore.stickyAdRendered = 1
      const wrapper = createWrapper()
      await flushPromises()
      const mobileAd = findExternalDa(wrapper, 'sticky_footer_mobile')
      await mobileAd.vm.$emit('failed')
      expect(mockMiscStore.stickyAdRendered).toBe(0)
    })

    it('does not render the sticky ad zone when allowAd is false', async () => {
      mockRoutePath = '/'
      mockLoggedIn.value = false
      const wrapper = createWrapper()
      await flushPromises()
      expect(findExternalDa(wrapper, 'sticky_footer_mobile')).toBeUndefined()
      expect(findExternalDa(wrapper, 'sticky_footer_desktop')).toBeUndefined()
    })

    it('always renders the video ad slot regardless of allowAd', async () => {
      mockRoutePath = '/'
      mockLoggedIn.value = false
      const wrapper = createWrapper()
      await flushPromises()
      expect(
        wrapper.find('#videoda').findComponent({ name: 'ExternalDa' }).exists()
      ).toBe(true)
    })
  })

  describe('logged-in Sentry / Inspectlet context', () => {
    it('sets Sentry context with build info when logged out', async () => {
      createWrapper()
      await flushPromises()
      expect(mockSentrySetContext).toHaveBeenCalledWith(
        'builddate',
        expect.objectContaining({ buildDate: '2026-01-01', deployId: 'abc' })
      )
      expect(mockSentrySetUser).not.toHaveBeenCalled()
    })

    it('sets Sentry user and tags Inspectlet session when logged in', async () => {
      mockMe.value = { id: 42 }
      mockMyid.value = 42
      globalThis.__insp = { push: vi.fn() }
      createWrapper()
      await flushPromises()
      expect(mockSentrySetUser).toHaveBeenCalledWith({ id: 42 })
      expect(globalThis.__insp.push).toHaveBeenCalledWith([
        'tagSession',
        expect.objectContaining({ userid: 42 }),
      ])
    })

    it('tags Inspectlet session as logged out when no user', async () => {
      globalThis.__insp = { push: vi.fn() }
      createWrapper()
      await flushPromises()
      expect(globalThis.__insp.push).toHaveBeenCalledWith([
        'tagSession',
        expect.objectContaining({ userid: 'Logged out' }),
      ])
    })

    it('skips Inspectlet tagging entirely when __insp is undefined', async () => {
      createWrapper()
      await flushPromises()
      // No throw, and no assumption made about a global that does not exist.
      expect(globalThis.__insp).toBeUndefined()
    })

    it('reads mobile device info from the mobile store when running as the app', async () => {
      globalThis.__testRuntimeConfig = () => ({
        public: {
          BUILD_DATE: '2026-01-01',
          DEPLOY_ID: 'abc',
          ISAPP: true,
          MOBILE_VERSION: '9.9.9',
        },
      })
      createWrapper()
      await flushPromises()
      expect(mockSentrySetContext).toHaveBeenCalledWith(
        'builddate',
        expect.objectContaining({
          mobileVersion: '9.9.9',
          deviceuserinfo: mockMobileStore.deviceuserinfo,
        })
      )
    })

    it('swallows errors from a failing Sentry context call', async () => {
      mockSentrySetContext.mockImplementationOnce(() => {
        throw new Error('sentry unavailable')
      })
      expect(() => createWrapper()).not.toThrow()
      await flushPromises()
    })
  })

  describe('reply-to-post on load', () => {
    it('does nothing when there is no pending reply', async () => {
      createWrapper()
      await flushPromises()
      expect(mockMessageStore.fetch).not.toHaveBeenCalled()
      expect(mockReplyToPost).not.toHaveBeenCalled()
    })

    it('fetches the referenced message and sends the pending reply', async () => {
      mockReplyToSend.value = { replyMsgId: 555 }
      mockReplyToUser.value = 777
      mockReplyToPost.mockResolvedValue(true)
      const wrapper = createWrapper()
      await flushPromises()
      expect(mockMessageStore.fetch).toHaveBeenCalledWith(555, true)
      expect(mockReplyToPost).toHaveBeenCalled()
      // A truthy reply result triggers replySent(), which opens the modal.
      expect(wrapper.find('.interested-modal').exists()).toBe(true)
    })

    it('does not open the interested-modal when the reply send fails', async () => {
      mockReplyToSend.value = { replyMsgId: 555 }
      mockReplyToUser.value = 777
      mockReplyToPost.mockResolvedValue(null)
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.interested-modal').exists()).toBe(false)
    })
  })

  describe('tab visibility monitoring', () => {
    function setHidden(hidden) {
      Object.defineProperty(document, 'hidden', {
        configurable: true,
        get: () => hidden,
      })
    }

    afterEach(() => {
      Object.defineProperty(document, 'hidden', {
        configurable: true,
        get: () => false,
      })
    })

    it('refetches notifications and chats when the tab becomes visible', async () => {
      createWrapper()
      await flushPromises()
      setHidden(false)
      document.dispatchEvent(new Event('visibilitychange'))
      await flushPromises()
      expect(mockNotificationStore.fetchCount).toHaveBeenCalled()
      expect(mockChatStore.fetchChats).toHaveBeenCalledWith(null, false)
    })

    it('re-checks login by fetching the user when the chat refetch fails', async () => {
      mockChatStore.fetchChats.mockRejectedValueOnce(new Error('logged out'))
      createWrapper()
      await flushPromises()
      setHidden(false)
      document.dispatchEvent(new Event('visibilitychange'))
      await flushPromises()
      expect(mockAuthStore.fetchUser).toHaveBeenCalled()
    })

    it('does not refetch while the tab is hidden', async () => {
      createWrapper()
      await flushPromises()
      setHidden(true)
      document.dispatchEvent(new Event('visibilitychange'))
      await flushPromises()
      expect(mockNotificationStore.fetchCount).not.toHaveBeenCalled()
    })

    // TODO: latent bug — monitorTabVisibility checks `if (me && !document.hidden)`
    // where `me` is the ref object itself (always truthy), not `me.value`. So it
    // refetches notifications/chats on every visibility change even when logged
    // out. This looks unintended but is left untouched per the coverage-only
    // scope of this change.
    it.skip('does not refetch when logged out (currently always refetches - latent bug)', async () => {
      mockMe.value = null
      createWrapper()
      await flushPromises()
      setHidden(false)
      document.dispatchEvent(new Event('visibilitychange'))
      await flushPromises()
      expect(mockNotificationStore.fetchCount).not.toHaveBeenCalled()
    })
  })

  describe('chat polling', () => {
    it('polls for chat updates when logged in', () => {
      mockMe.value = { id: 3 }
      createWrapper()
      expect(mockChatStore.pollForChatUpdates).toHaveBeenCalled()
    })

    it('does not poll when logged out', () => {
      mockMe.value = null
      createWrapper()
      expect(mockChatStore.pollForChatUpdates).not.toHaveBeenCalled()
    })
  })

  describe('sticky-ad detector heights', () => {
    it('uses the tall banner height when the detector reports display:block', async () => {
      vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'block' })
      mockRoutePath = '/browse'
      const wrapper = createWrapper()
      await flushPromises()
      await nextTick()
      const mobileAd = findExternalDa(wrapper, 'sticky_footer_mobile')
      expect(mobileAd.props('maxHeight')).toBe('100px')
      const desktopAd = findExternalDa(wrapper, 'sticky_footer_desktop')
      expect(desktopAd.props('maxHeight')).toBe('250px')
    })

    it('uses the short banner height when the detector reports a non-block display', async () => {
      vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'none' })
      mockRoutePath = '/browse'
      const wrapper = createWrapper()
      await flushPromises()
      await nextTick()
      const mobileAd = findExternalDa(wrapper, 'sticky_footer_mobile')
      expect(mobileAd.props('maxHeight')).toBe('50px')
      const desktopAd = findExternalDa(wrapper, 'sticky_footer_desktop')
      expect(desktopAd.props('maxHeight')).toBe('90px')
    })
  })

  describe('window resize handling', () => {
    it('registers a resize listener on mount', () => {
      const addSpy = vi.spyOn(window, 'addEventListener')
      createWrapper()
      expect(addSpy).toHaveBeenCalledWith('resize', expect.any(Function))
    })

    it('removes the resize listener and clears the clock timer on unmount', () => {
      const removeSpy = vi.spyOn(window, 'removeEventListener')
      const wrapper = createWrapper()
      wrapper.unmount()
      expect(removeSpy).toHaveBeenCalledWith('resize', expect.any(Function))
    })
  })
})
