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
vi.mock('~/stores/chat', () => ({
  useChatStore: () => ({
    openChatToMods: mockOpenChatToMods,
    report: mockReport,
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
  })

  describe('rendering', () => {
    it('never asks which community, and promises an outcome', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('[data-testid="group-select"]').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('Which community')
      expect(wrapper.text()).not.toContain('volunteers')
      expect(wrapper.text()).toContain("let you know what happens")
    })

    it('shows the reason and comment fields', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('[data-testid="reason-select"]').exists()).toBe(true)
      expect(wrapper.find('textarea').exists()).toBe(true)
    })
  })

  describe('send', () => {
    it('opens a national mod chat and reports, with an optional empty comment', async () => {
      const wrapper = await createWrapper()
      await wrapper.find('[data-testid="reason-select"]').setValue('Spam')
      const sendBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Send Report'))
      await sendBtn.trigger('click')
      await flushPromises()
      expect(mockOpenChatToMods).toHaveBeenCalledWith()
      expect(mockReport).toHaveBeenCalledWith(999, 'Spam', '', 123)
    })

    it('passes the comment when provided', async () => {
      const wrapper = await createWrapper()
      await wrapper.find('[data-testid="reason-select"]').setValue('Other')
      await wrapper.find('textarea').setValue('creepy')
      const sendBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Send Report'))
      await sendBtn.trigger('click')
      await flushPromises()
      expect(mockReport).toHaveBeenCalledWith(999, 'Other', 'creepy', 123)
    })

    it('does not send without a reason', async () => {
      const wrapper = await createWrapper()
      const sendBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Send Report'))
      await sendBtn.trigger('click')
      await flushPromises()
      expect(mockOpenChatToMods).not.toHaveBeenCalled()
      expect(mockReport).not.toHaveBeenCalled()
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
