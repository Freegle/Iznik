import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportLockdownStats from '~/modtools/components/ModSupportLockdownStats.vue'

// plans/active/2026-09-27-lockdown-switch.md section 10.6/10.9/10.10/11.2.
// The stats JSON shape here is this agent's own proposal (the Go handler
// isn't built yet - see .claude-agent-status/ui-lockdown.md) - kept
// defensive with optional chaining so a field rename is a small diff.
describe('ModSupportLockdownStats', () => {
  const stats = {
    triage: {
      chat: { spam: 40, risky: 10, low: 70 },
      post: { spam: 5, risky: 2, low: 23 },
      chitchat: { spam: 0, risky: 1, low: 4 },
    },
    samples: {
      spam: [
        {
          kind: 'chat',
          id: 111,
          text: 'Click here to claim your refund now',
          senderid: 456,
        },
      ],
      risky: [
        { kind: 'post', id: 222, text: 'Anyone want this sofa', senderid: 789 },
      ],
    },
    clusters: [{ line: 'click here to claim your refund', count: 34 }],
    accountscreated: 17,
    counters: {
      email: { digest: 200, immediate: 50 },
      push: 30,
      export: 2,
      refused: [{ userid: 1, name: 'Jane Mod', count: 4 }],
      approved: [{ userid: 1, name: 'Jane Mod', count: 12 }],
    },
    outcomes: {
      released: 100,
      review: 12,
      approved: 300,
      rejected: 20,
      spam_marked: 45,
    },
  }

  function createWrapper(props = {}) {
    return mount(ModSupportLockdownStats, {
      props: { stats, ...props },
      global: {
        stubs: {
          'b-badge': { template: '<span><slot /></span>' },
          'b-button': {
            template: '<button @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'size', 'disabled'],
          },
          ConfirmModal: {
            template: '<div />',
            props: ['title', 'message'],
            emits: ['confirm'],
          },
        },
      },
    })
  }

  it('renders triage counts per kind', () => {
    const wrapper = createWrapper()
    const triage = wrapper.find('[data-testid="lockdown-triage"]')
    expect(triage.text()).toContain('40')
    expect(triage.text()).toContain('10')
    expect(triage.text()).toContain('70')
  })

  it('renders spam samples, truncated safely, never the low set', () => {
    const wrapper = createWrapper()
    const spam = wrapper.find('[data-testid="lockdown-samples-spam"]')
    expect(spam.text()).toContain('Click here to claim your refund now')
    expect(wrapper.find('[data-testid="lockdown-samples-low"]').exists()).toBe(
      false
    )
  })

  it('renders risky samples', () => {
    const wrapper = createWrapper()
    expect(
      wrapper.find('[data-testid="lockdown-samples-risky"]').text()
    ).toContain('Anyone want this sofa')
  })

  it('renders clusters', () => {
    const wrapper = createWrapper()
    expect(wrapper.find('[data-testid="lockdown-clusters"]').text()).toContain(
      'click here to claim your refund'
    )
    expect(wrapper.find('[data-testid="lockdown-clusters"]').text()).toContain(
      '34'
    )
  })

  it('renders accounts created during the window', () => {
    const wrapper = createWrapper()
    expect(
      wrapper.find('[data-testid="lockdown-accounts-created"]').text()
    ).toContain('17')
  })

  it('renders not-sent counters and moderator actions', () => {
    const wrapper = createWrapper()
    const counters = wrapper.find('[data-testid="lockdown-counters"]')
    expect(counters.text()).toContain('200')
    expect(counters.text()).toContain('50')
    expect(counters.text()).toContain('30')
    expect(counters.text()).toContain('Jane Mod')
  })

  it('renders outcomes', () => {
    const wrapper = createWrapper()
    const outcomes = wrapper.find('[data-testid="lockdown-outcomes"]')
    expect(outcomes.text()).toContain('100')
    expect(outcomes.text()).toContain('45')
  })

  it('emits markspam when "Mark spam set" is confirmed', async () => {
    const wrapper = createWrapper()
    await wrapper.vm.confirmMarkSpam()
    expect(wrapper.emitted('markspam')).toBeTruthy()
  })

  it('emits releaseclass with kind, risk and decision', async () => {
    const wrapper = createWrapper()
    wrapper.vm.askReleaseClass('chat', 'risky', 'release')
    await wrapper.vm.confirmReleaseClass()
    expect(wrapper.emitted('releaseclass')).toEqual([
      [{ kind: 'chat', risk: 'risky', decision: 'release' }],
    ])
  })

  it('renders sensibly with no stats yet (nothing fetched)', () => {
    const wrapper = createWrapper({ stats: null })
    expect(
      wrapper.find('[data-testid="lockdown-accounts-created"]').text()
    ).toContain('0')
  })
})
