import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref, toValue } from 'vue'
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

const mockInTrial = ref(true)
const mockUseAutoapproveTrial = vi.fn(() => ({ inTrial: mockInTrial }))
vi.mock('~/modtools/composables/useAutoapproveTrial', () => ({
  useAutoapproveTrial: (groupid) => mockUseAutoapproveTrial(groupid),
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

describe('ModHelpPending', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockShowHelp.value = true
    mockInTrial.value = true
  })

  it('explains the pending queue and the 10-minute guarantee when shown', () => {
    const wrapper = mount(ModHelpPending, { global: { stubs } })
    expect(wrapper.text()).toContain('Posts waiting to go live')
    expect(wrapper.text()).toContain('at least 10 more minutes')
  })

  // Where the help is shown there are several communities, not all in the trial. It has to
  // say which posts publish themselves rather than read as true of every post on the page.
  it('says it is about communities in the trial', () => {
    const wrapper = mount(ModHelpPending, { global: { stubs } })
    expect(wrapper.text()).toContain('On communities in the trial')
  })

  it('does not use a dash as punctuation', () => {
    const wrapper = mount(ModHelpPending, { global: { stubs } })
    expect(wrapper.text()).not.toMatch(/[\u2013\u2014]/)
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

  it('shows nothing, not even the Help link, outside the trial', () => {
    mockInTrial.value = false
    const wrapper = mount(ModHelpPending, { global: { stubs } })
    expect(wrapper.find('.notice-message').exists()).toBe(false)
    expect(wrapper.find('button').exists()).toBe(false)
  })

  // A moderator of a trial community and a neighbouring one outside it must not be told,
  // on the neighbour's queue, that posts there publish themselves.
  it('asks about the community the page is filtered to', () => {
    mount(ModHelpPending, { props: { groupid: 42 }, global: { stubs } })
    expect(toValue(mockUseAutoapproveTrial.mock.calls.at(-1)[0])).toBe(42)
    mount(ModHelpPending, { global: { stubs } })
    expect(toValue(mockUseAutoapproveTrial.mock.calls.at(-1)[0])).toBe(0)
  })
})
