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
  startedat: null,
  startedby: null,
  startedbyname: null,
  phrases: [],
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

// plans/active/2026-09-27-lockdown-switch.md sections 10.9, 10.10, 11.2 - the
// orchestrator for the Support Lockdown tab: not-active shows the press
// form, active shows the surfaces/notice/phrases/stats/lift/close controls
// and refreshes stats+history every minute. Child components are exercised
// by their own specs, so here the stubs are minimal and the wiring (what
// gets patched, in what shape) is what's under test.
const pressStub = {
  template: '<div class="mod-support-lockdown-press" />',
  emits: ['pressed'],
}

describe('ModSupportLockdown', () => {
  function createWrapper() {
    return mount(ModSupportLockdown, {
      global: {
        stubs: {
          NoticeMessage: { template: '<div><slot /></div>' },
          ModSupportLockdownPress: pressStub,
          ModSupportLockdownSurfaces: {
            template: '<div class="mod-support-lockdown-surfaces" />',
            props: ['surfaces', 'heldCounts'],
          },
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
          'b-form-group': { template: '<div><slot /></div>' },
          'b-form-select': {
            template:
              '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value || null)"><option v-for="o in options" :key="String(o.value)" :value="o.value">{{ o.text }}</option></select>',
            props: ['modelValue', 'options'],
          },
          'b-form-textarea': {
            template:
              '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue'],
          },
          'b-button': {
            template:
              '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['disabled', 'variant', 'size'],
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
    store.startedat = null
    store.startedbyname = null
    store.phrases = []
    store.stats = null
    store.history = []
    vi.useFakeTimers()
  })

  it('fetches mod state, stats and history on mount', async () => {
    createWrapper()
    await flushPromises()
    expect(mockFetchMod).toHaveBeenCalled()
    expect(mockFetchStats).toHaveBeenCalled()
    expect(mockFetchHistory).toHaveBeenCalled()
  })

  it('shows the press form when not active', async () => {
    store.active = false
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.find('.mod-support-lockdown-press').exists()).toBe(true)
    expect(wrapper.find('[data-testid="lockdown-status-line"]').exists()).toBe(
      false
    )
  })

  it('shows the active controls, status line, surfaces and stats when active', async () => {
    store.active = true
    store.reason = 'Phishing wave'
    store.startedbyname = 'Jane Mod'
    store.startedat = new Date().toISOString()
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.find('.mod-support-lockdown-press').exists()).toBe(false)
    const banner = wrapper.find('[data-testid="lockdown-active-banner"]')
    expect(banner.exists()).toBe(true)
    expect(banner.text()).toContain('Lockdown on')
    const status = wrapper.find('[data-testid="lockdown-status-line"]')
    expect(status.exists()).toBe(true)
    expect(status.text()).toContain('Jane Mod')
    expect(status.text()).toContain('Phishing wave')
    expect(wrapper.find('.mod-support-lockdown-surfaces').exists()).toBe(true)
    expect(wrapper.find('.mod-support-lockdown-stats').exists()).toBe(true)
    expect(wrapper.find('.mod-support-lockdown-history').exists()).toBe(true)
    expect(wrapper.find('.mod-support-lockdown-taking-effect').exists()).toBe(
      true
    )
  })

  // GET /modtools/lockdown/stats' `held` is a flat array of
  // {kind,count,...}, one entry per kind, not an object keyed by kind - see
  // ModSupportLockdownSurfaces.vue's countFor(), which indexes heldCounts by
  // kind.
  it('turns the held array into a kind-keyed lookup for the surfaces panel', async () => {
    store.active = true
    store.stats = {
      held: [
        { kind: 'chat', count: 5, distinctusers: 3 },
        { kind: 'post', count: 2, distinctusers: 2 },
      ],
    }
    const wrapper = createWrapper()
    await flushPromises()
    const surfaces = wrapper.findComponent('.mod-support-lockdown-surfaces')
    expect(surfaces.props('heldCounts')).toEqual({
      chat: { kind: 'chat', count: 5, distinctusers: 3 },
      post: { kind: 'post', count: 2, distinctusers: 2 },
    })
  })

  it('refetches mod state when the press form emits pressed', async () => {
    store.active = false
    const wrapper = createWrapper()
    await flushPromises()
    mockFetchMod.mockClear()
    await wrapper.findComponent(pressStub).vm.$emit('pressed')
    await flushPromises()
    expect(mockFetchMod).toHaveBeenCalled()
  })

  it('polls stats and history every 60 seconds while mounted', async () => {
    createWrapper()
    await flushPromises()
    mockFetchStats.mockClear()
    mockFetchHistory.mockClear()
    vi.advanceTimersByTime(60000)
    await flushPromises()
    expect(mockFetchStats).toHaveBeenCalled()
    expect(mockFetchHistory).toHaveBeenCalled()
  })

  it('stops polling after unmount', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    wrapper.unmount()
    mockFetchStats.mockClear()
    vi.advanceTimersByTime(120000)
    await flushPromises()
    expect(mockFetchStats).not.toHaveBeenCalled()
  })

  // plans/active/2026-09-27-lockdown-switch.md section 11.6: poll every 5
  // seconds while any batch loop hasn't caught up, then every 60 seconds
  // once they all have.
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

  it('refreshes stats immediately after a patch, without waiting for a poll', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    mockFetchStats.mockClear()
    mockFetchHistory.mockClear()
    await wrapper.vm.onToggleSurface('posts', false)
    expect(mockFetchStats).toHaveBeenCalled()
    expect(mockFetchHistory).toHaveBeenCalled()
  })

  it('patches a surface toggle', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper.vm.onToggleSurface('posts', false)
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'surfaces',
      surfaces: { posts: false },
    })
  })

  it('patches a chat mode change', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper.vm.onSetChatMode('soft')
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'surfaces',
      surfaces: {},
      chat_mode: 'soft',
    })
  })

  it('patches the notice choice, including "normal"', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper.vm.onNotice('normal')
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'notice',
      notice: 'normal',
    })
  })

  it('patches phrases as a lowercase, trimmed array', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    wrapper.vm.onPhrasesInput('Click Here\n\n  freegift.example.com  ')
    await wrapper.vm.onPhrasesSave()
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'phrases',
      phrases: ['click here', 'freegift.example.com'],
    })
  })

  it('patches markspam from the stats component', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper.vm.onMarkSpam()
    expect(mockPatch).toHaveBeenCalledWith({ action: 'markspam' })
  })

  it('patches releaseclass from the stats component', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper.vm.onReleaseClass({
      kind: 'chat',
      risk: 'spam',
      decision: 'reject',
    })
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'releaseclass',
      kind: 'chat',
      risk: 'spam',
      decision: 'reject',
    })
  })

  it('patches liftall', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper.vm.onLiftAll()
    expect(mockPatch).toHaveBeenCalledWith({ action: 'liftall' })
  })

  it('patches close with the end note', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper
      .find('[data-testid="lockdown-close-note"]')
      .setValue('All clear, spam senders banned')
    await wrapper.vm.onClose()
    expect(mockPatch).toHaveBeenCalledWith({
      action: 'close',
      endnote: 'All clear, spam senders banned',
    })
  })

  // plans/active/2026-09-27-lockdown-switch.md section 11.6: "Lift
  // everything" and "Close" have their own dialogs with this specific
  // wording. Both ConfirmModals are v-if-gated (house pattern - see
  // ModSupportLockdownPress.vue's showConfirmModal), so each test has to
  // open it first rather than finding it already mounted.
  it('shows the lift-everything dialog wording', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper
      .find('[data-testid="lockdown-liftall-button"]')
      .trigger('click')
    const dialog = wrapper.find('[data-testid="lockdown-liftall-confirm"]')
    expect(dialog.text()).toContain(
      'Lifting releases held messages at a paced rate, and moderators will see the risky ones in their queues.'
    )
  })

  it('shows the close dialog wording', async () => {
    store.active = true
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper.find('[data-testid="lockdown-close-button"]').trigger('click')
    const dialog = wrapper.find('[data-testid="lockdown-close-confirm"]')
    expect(dialog.text()).toContain(
      'Closing ends the incident and clears the incident phrases.'
    )
  })

  // useOurModal() defaults autoShow to true, so an always-mounted
  // ConfirmModal pops open the instant the active-state page mounts instead
  // of waiting for its button - the bug fixed here for "Lift everything" and
  // "Close", matching the pattern already used for "Press".
  it('does not mount the lift-everything or close confirm modals until their buttons are clicked', async () => {
    store.active = true
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
})
