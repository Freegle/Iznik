import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModAdminFooterPreview from '~/modtools/components/ModAdminFooterPreview.vue'

function mountComponent(props = {}) {
  return mount(ModAdminFooterPreview, {
    props,
    global: {
      stubs: {
        ModClipboard: {
          template: '<button class="clip" :data-value="value" />',
          props: ['value'],
        },
        ExternalLink: {
          template: '<a :href="href"><slot /></a>',
          props: ['href'],
        },
      },
    },
  })
}

describe('ModAdminFooterPreview', () => {
  it('shows the charity line and registered address', () => {
    const text = mountComponent().text()
    expect(text).toContain('Freegle adds this footer')
    expect(text).toContain('HMRC (ref. XT32865)')
    expect(text).toContain('Registered address: 64a North Road, Ormesby')
  })

  it('offers no copy for the live editor without an MJML part', () => {
    expect(mountComponent().find('.clip').exists()).toBe(false)
  })

  it('copies the MJML with the footer, for the live editor', () => {
    const section =
      '<mj-section><mj-column><mj-text>Hi</mj-text></mj-column></mj-section>'
    const wrapper = mountComponent({ mjml: section })
    const copied = wrapper.find('.clip').attributes('data-value')
    expect(copied).toContain('<mjml>')
    expect(copied).toContain(section)
    expect(copied).toContain('HMRC (ref. XT32865)')
    expect(wrapper.html()).toContain('https://mjml.io/try-it-live')
  })
})
