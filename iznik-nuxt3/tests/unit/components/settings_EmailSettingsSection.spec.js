import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import EmailSettingsSection from '~/components/settings/EmailSettingsSection.vue'

// There is one national community now, not a per-group list, so email settings
// are a single site-wide switch backed directly by fields on the user object
// (emailfrequency/eventsallowed/volunteeringallowed), the same convention
// already used here for relevantallowed/newslettersallowed.
const { mockMe } = vi.hoisted(() => {
  const { ref } = require('vue')
  return {
    mockMe: ref({
      id: 123,
      settings: {
        simplemail: 'Full',
        notifications: {
          email: true,
          emailmine: false,
          push: true,
          facebook: true,
        },
        notificationmails: true,
        engagement: true,
      },
      relevantallowed: true,
      newslettersallowed: true,
      emailfrequency: 24,
      eventsallowed: true,
      volunteeringallowed: true,
    }),
  }
})

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

describe('EmailSettingsSection', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMe.value = {
      id: 123,
      settings: {
        simplemail: 'Full',
        notifications: {
          email: true,
          emailmine: false,
          push: true,
          facebook: true,
        },
        notificationmails: true,
        engagement: true,
      },
      relevantallowed: true,
      newslettersallowed: true,
      emailfrequency: 24,
      eventsallowed: true,
      volunteeringallowed: true,
    }
  })

  function createWrapper() {
    return mount(EmailSettingsSection, {
      global: {
        stubs: {
          'v-icon': {
            template: '<span class="v-icon" :data-icon="icon" />',
            props: ['icon'],
          },
          'b-form-select': {
            template:
              '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>',
            props: ['modelValue'],
          },
          'b-form-select-option': {
            template: '<option :value="value"><slot /></option>',
            props: ['value'],
          },
          'nuxt-link': {
            template: '<a :href="to"><slot /></a>',
            props: ['to', 'noPrefetch'],
          },
          NoticeMessage: {
            template:
              '<div class="notice-message" :class="variant"><slot /></div>',
            props: ['variant'],
          },
          SettingsGroup: {
            template:
              '<div class="settings-group" :data-emailfrequency="emailfrequency" :data-eventsallowed="eventsallowed" :data-volunteeringallowed="volunteeringallowed" />',
            props: [
              'emailfrequency',
              'eventsallowed',
              'volunteeringallowed',
              'label',
            ],
            emits: [
              'update:emailfrequency',
              'update:eventsallowed',
              'update:volunteeringallowed',
            ],
          },
          SettingsEmailInfo: {
            template: '<div class="settings-email-info" />',
            props: ['simpleEmailSetting'],
          },
          OurToggle: {
            template:
              '<div class="our-toggle" :data-checked="modelValue" @click="$emit(\'change\', !modelValue)" />',
            props: ['modelValue', 'width', 'sync', 'labels', 'color'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders settings section container', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.settings-section').exists()).toBe(true)
    })

    it('renders section header', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.section-header').exists()).toBe(true)
    })

    it('displays Email Settings title', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('h2').text()).toBe('Email Settings')
    })

    it('renders envelope icon', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.v-icon[data-icon="envelope"]').exists()).toBe(true)
    })

    it('renders section content unconditionally - there is one national community', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.section-content').exists()).toBe(true)
      expect(wrapper.text()).not.toContain(
        "You're not a member of any communities yet"
      )
    })
  })

  describe('email level and settings toggle', () => {
    it('renders the email level dropdown', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.email-select').exists()).toBe(true)
      expect(wrapper.text()).toContain('Email level:')
    })

    it('renders three email level options', () => {
      const wrapper = createWrapper()
      const options = wrapper.findAll('option')
      expect(options.length).toBe(3)
    })

    it('has Off option', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('option[value="None"]').exists()).toBe(true)
      expect(wrapper.text()).toContain('Off')
    })

    it('has Basic option', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('option[value="Basic"]').exists()).toBe(true)
      expect(wrapper.text()).toContain('Basic')
    })

    it('has Standard option', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('option[value="Full"]').exists()).toBe(true)
      expect(wrapper.text()).toContain('Standard')
    })

    it('has no Show advanced settings button - the advanced options are always visible', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.link-btn').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('Show advanced settings')
    })

    it('renders SettingsGroup bound to the site-wide settings when not None', () => {
      const wrapper = createWrapper()
      const settingsGroup = wrapper.find('.settings-group')
      expect(settingsGroup.exists()).toBe(true)
      expect(settingsGroup.attributes('data-emailfrequency')).toBe('24')
      expect(settingsGroup.attributes('data-eventsallowed')).toBe('true')
      expect(settingsGroup.attributes('data-volunteeringallowed')).toBe('true')
    })

    it('renders SettingsEmailInfo when not None', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.settings-email-info').exists()).toBe(true)
    })

    it('hides SettingsGroup and SettingsEmailInfo when level is None', async () => {
      const wrapper = createWrapper()
      wrapper.vm.simpleEmailSettingLocal = 'None'
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.settings-group').exists()).toBe(false)
      expect(wrapper.find('.settings-email-info').exists()).toBe(false)
    })
  })

  describe('no email notifications warning', () => {
    it('shows warning when simpleEmailSettingLocal is None', async () => {
      const wrapper = createWrapper()
      wrapper.vm.simpleEmailSettingLocal = 'None'
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.notice-message.danger').exists()).toBe(true)
    })

    it('shows warning message about checking chats', async () => {
      const wrapper = createWrapper()
      wrapper.vm.simpleEmailSettingLocal = 'None'
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain("You won't get email notifications")
      expect(wrapper.text()).toContain('Check')
      expect(wrapper.text()).toContain('regularly')
    })

    it('has link to chats page', async () => {
      const wrapper = createWrapper()
      wrapper.vm.simpleEmailSettingLocal = 'None'
      await wrapper.vm.$nextTick()
      const link = wrapper.find('a[href="/chats"]')
      expect(link.exists()).toBe(true)
    })

    it('does not show warning when email is Full', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.notice-message.danger').exists()).toBe(false)
    })
  })

  describe('advanced options (always visible)', () => {
    beforeEach(() => {
      vi.useFakeTimers()
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    it('renders the advanced options block without needing a toggle', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.advanced-options').exists()).toBe(true)
    })

    it('renders email replies toggle', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Email me replies to my posts')
    })

    it('renders sent messages copy toggle', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Copy of my sent messages')
    })

    it('renders chitchat toggle', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('ChitChat & notifications')
    })

    it('renders suggested posts toggle', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Suggested posts for you')
    })

    it('renders newsletters toggle', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Newsletters & stories')
    })

    it('renders encouragement toggle', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Encouragement emails')
    })

    it('renders admin note', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain(
        'We may occasionally send important admin emails'
      )
    })
  })

  describe('computed properties', () => {
    describe('simpleEmailSetting', () => {
      it('returns Full by default', () => {
        const wrapper = createWrapper()
        expect(wrapper.vm.simpleEmailSetting).toBe('Full')
      })

      it('returns value from me.value.settings.simplemail', () => {
        const wrapper = createWrapper()
        mockMe.value = {
          ...mockMe.value,
          settings: {
            ...mockMe.value.settings,
            simplemail: 'Basic',
          },
        }
        expect(wrapper.vm.simpleEmailSetting).toBe('Basic')
      })

      it('returns Full when simplemail is not set', () => {
        const wrapper = createWrapper()
        mockMe.value = {
          ...mockMe.value,
          settings: {
            ...mockMe.value.settings,
            simplemail: null,
          },
        }
        expect(wrapper.vm.simpleEmailSetting).toBe('Full')
      })
    })

    describe('notificationSettings', () => {
      it('returns default notification settings', () => {
        mockMe.value.settings.notifications = null
        const wrapper = createWrapper()
        expect(wrapper.vm.notificationSettings.email).toBe(true)
        expect(wrapper.vm.notificationSettings.emailmine).toBe(false)
      })

      it('returns user notification settings', () => {
        const wrapper = createWrapper()
        expect(wrapper.vm.notificationSettings.email).toBe(true)
        expect(wrapper.vm.notificationSettings.emailmine).toBe(false)
      })
    })

    describe('notificationmails', () => {
      it('returns true when enabled', () => {
        const wrapper = createWrapper()
        expect(wrapper.vm.notificationmails).toBe(true)
      })

      it('returns false when disabled', async () => {
        mockMe.value.settings.notificationmails = false
        const wrapper = createWrapper()
        await wrapper.vm.$nextTick()
        expect(wrapper.vm.notificationmails).toBe(false)
      })
    })

    describe('relevantallowed', () => {
      it('returns true when enabled', () => {
        const wrapper = createWrapper()
        expect(wrapper.vm.relevantallowed).toBe(true)
      })

      it('returns false when disabled', async () => {
        mockMe.value.relevantallowed = false
        const wrapper = createWrapper()
        await wrapper.vm.$nextTick()
        expect(wrapper.vm.relevantallowed).toBe(false)
      })
    })

    describe('newslettersallowed', () => {
      it('returns true when enabled', () => {
        const wrapper = createWrapper()
        expect(wrapper.vm.newslettersallowed).toBe(true)
      })

      it('returns false when disabled', async () => {
        mockMe.value.newslettersallowed = false
        const wrapper = createWrapper()
        await wrapper.vm.$nextTick()
        expect(wrapper.vm.newslettersallowed).toBe(false)
      })
    })
  })

  describe('methods', () => {
    describe('changeSetting', () => {
      it('calls saveAndGet with the parsed emailfrequency value', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeSetting('emailfrequency', '0')
        expect(mockSaveAndGet).toHaveBeenCalledWith({ emailfrequency: 0 })
      })

      it('calls saveAndGet with the parsed eventsallowed value', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeSetting('eventsallowed', 1)
        expect(mockSaveAndGet).toHaveBeenCalledWith({ eventsallowed: 1 })
      })

      it('calls saveAndGet with the parsed volunteeringallowed value', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeSetting('volunteeringallowed', 0)
        expect(mockSaveAndGet).toHaveBeenCalledWith({
          volunteeringallowed: 0,
        })
      })

      it('emits update event', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeSetting('emailfrequency', '24')
        expect(wrapper.emitted('update')).toBeTruthy()
      })
    })

    describe('changeNotification', () => {
      it('calls saveAndGet with email setting', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeNotification(false, 'email')
        expect(mockSaveAndGet).toHaveBeenCalled()
      })

      it('emits update event', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeNotification(false, 'email')
        expect(wrapper.emitted('update')).toBeTruthy()
      })
    })

    describe('changeRelevant', () => {
      it('calls saveAndGet with relevantallowed', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeRelevant(false)
        expect(mockSaveAndGet).toHaveBeenCalledWith({ relevantallowed: false })
      })

      it('emits update event', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeRelevant(false)
        expect(wrapper.emitted('update')).toBeTruthy()
      })
    })

    describe('changeNotifChitchat', () => {
      it('calls saveAndGet with settings', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeNotifChitchat(false)
        expect(mockSaveAndGet).toHaveBeenCalled()
      })

      it('emits update event', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeNotifChitchat(false)
        expect(wrapper.emitted('update')).toBeTruthy()
      })
    })

    describe('changeNewsletter', () => {
      it('calls saveAndGet with newslettersallowed', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeNewsletter(false)
        expect(mockSaveAndGet).toHaveBeenCalledWith({
          newslettersallowed: false,
        })
      })

      it('emits update event', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeNewsletter(false)
        expect(wrapper.emitted('update')).toBeTruthy()
      })
    })

    describe('changeEngagement', () => {
      it('calls saveAndGet with engagement setting', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeEngagement(false)
        expect(mockSaveAndGet).toHaveBeenCalled()
      })

      it('emits update event', async () => {
        const wrapper = createWrapper()
        await wrapper.vm.changeEngagement(false)
        expect(wrapper.emitted('update')).toBeTruthy()
      })
    })
  })

  describe('reactive state', () => {
    it('initializes simpleEmailSettingLocal from me', () => {
      const wrapper = createWrapper()
      expect(wrapper.vm.simpleEmailSettingLocal).toBe('Full')
    })

    it('initializes notification settings local', () => {
      const wrapper = createWrapper()
      expect(wrapper.vm.notificationSettingsLocal.email).toBe(true)
    })
  })

  describe('watch', () => {
    it('updates local values when me changes', async () => {
      const wrapper = createWrapper()
      expect(wrapper.vm.relevantallowedLocal).toBe(true)

      mockMe.value = {
        ...mockMe.value,
        relevantallowed: false,
      }
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.relevantallowedLocal).toBe(false)
    })
  })
})
