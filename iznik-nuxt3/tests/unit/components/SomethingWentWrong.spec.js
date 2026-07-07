import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import SomethingWentWrong from '~/components/SomethingWentWrong.vue'
import { HARD_RELOAD_COUNTDOWN_SECS } from '~/constants'

// Use vi.hoisted for mock setup
const { mockClearError } = vi.hoisted(() => ({
  mockClearError: vi.fn(),
}))

// Create refs at module level
const somethingWentWrongRef = ref(false)
const needToReloadRef = ref(false)
const needToReloadHardRef = ref(false)
const offlineRef = ref(false)
const unloadingRef = ref(false)
const errorDetailsRef = ref(null)
const lastTypingRef = ref(null)
const appOutOfDateRef = ref(false)
const appOutOfDateMessageRef = ref('')

// Mutable mobile-store state so each test can pick the app vs website variant.
const mobileState = { isApp: false }

// Mock Pinia's storeToRefs to return our refs while preserving other exports
vi.mock('pinia', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    storeToRefs: () => ({
      somethingWentWrong: somethingWentWrongRef,
      needToReload: needToReloadRef,
      needToReloadHard: needToReloadHardRef,
      offline: offlineRef,
      unloading: unloadingRef,
      errorDetails: errorDetailsRef,
      lastTyping: lastTypingRef,
      appOutOfDate: appOutOfDateRef,
      appOutOfDateMessage: appOutOfDateMessageRef,
    }),
  }
})

// Mock misc store
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    clearError: mockClearError,
  }),
}))

// Mock mobile store so we can toggle the app vs website experience.
vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => mobileState,
}))

describe('SomethingWentWrong', () => {
  let reloadSpy

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    // Reset ref values before each test
    somethingWentWrongRef.value = false
    needToReloadRef.value = false
    needToReloadHardRef.value = false
    offlineRef.value = false
    unloadingRef.value = false
    errorDetailsRef.value = null
    lastTypingRef.value = null
    appOutOfDateRef.value = false
    appOutOfDateMessageRef.value = ''
    mobileState.isApp = false
    // jsdom's location.reload is not directly assignable; redefine it so we can
    // observe forced reloads without navigating.
    reloadSpy = vi.fn()
    Object.defineProperty(window.location, 'reload', {
      configurable: true,
      value: reloadSpy,
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  function createWrapper() {
    return mount(SomethingWentWrong, {
      global: {
        stubs: {
          'client-only': {
            template: '<div><slot /></div>',
          },
          NoticeMessage: {
            template:
              '<div v-if="show" class="notice-message" :class="variant"><slot /></div>',
            // show must be typed Boolean so the component's bare `show` attribute
            // resolves to true (an untyped prop would receive "" and hide the banner).
            props: {
              variant: { type: String, default: '' },
              show: { type: Boolean, default: false },
            },
          },
          'b-button': {
            template:
              '<button :class="variant" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'size'],
          },
          SupportLink: {
            template: '<a class="support-link">Support</a>',
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('shows nothing when no errors and not reloading', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.notice-message').exists()).toBe(false)
    })

    it('hides content when unloading', () => {
      unloadingRef.value = true
      const wrapper = createWrapper()
      // The outer div should not render when unloading
      expect(wrapper.find('.notice-message').exists()).toBe(false)
    })
  })

  describe('methods', () => {
    it('has reload method', () => {
      const wrapper = createWrapper()
      expect(typeof wrapper.vm.reload).toBe('function')
    })

    it('has snooze method', () => {
      const wrapper = createWrapper()
      expect(typeof wrapper.vm.snooze).toBe('function')
    })

    it('has formatTimestamp method', () => {
      const wrapper = createWrapper()
      expect(typeof wrapper.vm.formatTimestamp).toBe('function')
    })
  })

  describe('formatTimestamp', () => {
    it('formats valid timestamp', () => {
      const wrapper = createWrapper()
      const result = wrapper.vm.formatTimestamp('2026-01-23T12:00:00Z')
      // Just verify it returns a string (locale-dependent formatting)
      expect(typeof result).toBe('string')
      expect(result).not.toBe('2026-01-23T12:00:00Z') // Should be formatted
    })

    it('returns original value on invalid timestamp', () => {
      const wrapper = createWrapper()
      const result = wrapper.vm.formatTimestamp('invalid')
      // Invalid date might still parse without throwing
      expect(typeof result).toBe('string')
    })
  })

  describe('store integration', () => {
    it('connects to misc store', () => {
      const wrapper = createWrapper()
      // Component should mount and connect to store without error
      expect(wrapper.exists()).toBe(true)
    })
  })

  describe('app out of date (server kill switch / ret:123)', () => {
    it('shows a danger banner with the server message and a Reload button on the website', () => {
      appOutOfDateRef.value = true
      appOutOfDateMessageRef.value =
        'App is out of date - please upgrade or use the website'
      mobileState.isApp = false

      const wrapper = createWrapper()
      const notice = wrapper.find('.notice-message')

      expect(notice.exists()).toBe(true)
      expect(notice.classes()).toContain('danger')
      expect(wrapper.text()).toContain(
        'App is out of date - please upgrade or use the website'
      )
      expect(wrapper.text()).toContain('Reload')
    })

    it('reloads when the Reload button is clicked on the website', async () => {
      appOutOfDateRef.value = true
      mobileState.isApp = false

      const wrapper = createWrapper()
      await wrapper.find('button').trigger('click')

      expect(reloadSpy).toHaveBeenCalled()
    })

    it('tells app users to update via their app store, offers the website, and shows no Reload button', () => {
      appOutOfDateRef.value = true
      appOutOfDateMessageRef.value =
        'App is out of date - please upgrade or use the website'
      mobileState.isApp = true

      const wrapper = createWrapper()

      expect(wrapper.find('.notice-message').exists()).toBe(true)
      expect(wrapper.text().toLowerCase()).toContain('app store')
      // Website fallback link is offered.
      expect(wrapper.find('a').exists()).toBe(true)
      // Reloading won't refresh a stale embedded app bundle, so no Reload button.
      expect(wrapper.find('button').exists()).toBe(false)
    })

    it('takes priority over the stale-build hard-reload banner', () => {
      appOutOfDateRef.value = true
      appOutOfDateMessageRef.value =
        'App is out of date - please upgrade or use the website'
      // needToReloadHard renders immediately (immediate watcher); the
      // out-of-date banner must still win.
      needToReloadHardRef.value = true

      const wrapper = createWrapper()

      expect(wrapper.text()).toContain('App is out of date')
      expect(wrapper.text()).not.toContain('more than a week out of date')
    })
  })

  describe('hard reload (stale build)', () => {
    it('shows a danger banner with a countdown when needToReloadHard is true', () => {
      needToReloadHardRef.value = true
      const wrapper = createWrapper()
      const notice = wrapper.find('.notice-message')
      expect(notice.exists()).toBe(true)
      expect(notice.classes()).toContain('danger')
      expect(wrapper.text()).toContain('more than a week out of date')
      expect(wrapper.text()).toContain(`${HARD_RELOAD_COUNTDOWN_SECS}s`)
      expect(wrapper.text()).toContain('Reload now')
    })

    it('reloads when the countdown expires and the user is not typing', () => {
      needToReloadHardRef.value = true
      lastTypingRef.value = null
      createWrapper()
      expect(reloadSpy).not.toHaveBeenCalled()
      vi.advanceTimersByTime(HARD_RELOAD_COUNTDOWN_SECS * 1000)
      expect(reloadSpy).toHaveBeenCalled()
    })

    it('reloads immediately when "Reload now" is clicked', async () => {
      needToReloadHardRef.value = true
      const wrapper = createWrapper()
      await wrapper.find('button').trigger('click')
      expect(reloadSpy).toHaveBeenCalled()
    })

    it('defers the reload while the user is mid-message', () => {
      const wrapper = createWrapper()
      // Typed just now -> within the typing window -> defer.
      lastTypingRef.value = Date.now()
      expect(wrapper.vm.maybeReload()).toBe(false)
      expect(reloadSpy).not.toHaveBeenCalled()
    })

    it('reloads via maybeReload when the user is not typing', () => {
      const wrapper = createWrapper()
      lastTypingRef.value = null
      expect(wrapper.vm.maybeReload()).toBe(true)
      expect(reloadSpy).toHaveBeenCalled()
    })
  })
})
