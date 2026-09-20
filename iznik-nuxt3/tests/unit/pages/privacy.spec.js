import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import PrivacyPage from '~/pages/privacy.vue'

globalThis.useHead = () => {}

describe('pages/privacy.vue', () => {
  it('renders the privacy policy sections', () => {
    const wrapper = mount(PrivacyPage, {
      global: {
        stubs: { 'client-only': { template: '<div><slot /></div>' } },
      },
    })

    expect(wrapper.text()).toContain('What data we process')
    expect(wrapper.text()).toContain('Cookies and Tracking')
    expect(wrapper.text()).toContain('Unsubscribing and deleting your data')
    expect(wrapper.text()).toContain('Legal Basis')
  })
})
