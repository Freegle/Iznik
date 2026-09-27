import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportLockdownStats from '~/modtools/components/ModSupportLockdownStats.vue'

// plans/active/2026-09-27-lockdown-switch.md section 10.6/10.9/10.10/11.2.
// `pressedat`, `counters` (push/export/refused/approved) and `waiting.email`
// (with its `queued`/`removed`/`deferred` sub-objects) match the real
// GET /modtools/lockdown/stats shape (iznik-server-go/lockdown/handlers.go) -
// confirmed by team-lead, not a guess.
describe('ModSupportLockdownStats', () => {
  const stats = {
    pressedat: new Date(Date.now() - 3600000).toISOString(),
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
      push: 30,
      export: 2,
      refused: [{ userid: 1, name: 'Jane Mod', count: 4 }],
      approved: [{ userid: 1, name: 'Jane Mod', count: 12 }],
    },
    // plans/active/2026-09-27-lockdown-switch.md section 11.7/11.8: member
    // email is not generated while held, so `waiting.email` covers all three
    // of what's still queued to send, what filtering removed before resume,
    // and how many mail runs were deferred rather than dropped. Real shape,
    // confirmed by team-lead against iznik-server-go/lockdown/handlers.go.
    waiting: {
      email: {
        queued: { digest: 200, immediate: 50 },
        removed: { digest: 5, immediate: 1 },
        deferred: { 'mail-loops': 12, 'background-tasks': 3 },
      },
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
    expect(counters.text()).toContain('30')
    expect(counters.text()).toContain('Jane Mod')
  })

  // plans/active/2026-09-27-lockdown-switch.md section 11.7 (rewritten):
  // member email is not generated while held, so there is no big "waiting"
  // pile - generation itself is paused and resumes from the watermarks on
  // lift, without anything removed being re-added.
  it('shows email paused since the press, with the resume-without-loss wording', () => {
    const wrapper = createWrapper()
    const paused = wrapper.find('[data-testid="lockdown-email-paused"]')
    expect(paused.text()).toContain('Email paused since')
    expect(paused.text()).toContain('ago')
    expect(paused.text()).toContain(
      'Digests and notifications will be generated when email is resumed, without anything removed in the meantime.'
    )
  })

  it('renders the send queue depth, summed with a per-type breakdown', () => {
    const wrapper = createWrapper()
    const paused = wrapper.find('[data-testid="lockdown-email-paused"]')
    expect(paused.text()).toContain('In the send queue: 250')
    expect(paused.text()).toContain('digest: 200')
    expect(paused.text()).toContain('immediate: 50')
  })

  it('renders mail removed from the queue, summed with a per-type breakdown', () => {
    const wrapper = createWrapper()
    const paused = wrapper.find('[data-testid="lockdown-email-paused"]')
    expect(paused.text()).toContain('Removed from the queue: 6')
    expect(paused.text()).toContain('digest: 5')
    expect(paused.text()).toContain('immediate: 1')
  })

  it('renders mail runs deferred, summed across loops', () => {
    const wrapper = createWrapper()
    const paused = wrapper.find('[data-testid="lockdown-email-paused"]')
    expect(paused.text()).toContain('Mail runs deferred: 15')
  })

  it('shows the email-paused block sensibly when stats is missing', () => {
    const wrapper = createWrapper({ stats: null })
    const paused = wrapper.find('[data-testid="lockdown-email-paused"]')
    expect(paused.text()).toContain('Email paused.')
    expect(paused.text()).not.toContain('since')
    expect(paused.text()).toContain('In the send queue: 0')
    expect(paused.text()).toContain('Removed from the queue: 0')
    expect(paused.text()).toContain('Mail runs deferred: 0')
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
