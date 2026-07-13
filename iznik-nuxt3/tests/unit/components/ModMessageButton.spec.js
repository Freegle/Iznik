import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import ModMessageButton from '~/modtools/components/ModMessageButton.vue'

const mockMessage = {
  id: 42,
  subject: 'WANTED: Dog',
  fromuser: 99,
  heldby: null,
  groups: [{ groupid: 10, collection: 'Pending' }],
}

const mockMessageStore = {
  byId: vi.fn().mockReturnValue(mockMessage),
  fetch: vi.fn().mockResolvedValue(mockMessage),
  approve: vi.fn().mockResolvedValue({}),
  delete: vi.fn().mockResolvedValue({}),
  spam: vi.fn().mockResolvedValue({}),
  hold: vi.fn().mockResolvedValue({}),
  release: vi.fn().mockResolvedValue({}),
  approveedits: vi.fn().mockResolvedValue({}),
  revertedits: vi.fn().mockResolvedValue({}),
}

const mockUserStore = {
  fetch: vi.fn().mockResolvedValue({}),
  byId: vi.fn(),
}

const mockStdmsgStore = {
  fetch: vi.fn().mockResolvedValue({}),
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/stores/stdmsg', () => ({
  useStdmsgStore: () => mockStdmsgStore,
}))

vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({
    checkWorkDeferGetMessages: vi.fn(),
  }),
}))

function mountButton(props = {}) {
  return mount(ModMessageButton, {
    props: {
      messageid: 42,
      variant: 'primary',
      label: 'Approve',
      icon: 'check',
      approve: true,
      ...props,
    },
    global: {
      stubs: {
        SpinButton: {
          template: '<button @click="$emit(\'handle\')"><slot /></button>',
          emits: ['handle'],
        },
        ConfirmModal: { template: '<div />' },
        ModStdMessageModal: { template: '<div />' },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

describe('ModMessageButton', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMessageStore.byId.mockReturnValue(mockMessage)
  })

  it('calls userStore.fetch with fromuser id after approve', async () => {
    const wrapper = mountButton({ approve: true })

    await wrapper.find('button').trigger('click')
    await wrapper.vm.$nextTick()

    expect(mockMessageStore.approve).toHaveBeenCalledWith(42, 10)
    expect(mockUserStore.fetch).toHaveBeenCalledWith(99, true)
  })

  it('does not call userStore.fetch if message has no fromuser', async () => {
    mockMessageStore.byId.mockReturnValue({ ...mockMessage, fromuser: null })
    const wrapper = mountButton({ approve: true })

    await wrapper.find('button').trigger('click')
    await wrapper.vm.$nextTick()

    expect(mockUserStore.fetch).not.toHaveBeenCalled()
  })

  describe('confirmButton (Discourse 9904 follow-up)', () => {
    // Other call site: confirmButton used to read the legacy message-level heldby,
    // set whenever ANY group holds ANY copy of a rippled post - so it would demand
    // an extra confirmation for an action on a copy that isn't held at all on the
    // group this button acts on, just because a different group happens to be
    // holding its own copy.
    it('does not require confirmation when a DIFFERENT group holds its own copy', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        heldby: { id: 1 }, // legacy message-level field, set by the OTHER group's hold
        groups: [
          { groupid: 10, collection: 'Pending', heldby: null },
          { groupid: 20, collection: 'Pending', heldby: { id: 1 } },
        ],
      })
      const wrapper = mountButton({ approve: true, groupid: 10 })
      expect(wrapper.vm.confirmButton).toBe(false)
    })

    it('still requires confirmation when THIS group is the one actually held', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        heldby: { id: 1 },
        groups: [{ groupid: 10, collection: 'Pending', heldby: { id: 1 } }],
      })
      const wrapper = mountButton({ approve: true, groupid: 10 })
      expect(wrapper.vm.confirmButton).toBe(true)
    })
  })
})
