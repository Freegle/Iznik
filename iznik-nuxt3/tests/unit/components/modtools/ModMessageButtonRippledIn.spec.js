import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ModMessageButton from '~/modtools/components/ModMessageButton.vue'

// On a rippled-in copy, a standard message that REMOVES the post must remove it and say
// nothing to the freegler. Only Reject was treated this way, so the shared "Animals
// (Delete)" message - action "Delete Approved Message" - opened the compose modal and
// mailed a Potteries poster from Walsall (Discourse 10102).

const message = {
  id: 42,
  subject: 'OFFER: brown rabbit',
  fromuser: 99,
  heldby: null,
  groups: [
    { groupid: 10, collection: 'Approved', namedisplay: 'Walsall Freegle' },
  ],
}

const mockMessageStore = {
  byId: vi.fn().mockReturnValue(message),
  fetch: vi.fn().mockResolvedValue(message),
  approve: vi.fn().mockResolvedValue({}),
  delete: vi.fn().mockResolvedValue({}),
  reject: vi.fn().mockResolvedValue({}),
  spam: vi.fn().mockResolvedValue({}),
  hold: vi.fn().mockResolvedValue({}),
  release: vi.fn().mockResolvedValue({}),
  approveedits: vi.fn().mockResolvedValue({}),
  revertedits: vi.fn().mockResolvedValue({}),
}

const mockStdmsgStore = { fetch: vi.fn() }

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ fetch: vi.fn(), byId: vi.fn() }),
}))

vi.mock('~/stores/stdmsg', () => ({
  useStdmsgStore: () => mockStdmsgStore,
}))

vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({ checkWorkDeferGetMessages: vi.fn() }),
}))

function mountButton(props = {}) {
  return mount(ModMessageButton, {
    props: {
      messageid: 42,
      groupid: 10,
      variant: 'danger',
      label: 'Animals (Delete)',
      icon: 'trash-alt',
      ...props,
    },
    global: {
      stubs: {
        SpinButton: {
          template: '<button @click="$emit(\'handle\')"><slot /></button>',
          emits: ['handle'],
        },
        ConfirmModal: {
          name: 'ConfirmModal',
          template: '<div class="confirm-modal"><slot /></div>',
          methods: { show: () => {} },
        },
        ModStdMessageModal: {
          name: 'ModStdMessageModal',
          template: '<div class="std-modal" />',
          // The component calls these on the ref once the modal is shown.
          methods: { show() {}, fillin() {} },
        },
        NoticeMessage: { template: '<div><slot /></div>', props: ['variant'] },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

async function clickAndSettle(wrapper) {
  await wrapper.find('button').trigger('click')
  await new Promise((resolve) => setTimeout(resolve, 0))
  await wrapper.vm.$nextTick()
}

describe('ModMessageButton on a rippled-in copy', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMessageStore.byId.mockReturnValue(message)
  })

  it('confirms a silent removal for a delete standard message', async () => {
    mockStdmsgStore.fetch.mockResolvedValue({
      id: 7,
      action: 'Delete Approved Message',
    })

    const wrapper = mountButton({ stdmsgid: 7, isHomeGroup: false })
    await clickAndSettle(wrapper)

    expect(wrapper.find('.confirm-modal').exists()).toBe(true)
    expect(wrapper.find('.std-modal').exists()).toBe(false)
  })

  it('deletes only this group and sends no message when confirmed', async () => {
    mockStdmsgStore.fetch.mockResolvedValue({
      id: 7,
      action: 'Delete Approved Message',
    })

    const wrapper = mountButton({ stdmsgid: 7, isHomeGroup: false })
    await clickAndSettle(wrapper)
    await wrapper.findComponent({ name: 'ConfirmModal' }).vm.$emit('confirm')
    await wrapper.vm.$nextTick()

    expect(mockMessageStore.delete).toHaveBeenCalledWith({
      id: 42,
      groupid: 10,
    })
    expect(mockMessageStore.reject).not.toHaveBeenCalled()
  })

  it('still composes a message for a delete standard message on the home community', async () => {
    mockStdmsgStore.fetch.mockResolvedValue({
      id: 7,
      action: 'Delete Approved Message',
    })

    const wrapper = mountButton({ stdmsgid: 7, isHomeGroup: true })
    await clickAndSettle(wrapper)

    expect(wrapper.find('.std-modal').exists()).toBe(true)
  })

  it('keeps confirming a silent removal for a reject standard message', async () => {
    mockStdmsgStore.fetch.mockResolvedValue({ id: 8, action: 'Reject' })

    const wrapper = mountButton({ stdmsgid: 8, isHomeGroup: false })
    await clickAndSettle(wrapper)
    await wrapper.findComponent({ name: 'ConfirmModal' }).vm.$emit('confirm')
    await wrapper.vm.$nextTick()

    expect(mockMessageStore.reject).toHaveBeenCalledWith(42, 10, '', null, '')
    expect(mockMessageStore.delete).not.toHaveBeenCalled()
  })
})
