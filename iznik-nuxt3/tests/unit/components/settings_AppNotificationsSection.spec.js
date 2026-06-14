import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import AppNotificationsSection from '~/components/settings/AppNotificationsSection.vue'

// ---------------------------------------------------------------------------
// Hoisted reactive mock for `useMe` so we can update it between tests.
// ---------------------------------------------------------------------------
const { mockMe } = vi.hoisted(() => {
  const { ref } = require('vue')
  return {
    mockMe: ref({
      settings: {
        notifications: {
          dailypostspush: true,
        },
      },
    }),
  }
})

// ---------------------------------------------------------------------------
// Mocks
// ---------------------------------------------------------------------------
const mockSaveAndGet = vi.fn()

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: mockMe,
  }),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    saveAndGet: mockSaveAndGet,
  }),
}))

// mobileStore with isApp controllable per test
let mockIsApp = true
vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({
    get isApp() {
      return mockIsApp
    },
  }),
}))

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function createWrapper() {
  return mount(AppNotificationsSection, {
    global: {
      stubs: {
        'v-icon': {
          template: '<span class="v-icon" :data-icon="icon" />',
          props: ['icon'],
        },
        OurToggle: {
          template:
            '<div class="our-toggle" :data-checked="modelValue" @click="$emit(\'change\', !modelValue)"><slot /></div>',
          props: ['modelValue', 'width', 'sync', 'labels', 'color'],
          emits: ['update:modelValue', 'change'],
        },
      },
    },
  })
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------
describe('AppNotificationsSection', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockIsApp = true
    mockMe.value = {
      settings: {
        notifications: {
          dailypostspush: true,
        },
      },
    }
  })

  // -------------------------------------------------------------------------
  // Visibility guard
  // -------------------------------------------------------------------------
  describe('app-only rendering', () => {
    it('renders the section when isApp is true', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.settings-section').exists()).toBe(true)
    })

    it('does NOT render the section when isApp is false', () => {
      mockIsApp = false
      const wrapper = createWrapper()
      expect(wrapper.find('.settings-section').exists()).toBe(false)
    })
  })

  // -------------------------------------------------------------------------
  // Static content
  // -------------------------------------------------------------------------
  describe('rendering', () => {
    it('shows the section header', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.section-header').exists()).toBe(true)
    })

    it('shows "App Notifications" as the heading', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('h2').text()).toBe('App Notifications')
    })

    it('renders the mobile-alt icon', () => {
      const wrapper = createWrapper()
      expect(
        wrapper.find('.v-icon[data-icon="mobile-alt"]').exists()
      ).toBe(true)
    })

    it('shows the toggle label text', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Daily notification of new posts')
    })

    it('shows the toggle description', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('A daily push with new offers')
    })

    it('renders one OurToggle', () => {
      const wrapper = createWrapper()
      expect(wrapper.findAll('.our-toggle').length).toBe(1)
    })
  })

  // -------------------------------------------------------------------------
  // Reactive state — reading settings.notifications.dailypostspush
  // -------------------------------------------------------------------------
  describe('reactive state', () => {
    it('initializes dailyPostsPushLocal as true when the setting is true', () => {
      const wrapper = createWrapper()
      expect(wrapper.vm.dailyPostsPushLocal).toBe(true)
    })

    it('initializes dailyPostsPushLocal as false when the setting is false', async () => {
      mockMe.value = {
        settings: { notifications: { dailypostspush: false } },
      }
      const wrapper = createWrapper()
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.dailyPostsPushLocal).toBe(false)
    })

    it('defaults to true when dailypostspush key is absent', async () => {
      mockMe.value = {
        settings: { notifications: {} },
      }
      const wrapper = createWrapper()
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.dailyPostsPushLocal).toBe(true)
    })

    it('defaults to true when notifications object is absent', async () => {
      mockMe.value = { settings: {} }
      const wrapper = createWrapper()
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.dailyPostsPushLocal).toBe(true)
    })
  })

  // -------------------------------------------------------------------------
  // changeDailyPostsPush — writes settings.notifications.dailypostspush via
  // authStore.saveAndGet
  // -------------------------------------------------------------------------
  describe('changeDailyPostsPush', () => {
    it('calls authStore.saveAndGet with dailypostspush=false when toggled off', async () => {
      const wrapper = createWrapper()
      await wrapper.vm.changeDailyPostsPush(false)

      expect(mockSaveAndGet).toHaveBeenCalledOnce()
      const call = mockSaveAndGet.mock.calls[0][0]
      expect(call.settings.notifications.dailypostspush).toBe(false)
    })

    it('calls authStore.saveAndGet with dailypostspush=true when toggled on', async () => {
      mockMe.value = {
        settings: { notifications: { dailypostspush: false } },
      }
      const wrapper = createWrapper()
      await wrapper.vm.changeDailyPostsPush(true)

      expect(mockSaveAndGet).toHaveBeenCalledOnce()
      const call = mockSaveAndGet.mock.calls[0][0]
      expect(call.settings.notifications.dailypostspush).toBe(true)
    })

    it('emits an update event after saving', async () => {
      const wrapper = createWrapper()
      await wrapper.vm.changeDailyPostsPush(false)
      expect(wrapper.emitted('update')).toBeTruthy()
    })

    it('creates notifications object if absent before saving', async () => {
      mockMe.value = { settings: {} }
      const wrapper = createWrapper()
      await wrapper.vm.changeDailyPostsPush(false)

      const call = mockSaveAndGet.mock.calls[0][0]
      expect(call.settings.notifications.dailypostspush).toBe(false)
    })
  })

  // -------------------------------------------------------------------------
  // Watcher — local state updates when me changes externally
  // -------------------------------------------------------------------------
  describe('watch(me)', () => {
    it('updates dailyPostsPushLocal when me.settings changes', async () => {
      const wrapper = createWrapper()
      expect(wrapper.vm.dailyPostsPushLocal).toBe(true)

      mockMe.value = {
        settings: { notifications: { dailypostspush: false } },
      }
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.dailyPostsPushLocal).toBe(false)
    })

    it('restores to true if key is removed from settings', async () => {
      mockMe.value = {
        settings: { notifications: { dailypostspush: false } },
      }
      const wrapper = createWrapper()
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.dailyPostsPushLocal).toBe(false)

      mockMe.value = { settings: {} }
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.dailyPostsPushLocal).toBe(true)
    })
  })
})
