import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ModHelpApproved from '~/modtools/components/ModHelpApproved.vue'

const mockShowHelp = ref(true)
const mockToggleHelp = vi.fn()
vi.mock('~/composables/useHelpBox', () => ({
  useHelpBox: () => ({
    hide: vi.fn(),
    show: vi.fn(),
    showHelp: mockShowHelp,
    toggleHelp: mockToggleHelp,
  }),
}))

const stubs = {
  NoticeMessage: {
    template: '<div class="notice-message"><slot /></div>',
    props: ['variant'],
  },
  'b-button': {
    template: "<button @click=\"$emit('click')\"><slot /></button>",
  },
}

describe('ModHelpApproved', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockShowHelp.value = true
  })

  it('explains that Approved is every live post and Checked/Trusted are subsets', () => {
    const wrapper = mount(ModHelpApproved, { global: { stubs } })
    expect(wrapper.text()).toContain('Every live post')
    expect(wrapper.text()).toContain('focused')
    expect(wrapper.text()).toContain('oversight subsets')
  })

  it('collapses to a Help button when hidden', () => {
    mockShowHelp.value = false
    const wrapper = mount(ModHelpApproved, { global: { stubs } })
    expect(wrapper.find('.notice-message').exists()).toBe(false)
    expect(wrapper.text()).toContain('Help')
  })

  it('toggles help when the button is clicked', async () => {
    const wrapper = mount(ModHelpApproved, { global: { stubs } })
    await wrapper.find('button').trigger('click')
    expect(mockToggleHelp).toHaveBeenCalled()
  })
})
