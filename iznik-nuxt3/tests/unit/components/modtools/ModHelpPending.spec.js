import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ModHelpPending from '~/modtools/components/ModHelpPending.vue'

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

describe('ModHelpPending', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockShowHelp.value = true
  })

  it('explains the pending queue and the 10-minute guarantee when shown', () => {
    const wrapper = mount(ModHelpPending, { global: { stubs } })
    expect(wrapper.text()).toContain('Posts waiting to go live')
    expect(wrapper.text()).toContain('at least 10 minutes')
  })

  it('collapses to a Help button when hidden', () => {
    mockShowHelp.value = false
    const wrapper = mount(ModHelpPending, { global: { stubs } })
    expect(wrapper.find('.notice-message').exists()).toBe(false)
    expect(wrapper.text()).toContain('Help')
  })

  it('toggles help when the button is clicked', async () => {
    const wrapper = mount(ModHelpPending, { global: { stubs } })
    await wrapper.find('button').trigger('click')
    expect(mockToggleHelp).toHaveBeenCalled()
  })
})
