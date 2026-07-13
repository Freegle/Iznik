import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { useMessageStore } from '~/stores/message'
import ModMessageButtons from '~/modtools/components/ModMessageButtons.vue'
import ModMessageButton from '~/modtools/components/ModMessageButton.vue'

// Blocker: the read path (heldByThisGroup) was scoped to the per-group
// messages_groups.heldby, but nothing proved the WRITE path (clicking Hold/Release)
// keeps that per-group field in sync without a full page refetch. This uses the REAL
// message store (not a hand-rolled mock) so messageStore.hold()'s existing
// fetchMT()-then-replace behaviour genuinely drives the UI - only the API layer and
// low-level UI atoms are mocked.

const mockHold = vi.fn()
const mockRelease = vi.fn()
const mockFetchMT = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    message: {
      hold: mockHold,
      release: mockRelease,
      fetchMT: mockFetchMT,
    },
  }),
}))

vi.mock('~/stores/modconfig', () => ({
  useModConfigStore: () => ({ configsById: {} }),
}))

vi.mock('~/stores/stdmsg', () => ({
  useStdmsgStore: () => ({ fetch: vi.fn().mockResolvedValue({}) }),
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ fetch: vi.fn().mockResolvedValue({}) }),
}))

vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({ checkWorkDeferGetMessages: vi.fn() }),
}))

vi.mock('~/composables/useStdMsgs', () => ({
  copyStdMsgs: () => [],
  icon: () => 'check',
  variant: () => 'primary',
}))

const stubs = {
  SpinButton: {
    template:
      '<button class="spin-button" @click="$emit(\'handle\', () => {})"><slot />{{ label }}</button>',
    props: ['variant', 'iconName', 'label', 'confirm', 'flex', 'disabled'],
  },
  ConfirmModal: { template: '<div />' },
  ModStdMessageModal: { template: '<div />' },
  OurToggle: { template: '<div class="our-toggle" />', props: ['modelValue'] },
  'b-button': {
    template:
      '<button class="b-button" @click="$emit(\'click\')"><slot /></button>',
  },
  'v-icon': { template: '<i />', props: ['icon'] },
  'client-only': { template: '<div><slot /></div>' },
}

function mountComponent(groupid) {
  return mount(ModMessageButtons, {
    props: { messageid: 1001, groupid },
    global: {
      // Nuxt auto-imports ModMessageButton in the app; outside that build pipeline
      // it must be registered explicitly so the real (unstubbed) component is used.
      components: { ModMessageButton },
      stubs,
    },
  })
}

// ModMessageButton itself is NOT stubbed (only its SpinButton child is), so the real
// holdIt()/releaseIt() handlers run against the real message store. There's no CSS
// hook for "Hold" vs "Release" on the real component, so find by the SpinButton's
// rendered label text instead.
function spinButtonLabelled(wrapper, label) {
  return wrapper
    .findAll('.spin-button')
    .find((btn) => btn.text().trim() === label)
}

describe('ModMessageButtons - hold/release write path keeps groups[].heldby in sync', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('clicking Hold flips the button set to Release once the store refetch resolves', async () => {
    const store = useMessageStore()
    store.list[1001] = {
      id: 1001,
      subject: 'OFFER: Test',
      type: 'Offer',
      outcomes: [],
      groups: [{ groupid: 456, collection: 'Pending', heldby: null }],
    }

    mockHold.mockResolvedValue({})
    mockFetchMT.mockResolvedValue({
      id: 1001,
      subject: 'OFFER: Test',
      type: 'Offer',
      outcomes: [],
      groups: [{ groupid: 456, collection: 'Pending', heldby: 999 }],
    })

    const wrapper = mountComponent(456)

    expect(spinButtonLabelled(wrapper, 'Hold')).toBeTruthy()
    expect(spinButtonLabelled(wrapper, 'Release')).toBeFalsy()

    await spinButtonLabelled(wrapper, 'Hold').trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 0))
    await wrapper.vm.$nextTick()

    expect(mockHold).toHaveBeenCalledWith(1001, 456)
    expect(mockFetchMT).toHaveBeenCalledWith({ id: 1001 })
    expect(spinButtonLabelled(wrapper, 'Hold')).toBeFalsy()
    expect(spinButtonLabelled(wrapper, 'Release')).toBeTruthy()
  })

  it('clicking Release flips the button set back to Hold once the store refetch resolves', async () => {
    const store = useMessageStore()
    store.list[1001] = {
      id: 1001,
      subject: 'OFFER: Test',
      type: 'Offer',
      outcomes: [],
      groups: [{ groupid: 456, collection: 'Pending', heldby: 999 }],
    }

    mockRelease.mockResolvedValue({})
    mockFetchMT.mockResolvedValue({
      id: 1001,
      subject: 'OFFER: Test',
      type: 'Offer',
      outcomes: [],
      groups: [{ groupid: 456, collection: 'Pending', heldby: null }],
    })

    const wrapper = mountComponent(456)

    expect(spinButtonLabelled(wrapper, 'Release')).toBeTruthy()
    expect(spinButtonLabelled(wrapper, 'Hold')).toBeFalsy()

    await spinButtonLabelled(wrapper, 'Release').trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 0))
    await wrapper.vm.$nextTick()

    expect(mockRelease).toHaveBeenCalledWith(1001, 456)
    expect(mockFetchMT).toHaveBeenCalledWith({ id: 1001 })
    expect(spinButtonLabelled(wrapper, 'Release')).toBeFalsy()
    expect(spinButtonLabelled(wrapper, 'Hold')).toBeTruthy()
  })
})
