import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModAlertReplyGate from '~/modtools/components/ModAlertReplyGate.vue'

const mockAlertStore = {
  pending: [],
  fetchPending: vi.fn(),
  reply: vi.fn(),
  snooze: vi.fn(),
}

vi.mock('~/stores/alert', () => ({
  useAlertStore: () => mockAlertStore,
}))

const pendingAlert = {
  id: 7,
  groupname: 'Testville Freegle',
  subject: 'A member needs help',
  text: 'Please phone them',
  html: '<p>Please <b>phone</b> them</p>',
  quoted: 'Dear Freegle, the member said this',
  snoozeavailable: true,
}

describe('ModAlertReplyGate', () => {
  function mountGate() {
    return mount(ModAlertReplyGate, {
      global: {
        stubs: {
          // Render the modal inline and expose how it is locked.
          'b-modal': {
            template:
              '<div class="modal-stub" :data-no-close-backdrop="String(noCloseOnBackdrop)" :data-no-esc="String(noCloseOnEsc)"><h4>{{ title }}</h4><slot /></div>',
            // Booleans, as on the real modal, so a bare attribute reads as true.
            props: {
              modelValue: Boolean,
              title: String,
              size: String,
              noStacking: Boolean,
              noCloseOnBackdrop: Boolean,
              noCloseOnEsc: Boolean,
              hideHeaderClose: Boolean,
              hideFooter: Boolean,
            },
          },
          'b-form-textarea': {
            template:
              '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue', 'rows', 'placeholder'],
          },
          'b-button': {
            template:
              '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'disabled'],
          },
          NoticeMessage: {
            template: '<div class="notice"><slot /></div>',
            props: ['variant'],
          },
        },
      },
    })
  }

  const buttonWith = (wrapper, text) =>
    wrapper.findAll('button').find((b) => b.text().includes(text))

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    mockAlertStore.pending = []
    mockAlertStore.fetchPending.mockResolvedValue([])
    mockAlertStore.reply.mockResolvedValue()
    mockAlertStore.snooze.mockResolvedValue()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows nothing when there is nothing to reply to', async () => {
    const wrapper = mountGate()
    await flushPromises()

    expect(mockAlertStore.fetchPending).toHaveBeenCalledTimes(1)
    expect(wrapper.find('.modal-stub').exists()).toBe(false)
  })

  it('blocks with a modal that cannot be dismissed, showing the message and the original email', async () => {
    mockAlertStore.pending = [pendingAlert]
    const wrapper = mountGate()
    await flushPromises()

    const modal = wrapper.find('.modal-stub')
    expect(modal.exists()).toBe(true)
    expect(modal.attributes('data-no-close-backdrop')).toBe('true')
    expect(modal.attributes('data-no-esc')).toBe('true')
    expect(wrapper.text()).toContain(
      'Testville Freegle volunteers need a reply'
    )
    expect(wrapper.text()).toContain('A member needs help')
    expect(wrapper.text()).toContain('the member said this')
    expect(wrapper.find('.alert-body b').text()).toBe('phone')
  })

  it('will not send an empty reply', async () => {
    mockAlertStore.pending = [pendingAlert]
    const wrapper = mountGate()
    await flushPromises()

    expect(
      buttonWith(wrapper, 'Send reply').attributes('disabled')
    ).toBeDefined()
    await wrapper.find('textarea').setValue('   ')
    expect(
      buttonWith(wrapper, 'Send reply').attributes('disabled')
    ).toBeDefined()
  })

  it('sends the reply through the store', async () => {
    mockAlertStore.pending = [pendingAlert]
    const wrapper = mountGate()
    await flushPromises()

    await wrapper.find('textarea').setValue('  I will phone them  ')
    await buttonWith(wrapper, 'Send reply').trigger('click')
    await flushPromises()

    expect(mockAlertStore.reply).toHaveBeenCalledWith(7, 'I will phone them')
  })

  it('says so when the reply did not send', async () => {
    mockAlertStore.pending = [pendingAlert]
    mockAlertStore.reply.mockRejectedValue(new Error('no'))
    const wrapper = mountGate()
    await flushPromises()

    await wrapper.find('textarea').setValue('Hello')
    await buttonWith(wrapper, 'Send reply').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('did not send')
  })

  it('offers the 30 minute sleep once', async () => {
    mockAlertStore.pending = [pendingAlert]
    const wrapper = mountGate()
    await flushPromises()

    await buttonWith(wrapper, 'Sleep for 30 minutes').trigger('click')
    expect(mockAlertStore.snooze).toHaveBeenCalledWith(7)
  })

  it('does not offer the sleep after it has been used', async () => {
    mockAlertStore.pending = [{ ...pendingAlert, snoozeavailable: false }]
    const wrapper = mountGate()
    await flushPromises()

    expect(buttonWith(wrapper, 'Sleep for 30 minutes')).toBeUndefined()
    expect(wrapper.text()).toContain('used your 30 minutes')
  })

  it('asks again every minute, so a sleep ends and a cancel releases', async () => {
    const wrapper = mountGate()
    await flushPromises()
    expect(mockAlertStore.fetchPending).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(60000)
    expect(mockAlertStore.fetchPending).toHaveBeenCalledTimes(2)

    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(120000)
    expect(mockAlertStore.fetchPending).toHaveBeenCalledTimes(2)
  })

  it('never blocks a moderator because the check failed', async () => {
    mockAlertStore.fetchPending.mockRejectedValue(new Error('offline'))
    const wrapper = mountGate()
    await flushPromises()

    expect(wrapper.find('.modal-stub').exists()).toBe(false)
  })
})
