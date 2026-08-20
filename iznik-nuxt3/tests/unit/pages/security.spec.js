import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import SecurityPage from '~/pages/security.vue'

globalThis.useHead = () => {}

describe('pages/security.vue', () => {
  it('renders the security policy sections', () => {
    const wrapper = mount(SecurityPage, {
      global: {
        stubs: { 'client-only': { template: '<div><slot /></div>' } },
      },
    })

    expect(wrapper.text()).toContain('Reporting Security Issues')
    expect(wrapper.text()).toContain('What Happens Next')
    expect(wrapper.text()).toContain('Our Promise to You')
    expect(wrapper.text()).toContain("What's In Scope")
    expect(wrapper.text()).toContain('Out of Scope')
  })
})
