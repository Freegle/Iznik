import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModSysAdminAutomod from '~/modtools/components/ModSysAdminAutomod.vue'

const mockFetchAutomodAgreement = vi.fn()
vi.mock('@/stores/message', () => ({
  useMessageStore: () => ({ fetchAutomodAgreement: mockFetchAutomodAgreement }),
}))

const tableStub = { template: '<div><slot /></div>' }
const stubs = {
  'b-form-group': tableStub,
  'b-form-select': { template: '<select />', props: ['modelValue', 'options'] },
  'b-card': { template: '<div class="card"><slot /></div>', props: ['header'] },
  'b-table-simple': { template: '<table><slot /></table>' },
  'b-thead': { template: '<thead><slot /></thead>' },
  'b-tbody': { template: '<tbody><slot /></tbody>' },
  'b-tr': { template: '<tr><slot /></tr>' },
  'b-th': { template: '<th><slot /></th>' },
  'b-td': { template: '<td><slot /></td>' },
  'b-spinner': true,
  NoticeMessage: { template: '<div class="notice"><slot /></div>' },
}

const stats = {
  days: 30,
  total: 12,
  shadow: { count: 8 },
  approve: { count: 4 },
  unavailable: 2,
  late: 1,
  latency: { avgSeconds: 90, maxSeconds: 700 },
  byEnd: [
    {
      end: 'APPROVE',
      verdict: 'approve',
      count: 9,
      modDisagreed: 1,
      neighbourDisagreed: 3,
    },
    {
      end: 'HOLD_LOAN',
      verdict: 'hold',
      count: 3,
      modDisagreed: 0,
      neighbourDisagreed: 0,
    },
  ],
  byGroup: [
    {
      groupid: 5,
      nameshort: 'FreegleTown',
      decisions: 10,
      decided: 8,
      approves: 8,
      holds: 2,
      unavailable: 1,
      modDisagreed: 1,
      neighbourDisagreed: 2,
      late: 1,
    },
    {
      groupid: 6,
      nameshort: 'OtherTown',
      decisions: 2,
      decided: 1,
      approves: 1,
      holds: 1,
      unavailable: 1,
      modDisagreed: 0,
      neighbourDisagreed: 1,
      late: 0,
    },
  ],
  byVersion: [
    {
      version: '5',
      decisions: 10,
      decided: 10,
      approves: 8,
      holds: 2,
      unavailable: 0,
      modDisagreed: 1,
      neighbourDisagreed: 3,
      late: 1,
    },
    {
      version: '',
      decisions: 2,
      decided: 0,
      approves: 0,
      holds: 0,
      unavailable: 2,
      modDisagreed: 0,
      neighbourDisagreed: 0,
      late: 0,
    },
  ],
  byDay: [
    {
      day: '2026-10-09',
      decisions: 7,
      decided: 6,
      approves: 5,
      holds: 1,
      unavailable: 1,
      modDisagreed: 0,
      neighbourDisagreed: 1,
      late: 0,
    },
    {
      day: '2026-10-10',
      decisions: 5,
      decided: 4,
      approves: 4,
      holds: 0,
      unavailable: 1,
      modDisagreed: 1,
      neighbourDisagreed: 2,
      late: 1,
    },
  ],
  feedback: [{ node: 'LOAN', count: 3, posts: 2 }],
}

describe('ModSysAdminAutomod', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetchAutomodAgreement.mockResolvedValue(stats)
  })

  it('fetches the last 30 days on mount', async () => {
    mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    expect(mockFetchAutomodAgreement).toHaveBeenCalledWith({ days: 30 })
  })

  it('shows the totals, one row per end node with the disagreement rate, and the feedback', async () => {
    const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    const text = wrapper.text()
    expect(text).toContain('12')
    expect(text).toContain('HOLD_LOAN')
    expect(text).toContain('11.1%')
    expect(text).toContain('LOAN')
  })

  // The three numbers that say whether the trial is safe to widen: how often the review
  // could not run, how often it decided after the wait had already run out, and how long
  // it takes; plus the neighbours' verdicts on what it approved.
  it('shows the unavailable and late counts, the latency, and neighbour disagreement', async () => {
    const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    expect(
      wrapper.find('[data-testid="automod-unavailable"]').text()
    ).toContain('2')
    expect(
      wrapper.find('[data-testid="automod-unavailable"]').text()
    ).toContain('16.7%')
    expect(wrapper.find('[data-testid="automod-late"]').text()).toContain('1')
    expect(wrapper.find('[data-testid="automod-latency"]').text()).toContain(
      'average 2 min, longest 12 min'
    )
    const byEnd = wrapper.text()
    expect(byEnd).toContain('Neighbour disagreed')
    expect(byEnd).toContain('3')
  })

  it('lists each community and lets the panel be narrowed to one', async () => {
    const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    const byGroup = wrapper.find('[data-testid="automod-by-group"]')
    expect(byGroup.exists()).toBe(true)
    expect(byGroup.text()).toContain('FreegleTown')
    expect(byGroup.text()).toContain('OtherTown')
    // The community choices come from the unfiltered fetch, and stay once narrowed.
    expect(wrapper.vm.groupOptions.map((o) => o.text)).toEqual([
      'All communities',
      'FreegleTown',
      'OtherTown',
    ])
    mockFetchAutomodAgreement.mockResolvedValue({
      ...stats,
      byGroup: [stats.byGroup[0]],
    })
    wrapper.vm.groupid = 5
    await wrapper.vm.fetchStats()
    expect(mockFetchAutomodAgreement).toHaveBeenLastCalledWith({
      days: 30,
      groupid: 5,
    })
    expect(wrapper.vm.groupOptions).toHaveLength(3)
  })

  // A chart bump or a bad day has to show as its own row, not be averaged into the window.
  it('breaks the figures down by chart version and by day', async () => {
    const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    const byVersion = wrapper.find('[data-testid="automod-by-version"]')
    expect(byVersion.exists()).toBe(true)
    expect(byVersion.text()).toContain('(none)')
    expect(byVersion.text()).toContain('10.0%')
    const byDay = wrapper.find('[data-testid="automod-by-day"]')
    expect(byDay.exists()).toBe(true)
    expect(byDay.text()).toContain('2026-10-09')
    expect(byDay.text()).toContain('2026-10-10')
    expect(byDay.text()).toContain('25.0%')
  })

  // A community can run in shadow and then in approve mode in the same window. They are
  // separate rows, because they measure different things: in shadow a moderator decides every
  // post, so disagreement is a fair measure; in approve mode only a post somebody opens in
  // Check meets a human, so the figure is a lower bound.
  describe('a community that ran in both modes', () => {
    const both = [
      { ...stats.byGroup[0], mode: 'shadow', decisions: 6, decided: 6 },
      { ...stats.byGroup[0], mode: 'approve', decisions: 4, decided: 2 },
      { ...stats.byGroup[1], mode: 'shadow' },
    ]

    it('has a row for each mode, with the mode shown', async () => {
      mockFetchAutomodAgreement.mockResolvedValue({ ...stats, byGroup: both })
      const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
      await flushPromises()
      const rows = wrapper
        .find('[data-testid="automod-by-group"]')
        .findAll('tbody tr')
      expect(rows).toHaveLength(3)
      expect(rows[0].text()).toContain('FreegleTown')
      expect(rows[0].text()).toContain('shadow')
      expect(rows[1].text()).toContain('FreegleTown')
      expect(rows[1].text()).toContain('approve')
    })

    it('lists the community once in the choice of communities', async () => {
      mockFetchAutomodAgreement.mockResolvedValue({ ...stats, byGroup: both })
      const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
      await flushPromises()
      expect(wrapper.vm.groupOptions.map((o) => o.text)).toEqual([
        'All communities',
        'FreegleTown',
        'OtherTown',
      ])
    })

    it('says why the approve mode figure is a lower bound', async () => {
      mockFetchAutomodAgreement.mockResolvedValue({ ...stats, byGroup: both })
      const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
      await flushPromises()
      expect(wrapper.text()).toContain('a lower bound')
    })
  })

  // The safety measure for the trial: posts that published themselves and a moderator then
  // pulled back from Check, and what was decided about them.
  describe('posts pulled back from Check', () => {
    const pulled = {
      ...stats,
      pulledBack: 7,
      pulledBackApproved: 3,
      pulledBackRejected: 2,
    }

    it('shows how many, how many turned out to be false alarms, and how many were real catches', async () => {
      mockFetchAutomodAgreement.mockResolvedValue(pulled)
      const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
      await flushPromises()
      const card = wrapper.find('[data-testid="automod-pulled-back"]')
      expect(card.exists()).toBe(true)
      const text = card.text()
      expect(text).toContain('7')
      expect(text).toContain('false alarms')
      expect(text).toContain('3')
      expect(text).toContain('real catches')
      expect(text).toContain('2')
      expect(text).toContain('still waiting')
      expect(
        card.find('[data-testid="automod-pulled-back-waiting"]').text()
      ).toBe('2')
    })

    it('is left out when the API does not report it', async () => {
      const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
      await flushPromises()
      expect(wrapper.find('[data-testid="automod-pulled-back"]').exists()).toBe(
        false
      )
    })
  })

  // A review that could not run, or a community that has every post checked, is not a
  // decision about the post, so no disagreement can be counted against it: the rate is
  // over the decisions that were made, not the rows that were written.
  describe('rates', () => {
    const rowFor = (wrapper, testid, text) =>
      wrapper
        .find(`[data-testid="${testid}"]`)
        .findAll('tr')
        .find((r) => r.text().includes(text))

    it('takes the disagreement over a community decided rows, not every row', async () => {
      const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
      await flushPromises()
      const row = rowFor(wrapper, 'automod-by-group', 'FreegleTown')
      expect(row.text()).toContain('12.5%')
      expect(row.text()).not.toContain('10.0%')
    })

    it('falls back to every row when the API does not say how many were decided', async () => {
      mockFetchAutomodAgreement.mockResolvedValue({
        ...stats,
        byGroup: [{ ...stats.byGroup[0], decided: undefined }],
      })
      const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
      await flushPromises()
      expect(
        rowFor(wrapper, 'automod-by-group', 'FreegleTown').text()
      ).toContain('10.0%')
    })

    it('shows no rate for an end that is not a decision about the post', async () => {
      mockFetchAutomodAgreement.mockResolvedValue({
        ...stats,
        byEnd: [
          ...stats.byEnd,
          {
            end: 'UNAVAILABLE',
            verdict: 'hold',
            count: 3,
            modDisagreed: 0,
            neighbourDisagreed: 0,
          },
          {
            end: 'GROUP_MODERATES_ALL',
            verdict: 'hold',
            count: 4,
            modDisagreed: 0,
            neighbourDisagreed: 0,
          },
        ],
      })
      const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
      await flushPromises()
      for (const end of ['UNAVAILABLE', 'GROUP_MODERATES_ALL']) {
        const row = wrapper.findAll('tr').find((r) => r.text().startsWith(end))
        expect(row.findAll('td')[4].text()).toBe('-')
      }
      const decided = wrapper
        .findAll('tr')
        .find((r) => r.text().startsWith('HOLD_LOAN'))
      expect(decided.findAll('td')[4].text()).toBe('0.0%')
    })

    it('does not use a dash as punctuation', async () => {
      const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
      await flushPromises()
      expect(wrapper.text()).not.toMatch(/[\u2013\u2014]/)
    })
  })

  // Several moderators can mark the same step on the same decision. The report says how many
  // posts had a step marked, and separately how many marks that was.
  describe('steps marked wrong', () => {
    it('shows posts and marks, so one post several moderators marked is not read as several posts', async () => {
      const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
      await flushPromises()
      const table = wrapper.find('[data-testid="automod-feedback"]')
      expect(table.exists()).toBe(true)
      const headings = table.findAll('th').map((h) => h.text())
      expect(headings).toEqual(['Step', 'Posts', 'Marks'])
      const cells = table.findAll('tbody td').map((c) => c.text())
      expect(cells).toEqual(['LOAN', '2', '3'])
      expect(wrapper.text()).toContain('a post several moderators marked adds')
    })

    it('shows marks alone when the API does not give posts', async () => {
      mockFetchAutomodAgreement.mockResolvedValue({
        ...stats,
        feedback: [{ node: 'LOAN', count: 2 }],
      })
      const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
      await flushPromises()
      const cells = wrapper
        .find('[data-testid="automod-feedback"]')
        .findAll('tbody td')
        .map((c) => c.text())
      expect(cells).toEqual(['LOAN', '-', '2'])
    })

    it('says so when nothing was marked', async () => {
      mockFetchAutomodAgreement.mockResolvedValue({ ...stats, feedback: [] })
      const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
      await flushPromises()
      expect(wrapper.find('[data-testid="automod-feedback"]').exists()).toBe(
        false
      )
      expect(wrapper.text()).toContain('No feedback in this period')
    })
  })

  it('says so when a period has no decisions to break down', async () => {
    mockFetchAutomodAgreement.mockResolvedValue({
      ...stats,
      byVersion: [],
      byDay: undefined,
    })
    const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    expect(wrapper.find('[data-testid="automod-by-version"]').exists()).toBe(
      false
    )
    expect(wrapper.find('[data-testid="automod-by-day"]').exists()).toBe(false)
  })

  it('shows the error when the request fails', async () => {
    mockFetchAutomodAgreement.mockRejectedValue(new Error('boom'))
    const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    expect(wrapper.find('.notice').text()).toContain('boom')
  })
})
