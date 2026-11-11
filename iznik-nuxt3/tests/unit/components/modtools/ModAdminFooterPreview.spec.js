import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ModAdminFooterPreview from '~/modtools/components/ModAdminFooterPreview.vue'

const recently = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString()

const mockGroupStore = {
  get: vi.fn(),
  fetch: vi.fn().mockResolvedValue({}),
}

vi.mock('~/stores/group', () => ({
  useGroupStore: () => mockGroupStore,
}))

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
  beforeEach(() => {
    mockGroupStore.get.mockReset()
    mockGroupStore.fetch.mockClear()
  })

  it('names the community volunteers the email will name', () => {
    mockGroupStore.get.mockReturnValue({
      id: 5,
      showmods: [
        { displayname: 'Michael Jones', lastaccess: recently },
        { displayname: 'Old Timer', lastaccess: '2020-01-01T00:00:00Z' },
      ],
    })
    const wrapper = mountComponent({ groupid: 5, mjml: '' })
    expect(wrapper.find('.volunteers').text()).toBe(
      'Your local volunteer is Michael.'
    )
    expect(wrapper.find('.volunteers-help').text()).toContain(
      'Show me as a volunteer'
    )
    expect(wrapper.find('.clip').attributes('data-value')).toContain(
      'Your local volunteer is Michael.'
    )
    expect(mockGroupStore.fetch).toHaveBeenCalledWith(5, true)
  })

  it('says when nobody will be named', () => {
    mockGroupStore.get.mockReturnValue({ id: 5, showmods: null })
    const wrapper = mountComponent({ groupid: 5 })
    expect(wrapper.find('.volunteers').text()).toBe(
      'No local volunteers will be named.'
    )
  })

  it("explains a suggested ADMIN names each community's own", () => {
    const wrapper = mountComponent({ groupid: -2 })
    expect(wrapper.find('.volunteers').text()).toContain(
      "Each community's own volunteers"
    )
    expect(mockGroupStore.fetch).not.toHaveBeenCalled()
  })

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
