import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import DisclaimerPage from '~/pages/disclaimer.vue'

globalThis.useHead = () => {}

describe('pages/disclaimer.vue', () => {
  it('renders the safety and scams sections', () => {
    const wrapper = mount(DisclaimerPage, {
      global: {
        stubs: { 'client-only': { template: '<div><slot /></div>' } },
      },
    })

    expect(wrapper.text()).toContain('Safety')
    expect(wrapper.text()).toContain('Scams')
  })
})
