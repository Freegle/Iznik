import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import DonationTraditionalExtras from '~/components/DonationTraditionalExtras.vue'

describe('DonationTraditionalExtras', () => {
  function createWrapper(props = {}) {
    return mount(DonationTraditionalExtras, {
      props: {
        ...props,
      },
      global: {
        stubs: {
          SupporterInfo: {
            template: '<span class="supporter-info" />',
            props: ['size'],
          },
          'nuxt-link': {
            template: '<a :href="to"><slot /></a>',
            props: ['to', 'noPrefetch'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders container div', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('div').exists()).toBe(true)
    })

    it('mentions supporter badge', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('badge')
    })

    it('mentions ads being turned off', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('turn off ads for a month')
    })

    it('includes SupporterInfo component', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.supporter-info').exists()).toBe(true)
    })

    it('links to donate page with noguard', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('a[href="/donate?noguard=true"]').exists()).toBe(true)
    })

    it('mentions alternative donation methods', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('bank transfer or cheque')
    })
  })

  describe('general fund message', () => {
    it('shows general fund message when target not met', () => {
      const wrapper = createWrapper({
        targetMet: false,
      })
      expect(wrapper.text()).toContain('general fund')
      expect(wrapper.text()).toContain('other communities')
    })

    it('does not show that message when target is met', () => {
      const wrapper = createWrapper({
        targetMet: true,
      })
      const paragraphs = wrapper.findAll('p')
      // Should only have one paragraph (the main text)
      const generalFundText = paragraphs.filter((p) =>
        p.text().includes('contribute to the general fund')
      )
      expect(generalFundText.length).toBeLessThan(2)
    })
  })

  describe('props', () => {
    it('defaults targetMet to false', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('targetMet')).toBe(false)
    })
  })
})
