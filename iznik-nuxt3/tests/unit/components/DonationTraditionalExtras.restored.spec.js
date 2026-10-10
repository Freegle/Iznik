import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import DonationTraditionalExtras from '~/components/DonationTraditionalExtras.vue'

describe('DonationTraditionalExtras', () => {
  function createWrapper(props = {}) {
    return mount(DonationTraditionalExtras, {
      props: {
        groupname: 'Test Community',
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

  describe('groupid prop behavior', () => {

    describe('when groupid is set', () => {
      it('shows general fund message when target not met and thermometer visible', () => {
        const wrapper = createWrapper({
          groupid: 1,
          targetMet: false,
          hideThermometer: false,
        })
        expect(wrapper.text()).toContain('general fund')
        expect(wrapper.text()).toContain('other communities')
      })

      it('does not show general fund message when target is met', () => {
        const wrapper = createWrapper({
          groupid: 1,
          targetMet: true,
          hideThermometer: false,
        })
        const paragraphs = wrapper.findAll('p')
        // Should only have one paragraph (the main text)
        const generalFundText = paragraphs.filter((p) =>
          p.text().includes('contribute to the general fund')
        )
        expect(generalFundText.length).toBeLessThan(2)
      })
    })
  })

})
