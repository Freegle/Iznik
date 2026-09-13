import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ModHelpCheck from '~/modtools/components/ModHelpCheck.vue'

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
    template: '<button @click="$emit(\'click\')"><slot /></button>',
  },
}

describe('ModHelpCheck', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockShowHelp.value = true
  })

  it('explains the Check oversight queue with accurate wording', () => {
    const wrapper = mount(ModHelpCheck, { global: { stubs } })
    expect(wrapper.text()).toContain(
      'went live by themselves from auto-moderated members'
    )
    expect(wrapper.text()).toContain('two microvolunteers have approved')
    expect(wrapper.text()).toContain('drop off this queue')
    // Must NOT use the inaccurate "treated as checked" phrasing (review finding D2).
    expect(wrapper.text()).not.toContain('treated as checked')
  })

  it('collapses to a Help button when hidden', () => {
    mockShowHelp.value = false
    const wrapper = mount(ModHelpCheck, { global: { stubs } })
    expect(wrapper.find('.notice-message').exists()).toBe(false)
    expect(wrapper.text()).toContain('Help')
  })

  it('toggles help when the button is clicked', async () => {
    const wrapper = mount(ModHelpCheck, { global: { stubs } })
    await wrapper.find('button').trigger('click')
    expect(mockToggleHelp).toHaveBeenCalled()
  })
})
