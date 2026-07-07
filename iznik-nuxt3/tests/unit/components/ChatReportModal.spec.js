import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { modalBootstrapStubs } from '../mocks/bootstrap-stubs'
import ChatReportModal from '~/components/ChatReportModal.vue'

const mockHide = vi.fn()
vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({ modal: ref(null), hide: mockHide }),
}))

const mockOpenChatToMods = vi.fn()
const mockReport = vi.fn()
const mockReportNoGroup = vi.fn()
const mockCommonGroups = vi.fn()
vi.mock('~/stores/chat', () => ({
  useChatStore: () => ({
    openChatToMods: mockOpenChatToMods,
    report: mockReport,
    reportNoGroup: mockReportNoGroup,
    commonGroups: mockCommonGroups,
  }),
}))

// The shared b-form-select stub renders options from an `options` prop, but this
// component uses slotted <option> children, so override it with a stub that
// renders the default slot and drives v-model on change. b-spinner isn't in the
// shared stubs, so stub it too (an unresolved component triggers a Vue warning
// that the test harness treats as a failure).
const selectStub = {
  template:
    '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>',
  props: ['modelValue', 'disabled'],
}

async function createWrapper(props = {}) {
  const wrapper = mount(ChatReportModal, {
    props: { user: { displayname: 'Test User' }, chatid: 123, ...props },
    global: {
      stubs: {
        ...modalBootstrapStubs,
        'b-form-select': selectStub,
        'b-spinner': { template: '<span class="spinner" />' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('ChatReportModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockOpenChatToMods.mockResolvedValue(999)
    mockCommonGroups.mockResolvedValue([])
  })

  describe('common group exists', () => {
    beforeEach(() => {
      mockCommonGroups.mockResolvedValue([{ id: 1, namedisplay: 'Group 1' }])
    })

    it('shows the community selector', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.text()).toContain('Which community is this about?')
      expect(wrapper.find('[data-testid="group-select"]').exists()).toBe(true)
    })

    it('routes the report to the community mods', async () => {
      const wrapper = await createWrapper()
      await wrapper.find('[data-testid="reason-select"]').setValue('Spam')
      await wrapper.find('textarea').setValue('creepy')
      const sendBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Send Report'))
      await sendBtn.trigger('click')
      await flushPromises()
      expect(mockOpenChatToMods).toHaveBeenCalledWith(1)
      expect(mockReport).toHaveBeenCalled()
      expect(mockReportNoGroup).not.toHaveBeenCalled()
    })
  })

  describe('no common group (spam-team fallback)', () => {
    it('hides the selector and shows the central-volunteers note', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.text()).not.toContain('Which community is this about?')
      expect(wrapper.text()).toContain('central volunteers')
      expect(wrapper.find('[data-testid="group-select"]').exists()).toBe(false)
    })

    it('routes the report to the spam team with an optional empty comment', async () => {
      const wrapper = await createWrapper()
      await wrapper.find('[data-testid="reason-select"]').setValue('Spam')
      const sendBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Send Report'))
      await sendBtn.trigger('click')
      await flushPromises()
      expect(mockReportNoGroup).toHaveBeenCalledWith(123, 'Spam', '')
      expect(mockOpenChatToMods).not.toHaveBeenCalled()
    })

    it('does not send without a reason', async () => {
      const wrapper = await createWrapper()
      const sendBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Send Report'))
      await sendBtn.trigger('click')
      await flushPromises()
      expect(mockReportNoGroup).not.toHaveBeenCalled()
    })
  })

  describe('close action', () => {
    it('calls hide when Close is clicked', async () => {
      const wrapper = await createWrapper()
      const closeBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Close'))
      await closeBtn.trigger('click')
      expect(mockHide).toHaveBeenCalled()
    })
  })
})
