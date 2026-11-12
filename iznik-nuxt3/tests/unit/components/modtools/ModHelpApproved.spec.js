import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref, toValue } from 'vue'
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

describe('ModHelpApproved', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockShowHelp.value = true
    mockInTrial.value = true
  })

  it('explains that Approved is every live post and Check is a subset', () => {
    const wrapper = mount(ModHelpApproved, { global: { stubs } })
    expect(wrapper.text()).toContain('Every live post')
    expect(wrapper.text()).toContain('Check holds')
  })

  it('says Check is for communities in the trial', () => {
    const wrapper = mount(ModHelpApproved, { global: { stubs } })
    expect(wrapper.text()).toContain('On communities in the trial')
  })

  it('does not use a dash as punctuation', () => {
    const wrapper = mount(ModHelpApproved, { global: { stubs } })
    expect(wrapper.text()).not.toMatch(/[\u2013\u2014]/)
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

  it('shows nothing, not even the Help link, outside the trial', () => {
    mockInTrial.value = false
    const wrapper = mount(ModHelpApproved, { global: { stubs } })
    expect(wrapper.find('.notice-message').exists()).toBe(false)
    expect(wrapper.find('button').exists()).toBe(false)
  })

  // A moderator of a trial community and a neighbouring one outside it must not see the
  // post-moderation help on the neighbour's Approved page.
  it('asks about the community the page is filtered to', () => {
    mount(ModHelpApproved, { props: { groupid: 42 }, global: { stubs } })
    expect(toValue(mockUseAutoapproveTrial.mock.calls.at(-1)[0])).toBe(42)
    mount(ModHelpApproved, { global: { stubs } })
    expect(toValue(mockUseAutoapproveTrial.mock.calls.at(-1)[0])).toBe(0)
  })
})
