import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import ModMessageButtons from '~/modtools/components/ModMessageButtons.vue'

const mockMessage = {
  id: 42,
  subject: 'OFFER: Sofa',
  type: 'Offer',
  fromuser: 99,
  groups: [{ groupid: 10, collection: 'Pending' }],
}

const mockMessageStore = {
  byId: vi.fn().mockReturnValue(mockMessage),
  fetch: vi.fn().mockResolvedValue(mockMessage),
  updateMT: vi.fn().mockResolvedValue({}),
}

// One standard message per action the Pending queue offers, so a test can tell "no
// standard messages" apart from "this config had none anyway".
const mockModConfig = {
  id: 7,
  stdmsgs: [
    { id: 1, title: 'Reject: no photo', action: 'Reject', rarelyused: 0 },
    { id: 2, title: 'Ask for more detail', action: 'Leave', rarelyused: 0 },
    { id: 3, title: 'Approve with note', action: 'Approve', rarelyused: 0 },
  ],
}

const mockModConfigStore = {
  configsById: { 7: mockModConfig },
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/modconfig', () => ({
  useModConfigStore: () => mockModConfigStore,
}))

vi.mock('~/composables/useStdMsgs', () => ({
  copyStdMsgs: (config) => config.stdmsgs,
  icon: () => 'envelope',
  variant: () => 'white',
}))

function mountButtons(props = {}) {
  return mount(ModMessageButtons, {
    props: {
      messageid: 42,
      modconfigid: 7,
      groupid: 10,
      ...props,
    },
    global: {
      stubs: {
        ModMessageButton: {
          // Render the props under test so assertions read the real bindings rather
          // than a rendered label that several buttons could share.
          template:
            '<button class="mod-message-button" :data-label="label" :data-no-member-message="String(noMemberMessage)" :data-stdmsgid="String(stdmsgid)" />',
          props: [
            'messageid',
            'groupid',
            'variant',
            'label',
            'icon',
            'approve',
            'reject',
            'delete',
            'hold',
            'release',
            'leave',
            'spam',
            'approveedits',
            'revertedits',
            'stdmsgid',
            'autosend',
            'isHomeGroup',
            'noMemberMessage',
          ],
        },
        SpinButton: { template: '<button class="spin-button" />' },
        OurToggle: { template: '<div class="our-toggle" />' },
        'b-button': { template: '<button><slot /></button>' },
        'client-only': { template: '<div><slot /></div>' },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

function labels(wrapper) {
  return wrapper
    .findAll('.mod-message-button')
    .map((b) => b.attributes('data-label'))
}

describe('ModMessageButtons', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMessageStore.byId.mockReturnValue(mockMessage)
  })

  it('offers the usual actions and standard messages for an ordinary post', () => {
    const wrapper = mountButtons()
    const seen = labels(wrapper)

    expect(seen).toContain('Approve')
    expect(seen).toContain('Reject')
    expect(seen).toContain('Delete')
    expect(seen).toContain('Ask for more detail')
    expect(wrapper.find('.our-toggle').exists()).toBe(true)

    // Guard the negative test below: it only means anything if standard messages do
    // normally render with a real stdmsgid here.
    const withStdmsg = wrapper
      .findAll('.mod-message-button')
      .filter((b) => /^\d+$/.test(b.attributes('data-stdmsgid') || ''))
    expect(withStdmsg.length).toBeGreaterThan(0)
  })

  describe('a post whose poster never joined Freegle', () => {
    function unaddressed() {
      return mountButtons({ modMessagingAllowed: false })
    }

    it('still offers Approve and Delete', () => {
      const seen = labels(unaddressed())
      expect(seen).toContain('Approve')
      expect(seen).toContain('Delete')
      expect(seen).toContain('Delete as Spam')
      expect(seen).toContain('Hold')
    })

    it('offers no standard messages at all - every one of them writes to the poster', () => {
      const wrapper = unaddressed()
      const seen = labels(wrapper)

      expect(seen).not.toContain('Ask for more detail')
      expect(seen).not.toContain('Reject: no photo')
      expect(seen).not.toContain('Approve with note')
      const withStdmsg = wrapper
        .findAll('.mod-message-button')
        .filter((b) => /^\d+$/.test(b.attributes('data-stdmsgid') || ''))
      expect(withStdmsg.length).toBe(0)
    })

    it('hides the autosend toggle, which now controls nothing', () => {
      expect(unaddressed().find('.our-toggle').exists()).toBe(false)
    })

    it('marks Reject as sending the poster nothing', () => {
      const reject = unaddressed()
        .findAll('.mod-message-button')
        .find((b) => b.attributes('data-label') === 'Reject')

      expect(reject.attributes('data-no-member-message')).toBe('true')
    })

    it('offers no Blank Reply on an approved post either', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        groups: [{ groupid: 10, collection: 'Approved' }],
      })
      const seen = labels(mountButtons({ modMessagingAllowed: false }))

      expect(seen).not.toContain('Blank Reply')
      expect(seen).toContain('Delete')
    })
  })

  it('offers Blank Reply on an approved ordinary post', () => {
    mockMessageStore.byId.mockReturnValue({
      ...mockMessage,
      groups: [{ groupid: 10, collection: 'Approved' }],
    })
    expect(labels(mountButtons())).toContain('Blank Reply')
  })
})
