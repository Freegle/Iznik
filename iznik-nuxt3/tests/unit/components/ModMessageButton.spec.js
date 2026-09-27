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
        ModStdMessageModal: {
          // Real methods: ModMessageButton calls show()/fillin() through the ref, and a
          // bare <div /> stub turns that into an unhandled TypeError.
          template: '<div />',
          methods: { show() {}, fillin() {} },
        },
        NoticeMessage: { template: '<div><slot /></div>', props: ['variant'] },
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

  // A post whose poster never joined Freegle is on its home group, so Delete and Delete as
  // Spam must stay - but there is still nobody to send a rejection message to.
  describe('when the poster cannot be written to', () => {
    it('confirms a silent removal instead of composing a rejection', async () => {
      const wrapper = mountButton({
        approve: false,
        reject: true,
        noMemberMessage: true,
        label: 'Reject',
        variant: 'warning',
        icon: 'times',
      })

      await wrapper.find('button').trigger('click')
      await wrapper.vm.$nextTick()

      expect(wrapper.vm.showRejectNoMsgModal).toBe(true)
      expect(wrapper.vm.showStdMsgModal).toBe(false)
    })

    it('composes a rejection as usual when the poster can be written to', async () => {
      const wrapper = mountButton({
        approve: false,
        reject: true,
        label: 'Reject',
        variant: 'warning',
        icon: 'times',
      })

      await wrapper.find('button').trigger('click')
      await wrapper.vm.$nextTick()

      expect(wrapper.vm.showRejectNoMsgModal).toBe(false)
      expect(wrapper.vm.showStdMsgModal).toBe(true)
    })
  })
})
