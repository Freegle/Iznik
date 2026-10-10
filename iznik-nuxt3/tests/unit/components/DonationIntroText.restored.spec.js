import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import DonationIntroText from '~/components/DonationIntroText.vue'

describe('DonationIntroText', () => {
  function createWrapper(props = {}) {
    return mount(DonationIntroText, {
      props: {
        groupname: 'Test Freegle',
        ...props,
      },
    })
  }

  describe('conditional text', () => {

    it('shows "across the UK" when no groupid', () => {
      const wrapper = createWrapper({ groupid: null })
      expect(wrapper.text()).toContain('across the UK')
    })

    it('shows "across the UK" when target is met', () => {
      const wrapper = createWrapper({ groupid: 123, targetMet: true })
      expect(wrapper.text()).toContain('across the UK')
    })
  })

})
