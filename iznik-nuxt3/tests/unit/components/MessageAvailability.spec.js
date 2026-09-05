import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MessageAvailability from '~/components/MessageAvailability.vue'

function createWrapper(props = {}) {
  return mount(MessageAvailability, {
    props: {
      availablenow: 3,
      availableinitially: 3,
      ...props,
    },
    global: {
      stubs: {
        'b-badge': {
          template: '<span class="b-badge" :class="variant"><slot /></span>',
          props: ['variant'],
        },
      },
    },
  })
}

describe('MessageAvailability', () => {
  describe('single item', () => {
    it('shows nothing when only one was ever on offer', () => {
      const wrapper = createWrapper({ availablenow: 1, availableinitially: 1 })
      expect(wrapper.find('.b-badge').exists()).toBe(false)
    })

    it('shows nothing when the single item has gone', () => {
      const wrapper = createWrapper({ availablenow: 0, availableinitially: 1 })
      expect(wrapper.find('.b-badge').exists()).toBe(false)
    })
  })

  describe('ordinary post with nothing given away yet', () => {
    it('shows how many are on offer', () => {
      const wrapper = createWrapper({ availablenow: 3, availableinitially: 3 })
      expect(wrapper.text()).toContain('3 available')
    })
  })

  describe('ordinary post that is part gone', () => {
    it('says part gone instead of a number', () => {
      const wrapper = createWrapper({ availablenow: 2, availableinitially: 5 })
      expect(wrapper.text()).toContain('Part gone, some still available')
    })

    it('gives no number at all', () => {
      const wrapper = createWrapper({ availablenow: 2, availableinitially: 5 })
      expect(wrapper.text()).not.toMatch(/[0-9]/)
    })

    it('still says part gone when the count has run down to zero', () => {
      const wrapper = createWrapper({ availablenow: 0, availableinitially: 5 })
      expect(wrapper.text()).toContain('Part gone, some still available')
    })
  })

  describe('bulk clearance offer', () => {
    it('keeps the absolute count once part gone', () => {
      const wrapper = createWrapper({
        availablenow: 2,
        availableinitially: 5,
        bulkcount: 4,
      })
      expect(wrapper.text()).toContain('2 available')
    })

    it('keeps the absolute count when untouched', () => {
      const wrapper = createWrapper({
        availablenow: 5,
        availableinitially: 5,
        bulkcount: 4,
      })
      expect(wrapper.text()).toContain('5 available')
    })
  })

  describe('missing availableinitially', () => {
    it('falls back to the current count rather than claiming part gone', () => {
      const wrapper = createWrapper({ availablenow: 3, availableinitially: 0 })
      expect(wrapper.text()).toContain('3 available')
    })

    it('copes with availableinitially being absent altogether', () => {
      const wrapper = mount(MessageAvailability, {
        props: { availablenow: 4 },
        global: {
          stubs: {
            'b-badge': {
              template: '<span class="b-badge"><slot /></span>',
              props: ['variant'],
            },
          },
        },
      })
      expect(wrapper.text()).toContain('4 available')
    })
  })
})
