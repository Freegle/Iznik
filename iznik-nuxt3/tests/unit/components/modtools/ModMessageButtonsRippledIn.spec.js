import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ModMessageButtons from '~/modtools/components/ModMessageButtons.vue'

// A moderator of a group a post rippled INTO administers their own copy of it. Every
// button that exists only to write to the freegler belongs to the home community, so it
// must not be on screen here at all (Discourse 10102).

const { mockMessageStore, mockModConfigStore } = vi.hoisted(() => ({
  mockMessageStore: {
    byId: vi.fn(),
    fetch: vi.fn().mockResolvedValue(),
    updateMT: vi.fn().mockResolvedValue(),
  },
  mockModConfigStore: { configsById: {} },
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/modconfig', () => ({
  useModConfigStore: () => mockModConfigStore,
}))

vi.mock('~/composables/useStdMsgs', () => ({
  copyStdMsgs: (config) => config.stdmsgs || [],
  icon: () => 'check',
  variant: () => 'primary',
}))

const stubs = {
  ModMessageButton: {
    name: 'ModMessageButton',
    template: '<button class="mmb">{{ label }}</button>',
    props: [
      'messageid',
      'variant',
      'icon',
      'label',
      'stdmsgid',
      'autosend',
      'groupid',
      'isHomeGroup',
      'approve',
      'reject',
      'delete',
      'hold',
      'release',
      'spam',
      'approveedits',
      'revertedits',
      'leave',
    ],
  },
  SpinButton: {
    template: '<button class="spin-button">{{ label }}</button>',
    props: ['variant', 'iconName', 'label', 'confirm', 'flex'],
  },
  OurToggle: {
    template: '<div class="our-toggle" />',
    props: [
      'modelValue',
      'height',
      'width',
      'fontSize',
      'sync',
      'labels',
      'variant',
    ],
  },
  'b-button': { template: '<button><slot /></button>', props: ['variant'] },
  'v-icon': { template: '<i />', props: ['icon'] },
  'client-only': { template: '<div><slot /></div>' },
}

const STDMSGS = [
  { id: 1, title: 'Approve Message', action: 'Approve', rarelyused: 0 },
  { id: 2, title: 'Reject Message', action: 'Reject', rarelyused: 0 },
  { id: 3, title: 'Leave Message', action: 'Leave', rarelyused: 0 },
  {
    id: 4,
    title: 'Animals (Reply)',
    action: 'Leave Approved Message',
    rarelyused: 0,
  },
  {
    id: 5,
    title: 'Animals (Delete)',
    action: 'Delete Approved Message',
    rarelyused: 0,
  },
  { id: 6, title: 'Edit Message', action: 'Edit', rarelyused: 0 },
]

function mountButtons({ collection = 'Approved', isHomeGroup = false } = {}) {
  const message = {
    id: 123,
    subject: 'OFFER: brown rabbit',
    type: 'Offer',
    heldby: null,
    groups: [{ groupid: 456, collection }],
    outcomes: [],
  }

  mockMessageStore.byId.mockImplementation((id) =>
    id === 123 ? message : null
  )
  mockModConfigStore.configsById = { 1: { id: 1, stdmsgs: STDMSGS } }

  return mount(ModMessageButtons, {
    props: { messageid: 123, modconfigid: 1, groupid: 456, isHomeGroup },
    global: { stubs },
  })
}

function labels(wrapper) {
  return wrapper
    .findAllComponents({ name: 'ModMessageButton' })
    .map((b) => b.props('label'))
}

describe('ModMessageButtons on a rippled-in copy', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('offers no Blank Reply on an approved rippled-in copy', () => {
    expect(labels(mountButtons())).not.toContain('Blank Reply')
  })

  it('still offers Blank Reply on the home community', () => {
    expect(labels(mountButtons({ isHomeGroup: true }))).toContain('Blank Reply')
  })

  it('offers no message-only standard message on an approved rippled-in copy', () => {
    expect(labels(mountButtons())).not.toContain('Animals (Reply)')
  })

  it('offers no message-only standard message on a pending rippled-in copy', () => {
    expect(labels(mountButtons({ collection: 'Pending' }))).not.toContain(
      'Leave Message'
    )
  })

  it('still offers the delete standard message, which removes their own copy', () => {
    expect(labels(mountButtons())).toContain('Animals (Delete)')
  })

  it('still offers message-only standard messages on the home community', () => {
    expect(labels(mountButtons({ isHomeGroup: true }))).toContain(
      'Animals (Reply)'
    )
  })
})
