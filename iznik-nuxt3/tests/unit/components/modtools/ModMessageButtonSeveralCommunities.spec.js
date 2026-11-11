import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ModMessageButton from '~/modtools/components/ModMessageButton.vue'

// A post pending on several of a moderator's communities is moderated from one card.
// ModMessage hands the buttons every community to act on as groupids, the one being looked
// at first, and Approve, Reject and Delete must carry them to the store. Without groupids a
// button acts on its one community exactly as before.

const message = {
  id: 42,
  subject: 'OFFER: garden chairs',
  fromuser: 99,
  heldby: null,
  groups: [
    { groupid: 10, collection: 'Pending', namedisplay: 'First Freegle' },
    { groupid: 11, collection: 'Pending', namedisplay: 'Second Freegle' },
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

const BOTH = [10, 11]

function mountButton(props = {}) {
  return mount(ModMessageButton, {
    props: {
      messageid: 42,
      groupid: 10,
      variant: 'primary',
      label: 'Button',
      icon: 'check',
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
          props: [
            'stdmsgid',
            'stdmsgaction',
            'messageid',
            'groupid',
            'groupids',
          ],
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

describe('ModMessageButton acting on several communities', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMessageStore.byId.mockReturnValue(message)
  })

  it('approves on every community it is given', async () => {
    const wrapper = mountButton({ approve: true, groupids: BOTH })
    await clickAndSettle(wrapper)

    expect(mockMessageStore.approve).toHaveBeenCalledWith(
      42,
      10,
      null,
      null,
      null,
      BOTH
    )
  })

  it('approves on its own community alone without groupids', async () => {
    const wrapper = mountButton({ approve: true })
    await clickAndSettle(wrapper)

    expect(mockMessageStore.approve).toHaveBeenCalledWith(42, 10)
  })

  it('deletes from every community it is given', async () => {
    const wrapper = mountButton({ delete: true, groupids: BOTH })
    await clickAndSettle(wrapper)
    await wrapper.findComponent({ name: 'ConfirmModal' }).vm.$emit('confirm')
    await wrapper.vm.$nextTick()

    expect(mockMessageStore.delete).toHaveBeenCalledWith({
      id: 42,
      groupid: 10,
      groupids: BOTH,
    })
  })

  it('takes a rippled-in post off every community, and says so, with no message', async () => {
    const wrapper = mountButton({
      reject: true,
      isHomeGroup: false,
      groupids: BOTH,
    })
    await clickAndSettle(wrapper)

    expect(wrapper.find('.confirm-modal').text()).toContain(
      '2 of your communities'
    )

    await wrapper.findComponent({ name: 'ConfirmModal' }).vm.$emit('confirm')
    await wrapper.vm.$nextTick()

    expect(mockMessageStore.reject).toHaveBeenCalledWith(
      42,
      10,
      '',
      null,
      '',
      BOTH
    )
  })

  it('hands the communities to the standard message modal', async () => {
    mockStdmsgStore.fetch.mockResolvedValue({ id: 8, action: 'Reject' })

    const wrapper = mountButton({ stdmsgid: 8, groupids: BOTH })
    await clickAndSettle(wrapper)

    const modal = wrapper.findComponent({ name: 'ModStdMessageModal' })
    expect(modal.exists()).toBe(true)
    expect(modal.props('groupids')).toEqual(BOTH)
  })
})
