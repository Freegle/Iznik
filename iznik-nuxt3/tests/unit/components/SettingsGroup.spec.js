import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SettingsGroup from '~/components/SettingsGroup.vue'

// SettingsGroup is a dumb emit-only control now: there is one national set of
// settings, so it never looks anything up itself and never persists anything
// itself. The national site backs it with props (emailfrequency/eventsallowed/
// volunteeringallowed read from the current user); ModTools backs it with a
// membershipMT object (the member row) so it can control persistence itself.
describe('SettingsGroup', () => {
  function createWrapper(props = {}) {
    return mount(SettingsGroup, {
      props: {
        ...props,
      },
      global: {
        stubs: {
          OurToggle: {
            template:
              '<button class="our-toggle" :data-model-value="modelValue" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></button>',
            props: ['modelValue', 'size', 'labels'],
          },
          'b-form-select': {
            template:
              '<select class="b-form-select" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>',
            props: ['modelValue'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders settings-group container', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.settings-group').exists()).toBe(true)
    })

    it('renders email frequency select', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.b-form-select').exists()).toBe(true)
    })

    it('displays default label for email frequency', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.setting-label').text()).toBe('OFFER/WANTED emails')
    })

    it('displays custom label when provided', () => {
      const wrapper = createWrapper({ label: 'Custom Label' })
      expect(wrapper.find('.setting-label').text()).toBe('Custom Label')
    })

    it('renders community events toggle by default', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Community events')
    })

    it('renders volunteer opportunities toggle by default', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Volunteer opportunities')
    })

    it('hides events toggle when eventshide is true', () => {
      const wrapper = createWrapper({ eventshide: true })
      expect(wrapper.text()).not.toContain('Community events')
    })

    it('hides volunteer toggle when volunteerhide is true', () => {
      const wrapper = createWrapper({ volunteerhide: true })
      expect(wrapper.text()).not.toContain('Volunteer opportunities')
    })

    it('has no leave button - there is one national community, so nobody leaves it', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.leave-row').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('Leave')
    })
  })

  describe('computed emailfreq', () => {
    it('returns the emailfrequency prop when no membershipMT', () => {
      const wrapper = createWrapper({ emailfrequency: -1 })
      expect(wrapper.vm.emailfreq).toBe('-1')
    })

    it('defaults to 24 (Daily) when no emailfrequency prop and no membershipMT', () => {
      const wrapper = createWrapper()
      expect(wrapper.vm.emailfreq).toBe('24')
    })

    it('prefers membershipMT.emailfrequency over the emailfrequency prop', () => {
      const wrapper = createWrapper({
        emailfrequency: -1,
        membershipMT: { emailfrequency: 0 },
      })
      expect(wrapper.vm.emailfreq).toBe('0')
    })
  })

  describe('computed eventsallowed', () => {
    it('returns the eventsallowed prop when no membershipMT', () => {
      const wrapper = createWrapper({ eventsallowed: true })
      expect(wrapper.vm.eventsallowed).toBe(true)
    })

    it('defaults to false', () => {
      const wrapper = createWrapper()
      expect(wrapper.vm.eventsallowed).toBe(false)
    })

    it('prefers membershipMT.eventsallowed over the eventsallowed prop', () => {
      const wrapper = createWrapper({
        eventsallowed: false,
        membershipMT: { eventsallowed: 1 },
      })
      expect(wrapper.vm.eventsallowed).toBe(true)
    })
  })

  describe('computed volunteeringallowed', () => {
    it('returns the volunteeringallowed prop when no membershipMT', () => {
      const wrapper = createWrapper({ volunteeringallowed: true })
      expect(wrapper.vm.volunteeringallowed).toBe(true)
    })

    it('defaults to false', () => {
      const wrapper = createWrapper()
      expect(wrapper.vm.volunteeringallowed).toBe(false)
    })

    it('prefers membershipMT.volunteeringallowed over the volunteeringallowed prop', () => {
      const wrapper = createWrapper({
        volunteeringallowed: false,
        membershipMT: { volunteeringallowed: 1 },
      })
      expect(wrapper.vm.volunteeringallowed).toBe(true)
    })
  })

  describe('computed highlightEmailFrequencyIfOn', () => {
    it('returns frequency-on when emailfrequency is not 0', () => {
      const wrapper = createWrapper({ emailfrequency: 24 })
      expect(wrapper.vm.highlightEmailFrequencyIfOn).toBe('frequency-on')
    })

    it('returns frequency-off when emailfrequency is 0', () => {
      const wrapper = createWrapper({ emailfrequency: 0 })
      expect(wrapper.vm.highlightEmailFrequencyIfOn).toBe('frequency-off')
    })
  })

  describe('emits', () => {
    it('emits update:emailfrequency when email frequency changes, and persists nothing itself', async () => {
      const wrapper = createWrapper()
      wrapper.vm.emailfreq = '0'
      await wrapper.vm.$nextTick()
      expect(wrapper.emitted('update:emailfrequency')).toBeTruthy()
      expect(wrapper.emitted('update:emailfrequency')[0]).toEqual(['0'])
    })

    it('emits update:eventsallowed as 1/0 when the events toggle changes', async () => {
      const wrapper = createWrapper()
      wrapper.vm.eventsallowed = true
      await wrapper.vm.$nextTick()
      expect(wrapper.emitted('update:eventsallowed')[0]).toEqual([1])
    })

    it('emits update:volunteeringallowed as 1/0 when the volunteering toggle changes', async () => {
      const wrapper = createWrapper()
      wrapper.vm.volunteeringallowed = true
      await wrapper.vm.$nextTick()
      expect(wrapper.emitted('update:volunteeringallowed')[0]).toEqual([1])
    })
  })

  describe('membershipMT prop (used by ModTools)', () => {
    it('uses membershipMT for emailfreq, eventsallowed and volunteeringallowed when provided', () => {
      const mtMembership = {
        emailfrequency: -1,
        eventsallowed: 0,
        volunteeringallowed: 0,
      }
      const wrapper = createWrapper({ membershipMT: mtMembership })
      expect(wrapper.vm.emailfreq).toBe('-1')
      expect(wrapper.vm.eventsallowed).toBe(false)
      expect(wrapper.vm.volunteeringallowed).toBe(false)
    })
  })
})
