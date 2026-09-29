import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'
import ModSupportLockdown from '~/modtools/components/ModSupportLockdown.vue'

const mockFetchMod = vi.fn()
const mockFetchStats = vi.fn()
const mockFetchHistory = vi.fn()
const mockPatch = vi.fn()

const store = reactive({
  active: false,
  incidentid: null,
  surfaces: {},
  reason: null,
  notice: null,
  startedat: null,
  startedby: null,
  startedbyname: null,
  stats: null,
  history: [],
  fetchMod: mockFetchMod,
  fetchStats: mockFetchStats,
  fetchHistory: mockFetchHistory,
  patch: mockPatch,
})

vi.mock('~/stores/lockdown', () => ({
  useLockdownStore: () => store,
}))

// plans/active/2026-09-27-lockdown-switch.md sections 11.6 and 11.11 - the
// orchestrator for the Support Lockdown tab. Child components are exercised
// by their own specs, so here the stubs are minimal and the wiring (what is
// shown, what gets patched and in what shape, the poll cadence) is what's
// under test.
const pressStub = {
  template: '<div class="mod-support-lockdown-press" />',
  emits: ['pressed'],
}
const surfacesStub = {
  template: '<div class="mod-support-lockdown-surfaces" />',
  props: ['surfaces', 'busy'],
  emits: ['set-surface'],
}
const noticeStub = {
  template: '<div class="mod-support-lockdown-notice" />',
  props: ['notice', 'active', 'busy'],
  emits: ['save'],
}

describe('ModSupportLockdown', () => {
  function createWrapper() {
    return mount(ModSupportLockdown, {
      global: {
        stubs: {
          NoticeMessage: { template: '<div><slot /></div>' },
          ModSupportLockdownPress: pressStub,
          ModSupportLockdownSurfaces: surfacesStub,
          ModSupportLockdownNotice: noticeStub,
          ModSupportLockdownStats: {
            template: '<div class="mod-support-lockdown-stats" />',
            props: ['stats'],
          },
          ModSupportLockdownHistory: {
            template: '<div class="mod-support-lockdown-history" />',
            props: ['history'],
          },
          ModSupportLockdownTakingEffect: {
            template: '<div class="mod-support-lockdown-taking-effect" />',
            props: ['stats'],
          },
          ModSupportLockdownHeld: {
            template: '<div class="mod-support-lockdown-held" />',
          },
          'b-tabs': { template: '<div><slot /></div>' },
          'b-tab': {
            template: '<div class="b-tab"><slot name="title" /><slot /></div>',
          },
          'b-form-group': { template: '<div><slot /></div>' },
          'b-form-textarea': {
            template:
              '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue'],
          },
          'b-button': {
            template:
              '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['disabled', 'variant', 'size'],
            emits: ['click'],
          },
          ConfirmModal: {
            template: '<div><slot /></div>',
            props: ['title'],
            emits: ['confirm'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockFetchMod.mockResolvedValue({})
    mockFetchStats.mockResolvedValue({})
    mockFetchHistory.mockResolvedValue({})
    mockPatch.mockResolvedValue({})
    store.active = false
    store.surfaces = {}
    store.reason = null
    store.notice = null
    store.startedat = null
    store.startedbyname = null
    store.stats = null
    store.history = []
    vi.useFakeTimers()
  })

  it('fetches mod state and history on mount, and stats only while active', async () => {
    createWrapper()
    await flushPromises()
    expect(mockFetchMod).toHaveBeenCalled()
    expect(mockFetchHistory).toHaveBeenCalled()
    expect(mockFetchStats).not.toHaveBeenCalled()

    store.active = true
    createWrapper()
    await flushPromises()
    expect(mockFetchStats).toHaveBeenCalled()
  })

  it('shows the press form, the notice and the history when not active', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.find('.mod-support-lockdown-press').exists()).toBe(true)
    expect(wrapper.find('.mod-support-lockdown-history').exists()).toBe(true)
    const notice = wrapper.findComponent('.mod-support-lockdown-notice')
    expect(notice.exists()).toBe(true)
    expect(notice.props('active')).toBe(false)
    expect(
      wrapper.find('[data-testid="lockdown-active-banner"]').exists()
    ).toBe(false)
  })

  it('shows the controls and the What is held subtab when active', async () => {
    store.active = true
    store.surfaces = { mods: true, chat: true }
    store.reason = 'voucher wave'
    store.startedbyname = 'Support Person'
    store.startedat = new Date().toISOString()
    const wrapper = createWrapper()
    await flushPromises()

    expect(wrapper.find('.mod-support-lockdown-press').exists()).toBe(false)
    expect(
      wrapper.find('[data-testid="lockdown-status-line"]').text()
    ).toContain('Support Person: voucher wave')
    expect(
      wrapper.findComponent('.mod-support-lockdown-surfaces').props('surfaces')
    ).toEqual({
      mods: true,
      chat: true,
    })
    expect(wrapper.find('.mod-support-lockdown-stats').exists()).toBe(true)
    expect(wrapper.find('.mod-support-lockdown-taking-effect').exists()).toBe(
      true
    )
    expect(
      wrapper.findComponent('.mod-support-lockdown-notice').props('active')
    ).toBe(true)
    expect(
      wrapper.find('[data-testid="lockdown-subtab-controls"]').text()
    ).toBe('Controls')
    expect(wrapper.find('[data-testid="lockdown-subtab-held"]').text()).toBe(
      'What is held'
    )
    expect(wrapper.find('.mod-support-lockdown-held').exists()).toBe(true)
  })

  it('refetches mod state when the press form emits pressed', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    mockFetchMod.mockClear()
    await wrapper.findComponent(pressStub).vm.$emit('pressed')
    await flushPromises()
    expect(mockFetchMod).toHaveBeenCalled()
  })

  it('polls history every 60 seconds while not active', async () => {
    createWrapper()
    await flushPromises()
    mockFetchHistory.mockClear()
    vi.advanceTimersByTime(60000)
    await flushPromises()
    expect(mockFetchHistory).toHaveBeenCalled()
  })

  it('stops polling after unmount', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    wrapper.unmount()
    mockFetchStats.mockClear()
    vi.advanceTimersByTime(120000)
    await flushPromises()
    expect(mockFetchStats).not.toHaveBeenCalled()
  })

  // Section 11.6: poll every 5 seconds while any batch loop hasn't caught
  // up, then every 60 seconds once they all have.
  it('polls every 5 seconds while active and a loop has not caught up', async () => {
    store.active = true
    store.stats = { acks: [{ loop: 'chat-process', caughtup: false }] }
    createWrapper()
    await flushPromises()
    mockFetchStats.mockClear()

    vi.advanceTimersByTime(5000)
    await flushPromises()
    expect(mockFetchStats).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(5000)
    await flushPromises()
    expect(mockFetchStats).toHaveBeenCalledTimes(2)
  })

  it('slows to a 60 second poll once every loop has caught up', async () => {
    store.active = true
    store.stats = { acks: [{ loop: 'chat-process', caughtup: true }] }
    createWrapper()
    await flushPromises()
    mockFetchStats.mockClear()

    vi.advanceTimersByTime(5000)
    await flushPromises()
    expect(mockFetchStats).not.toHaveBeenCalled()

    vi.advanceTimersByTime(55000)
    await flushPromises()
    expect(mockFetchStats).toHaveBeenCalledTimes(1)
  })

  it('lifts an area and refreshes stats straight away', async () => {
    store.active = true
    store.surfaces = { chat: true }
    const wrapper = createWrapper()
    await flushPromises()
    mockFetchStats.mockClear()

    await wrapper
      .findComponent('.mod-support-lockdown-surfaces')
      .vm.$emit('set-surface', 'chat', false)
    await flushPromises()

    expect(mockPatch).toHaveBeenCalledWith({
      action: 'surfaces',
      surfaces: { chat: false },
    })
    expect(mockFetchStats).toHaveBeenCalled()
  })

  it('holds an area again', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper
      .findComponent('.mod-support-lockdown-surfaces')
      .vm.$emit('set-surface', 'posts', true)
    await flushPromises()
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'surfaces',
      surfaces: { posts: true },
    })
  })

  it('saves the notice text, or null for no notice', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()

    await wrapper
      .findComponent('.mod-support-lockdown-notice')
      .vm.$emit('save', 'Back soon.')
    await flushPromises()
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'notice',
      notice: 'Back soon.',
    })

    await wrapper
      .findComponent('.mod-support-lockdown-notice')
      .vm.$emit('save', null)
    await flushPromises()
    expect(mockPatch).toHaveBeenCalledWith({ action: 'notice', notice: null })
  })

  it('saves a notice after close too', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper
      .findComponent('.mod-support-lockdown-notice')
      .vm.$emit('save', 'Things are back to normal.')
    await flushPromises()
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'notice',
      notice: 'Things are back to normal.',
    })
  })

  it('disables Lift everything once nothing is held', async () => {
    store.active = true
    store.surfaces = { mods: false, chat: false }
    const wrapper = createWrapper()
    await flushPromises()
    expect(
      wrapper
        .find('[data-testid="lockdown-liftall-button"]')
        .attributes('disabled')
    ).toBeDefined()
  })

  it('patches liftall after the dialog confirms', async () => {
    store.active = true
    store.surfaces = { chat: true }
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper
      .find('[data-testid="lockdown-liftall-button"]')
      .trigger('click')
    expect(
      wrapper.find('[data-testid="lockdown-liftall-confirm"]').text()
    ).toContain('released straight away')
    await wrapper.vm.onLiftAll()
    expect(mockPatch).toHaveBeenCalledWith({ action: 'liftall' })
  })

  it('patches close with the trimmed end note, then clears it', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper
      .find('[data-testid="lockdown-close-note"]')
      .setValue('  drill over  ')
    await wrapper.find('[data-testid="lockdown-close-button"]').trigger('click')
    expect(
      wrapper.find('[data-testid="lockdown-close-confirm"]').text()
    ).toContain('member notice is removed')
    await wrapper.vm.onClose()
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'close',
      endnote: 'drill over',
    })
    expect(
      wrapper.find('[data-testid="lockdown-close-note"]').element.value
    ).toBe('')
  })

  // useOurModal() defaults autoShow to true, so an always-mounted
  // ConfirmModal pops open the instant the active-state page mounts instead
  // of waiting for its button.
  it('does not mount the lift-everything or close confirm modals until their buttons are clicked', async () => {
    store.active = true
    store.surfaces = { chat: true }
    const wrapper = createWrapper()
    await flushPromises()
    expect(
      wrapper.find('[data-testid="lockdown-liftall-confirm"]').exists()
    ).toBe(false)
    expect(
      wrapper.find('[data-testid="lockdown-close-confirm"]').exists()
    ).toBe(false)

    await wrapper
      .find('[data-testid="lockdown-liftall-button"]')
      .trigger('click')
    expect(
      wrapper.find('[data-testid="lockdown-liftall-confirm"]').exists()
    ).toBe(true)
    expect(
      wrapper.find('[data-testid="lockdown-close-confirm"]').exists()
    ).toBe(false)

    await wrapper.find('[data-testid="lockdown-close-button"]').trigger('click')
    expect(
      wrapper.find('[data-testid="lockdown-close-confirm"]').exists()
    ).toBe(true)
  })

  it('disables the buttons while a change is being saved', async () => {
    store.active = true
    store.surfaces = { chat: true }
    let resolvePatch
    mockPatch.mockReturnValue(
      new Promise((resolve) => {
        resolvePatch = resolve
      })
    )
    const wrapper = createWrapper()
    await flushPromises()

    const pending = wrapper.vm.onSetSurface('chat', false)
    await flushPromises()
    expect(
      wrapper.findComponent('.mod-support-lockdown-surfaces').props('busy')
    ).toBe(true)
    expect(
      wrapper
        .find('[data-testid="lockdown-close-button"]')
        .attributes('disabled')
    ).toBeDefined()

    resolvePatch({})
    await pending
    await flushPromises()
    expect(
      wrapper.findComponent('.mod-support-lockdown-surfaces').props('busy')
    ).toBe(false)
  })
})
