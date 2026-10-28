import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import OtherSettingsSection from '~/components/settings/OtherSettingsSection.vue'

const { mockMe } = vi.hoisted(() => {
  return {
    mockMe: {
      id: 1,
      settings: {
        enterNewLine: false,
        autorepostsdisable: false,
      },
    },
  }
})

const mockAuthStore = {
  saveAndGet: vi.fn().mockResolvedValue({}),
}

const mockMiscStore = {
  marketingConsent: true,
  setMarketingConsent: vi.fn(),
}

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: { value: mockMe },
  }),
}))

describe('OtherSettingsSection', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMe.settings = {
      enterNewLine: false,
      autorepostsdisable: false,
    }
    mockMe.donated = null
  })

  function createWrapper() {
    return mount(OtherSettingsSection, {
      global: {
        stubs: {
          'v-icon': {
            template: '<span class="v-icon" :data-icon="icon" />',
            props: ['icon'],
          },
          'nuxt-link': {
            template: '<a :href="to"><slot /></a>',
            props: ['to', 'noPrefetch'],
          },
          OurToggle: {
            template:
              '<button class="our-toggle" :data-checked="String(modelValue)" @click="$emit(\'change\', !modelValue)"><slot /></button>',
            props: ['modelValue', 'width', 'sync', 'labels', 'color'],
            emits: ['change', 'update:modelValue'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders settings section', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.settings-section').exists()).toBe(true)
    })

    it('shows section header with Other Settings title', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Other Settings')
    })

    it('shows cog icon', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('[data-icon="cog"]').exists()).toBe(true)
    })
  })

  describe('enter key setting', () => {
    it('shows enter key option label', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Enter key in chat')
    })

    it('shows enter key description', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Send message or add new line')
    })

    it('has toggle for enter key', () => {
      const wrapper = createWrapper()
      const toggles = wrapper.findAll('.our-toggle')
      expect(toggles.length).toBeGreaterThanOrEqual(1)
    })
  })

  describe('auto-repost setting', () => {
    it('shows auto-repost option label', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Auto-repost')
    })

    it('shows auto-repost description', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Bump posts until marked as done')
    })

    it('links to My Posts', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('a[href="/myposts"]').exists()).toBe(true)
    })
  })

  describe('marketing consent setting', () => {
    it('shows freegle updates option label', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Freegle updates')
    })

    it('shows freegle updates description', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('News and ways to support Freegle')
    })
  })

  describe('toggle interactions', () => {
    it('has three toggles', () => {
      const wrapper = createWrapper()
      const toggles = wrapper.findAll('.our-toggle')
      expect(toggles.length).toBe(3)
    })

    it('emits update on toggle change', async () => {
      const wrapper = createWrapper()
      const toggles = wrapper.findAll('.our-toggle')
      await toggles[0].trigger('click')
      await flushPromises()
      expect(wrapper.emitted('update')).toBeTruthy()
    })

    it('saves settings on enter key toggle', async () => {
      const wrapper = createWrapper()
      const toggles = wrapper.findAll('.our-toggle')
      await toggles[0].trigger('click')
      await flushPromises()
      expect(mockAuthStore.saveAndGet).toHaveBeenCalled()
    })

    it('saves settings on auto-repost toggle', async () => {
      const wrapper = createWrapper()
      const toggles = wrapper.findAll('.our-toggle')
      await toggles[1].trigger('click')
      await flushPromises()
      expect(mockAuthStore.saveAndGet).toHaveBeenCalled()
    })

    it('sets marketing consent on toggle', async () => {
      const wrapper = createWrapper()
      const toggles = wrapper.findAll('.our-toggle')
      await toggles[2].trigger('click')
      await flushPromises()
      expect(mockMiscStore.setMarketingConsent).toHaveBeenCalled()
    })
  })

  describe('donation requests setting', () => {
    it('is hidden for someone who has never donated', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).not.toContain('Donation requests')
      expect(wrapper.findAll('.our-toggle').length).toBe(3)
    })

    it('shows for someone who has donated, however long ago', () => {
      mockMe.donated = '2019-01-01T00:00:00Z'
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Donation requests')
      const toggles = wrapper.findAll('.our-toggle')
      expect(toggles.length).toBe(4)
      expect(toggles[3].attributes('data-checked')).toBe('true')
    })

    it('shows as off when they have asked not to be asked', () => {
      mockMe.donated = '2019-01-01T00:00:00Z'
      mockMe.settings.nodonationasks = true
      const wrapper = createWrapper()
      const toggles = wrapper.findAll('.our-toggle')
      expect(toggles[3].attributes('data-checked')).toBe('false')
    })

    it('saves nodonationasks when turned off', async () => {
      mockMe.donated = '2019-01-01T00:00:00Z'
      const wrapper = createWrapper()
      await wrapper.findAll('.our-toggle')[3].trigger('click')
      await flushPromises()
      expect(mockAuthStore.saveAndGet).toHaveBeenCalledWith({
        settings: expect.objectContaining({ nodonationasks: true }),
      })
      expect(wrapper.emitted('update')).toBeTruthy()
    })

    it('says when an opt-out ends', () => {
      mockMe.donated = '2019-01-01T00:00:00Z'
      mockMe.settings.nodonationasks = true
      mockMe.settings.nodonationasksuntil = '2099-03-05T10:00:00Z'
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain(
        "We won't ask you to donate until 5 March 2099."
      )
      expect(wrapper.findAll('.our-toggle')[3].attributes('data-checked')).toBe(
        'false'
      )
    })

    it('shows asks as on once an opt-out has lapsed', () => {
      mockMe.donated = '2019-01-01T00:00:00Z'
      mockMe.settings.nodonationasks = true
      mockMe.settings.nodonationasksuntil = '2020-01-01T00:00:00Z'
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('for a year')
      expect(wrapper.text()).not.toContain("We won't ask you to donate until")
      expect(wrapper.findAll('.our-toggle')[3].attributes('data-checked')).toBe(
        'true'
      )
    })

    it('does not send an old date when turning asks off again', async () => {
      mockMe.donated = '2019-01-01T00:00:00Z'
      mockMe.settings.nodonationasks = true
      mockMe.settings.nodonationasksuntil = '2020-01-01T00:00:00Z'
      const wrapper = createWrapper()
      await wrapper.findAll('.our-toggle')[3].trigger('click')
      await flushPromises()
      const sent = mockAuthStore.saveAndGet.mock.calls[0][0].settings
      expect(sent.nodonationasks).toBe(true)
      expect(sent).not.toHaveProperty('nodonationasksuntil')
    })

    it('saves nodonationasks false when turned back on', async () => {
      mockMe.donated = '2019-01-01T00:00:00Z'
      mockMe.settings.nodonationasks = true
      const wrapper = createWrapper()
      await wrapper.findAll('.our-toggle')[3].trigger('click')
      await flushPromises()
      expect(mockAuthStore.saveAndGet).toHaveBeenCalledWith({
        settings: expect.objectContaining({ nodonationasks: false }),
      })
    })
  })
})
