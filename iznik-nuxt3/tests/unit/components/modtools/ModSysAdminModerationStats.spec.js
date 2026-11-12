import { describe, it, expect, vi, beforeEach } from 'vitest'
import { shallowMount, mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ModSysAdminModerationStats from '~/modtools/components/ModSysAdminModerationStats.vue'

const sampleStats = {
  arrived: 100,
  manualApproved: 20,
  manualRejected: 5,
  trusted: 10,
  autoApproved: 65,
  autoModChecked: 30,
  autoLaterActioned: 2,
  qualitySampled: 40,
  qualitySampleBad: 1,
}

const mockMessageStore = {
  fetchModerationStats: vi.fn().mockResolvedValue(sampleStats),
}
vi.mock('@/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

describe('ModSysAdminModerationStats.vue', () => {
  function mountComponent() {
    return shallowMount(ModSysAdminModerationStats, {
      global: {
        plugins: [createPinia()],
        stubs: {
          'b-form-group': true,
          'b-form-input': true,
          'b-button': true,
          'b-card': true,
          'b-table-simple': true,
          'b-tbody': true,
          'b-tr': true,
          'b-td': true,
          NoticeMessage: true,
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockMessageStore.fetchModerationStats.mockResolvedValue(sampleStats)
  })

  it('fetches stats for the default 30-day range on mount', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    expect(mockMessageStore.fetchModerationStats).toHaveBeenCalledTimes(1)
    const params = mockMessageStore.fetchModerationStats.mock.calls[0][0]
    expect(params.start).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect(params.end).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect(new Date(params.start) <= new Date(params.end)).toBe(true)
    expect(wrapper.vm.stats).toEqual(sampleStats)
    // autoApproved is the single real auto-approved figure (no autoChecked/autoFallback).
    expect(wrapper.vm.stats.autoApproved).toBe(65)
  })

  it('formats percentages and guards divide-by-zero', () => {
    const wrapper = mountComponent()
    expect(wrapper.vm.pct(1, 2)).toBe('50.0%')
    expect(wrapper.vm.pct(2, 65)).toBe('3.1%')
    expect(wrapper.vm.pct(5, 0)).toBe('-')
  })

  it('re-fetches when Update is triggered', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    mockMessageStore.fetchModerationStats.mockClear()
    wrapper.vm.startDate = '2026-01-01'
    wrapper.vm.endDate = '2026-01-31'
    await wrapper.vm.fetchStats()
    expect(mockMessageStore.fetchModerationStats).toHaveBeenCalledWith({
      start: '2026-01-01',
      end: '2026-01-31',
    })
  })

  // The 48-hour fallback publishes posts on every community, trial or not, so the figures
  // that count all auto-approved posts are network-wide. The trial's own are the posts
  // automated review's approve published, with the later interventions on those same posts.
  describe("the trial's own error rate", () => {
    const clean = {
      ...sampleStats,
      autoApproved: 200,
      autoLaterActioned: 4,
      autoApprovedClean: 20,
      autoLaterActionedClean: 3,
    }

    it('is taken over the posts automated review approved when the API gives both figures', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue(clean)
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.vm.trialFigures).toBe(true)
      expect(wrapper.vm.autoErrorRate).toBe('15.0%')
      expect(wrapper.vm.autoErrorRateNum).toBe(15)
    })

    it('falls back to every auto-approved post when the API does not give them', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue({
        ...sampleStats,
        autoApproved: 200,
        autoLaterActioned: 4,
      })
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.vm.trialFigures).toBe(false)
      expect(wrapper.vm.autoErrorRate).toBe('2.0%')
    })

    it('does not pair a trial denominator with an all-posts numerator', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue({
        ...sampleStats,
        autoApproved: 200,
        autoLaterActioned: 4,
        autoApprovedClean: 20,
      })
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.vm.trialFigures).toBe(false)
      expect(wrapper.vm.autoErrorRate).toBe('2.0%')
    })

    it('shows a dash when automated review has published nothing yet', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue({
        ...clean,
        autoApprovedClean: 0,
        autoLaterActionedClean: 0,
      })
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.vm.autoErrorRate).toBe('-')
    })
  })

  // Rendered through real slots, for what a SysAdmin reads.
  describe('what is shown', () => {
    const slot = (tag = 'div') => ({
      template: `<${tag}><slot /></${tag}>`,
    })
    function mountFull() {
      return mount(ModSysAdminModerationStats, {
        global: {
          plugins: [createPinia()],
          stubs: {
            'b-form-group': slot(),
            'b-form-input': true,
            'b-button': slot('button'),
            'b-card': {
              props: ['header'],
              template: '<div class="card"><h4>{{ header }}</h4><slot /></div>',
            },
            'b-table-simple': slot('table'),
            'b-tbody': slot('tbody'),
            'b-tr': slot('tr'),
            'b-td': slot('td'),
            NoticeMessage: slot(),
          },
        },
      })
    }

    it('says the quality sample is one setting for every community, not a per-group one', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue({
        ...sampleStats,
        qualitySampled: 0,
        qualitySampleBad: 0,
      })
      const wrapper = mountFull()
      await flushPromises()
      const text = wrapper.text()
      expect(text).toContain('No quality-check sample in this period')
      expect(text).toContain('FREEGLE_AUTOAPPROVE_QUALITY_CHECK_PCT')
      expect(text).not.toContain('on some groups')
    })

    it('shows the posts automated review approved, beside the network-wide figure', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue({
        ...sampleStats,
        autoApproved: 200,
        autoApprovedClean: 20,
        autoLaterActioned: 4,
        autoLaterActionedClean: 3,
      })
      const wrapper = mountFull()
      await flushPromises()
      const text = wrapper.text()
      expect(text).toContain("published after automated review's approve")
      expect(text).toContain('20')
      expect(text).toContain('15.0% needed intervention')
    })

    it("says which posts the rate is over, so a diluted figure is not read as the trial's", async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue({
        ...sampleStats,
        autoApproved: 200,
        autoLaterActioned: 4,
      })
      const wrapper = mountFull()
      await flushPromises()
      expect(wrapper.text()).toContain('including the 48-hour fallback')
    })

    it('does not use a dash as punctuation', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue({
        ...sampleStats,
        autoApprovedClean: 20,
        autoLaterActionedClean: 3,
        qualitySampleDecided: 10,
      })
      const wrapper = mountFull()
      await flushPromises()
      expect(wrapper.text()).not.toMatch(/[\u2013\u2014]/)
    })

    // A sample nobody has looked at yet is undecided, not good: the bad rate is over the
    // samples a moderator gave a verdict on, when the API says how many that is.
    it('divides the sample bad rate by the samples a moderator decided', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue({
        ...sampleStats,
        qualitySampled: 40,
        qualitySampleDecided: 10,
        qualitySampleBad: 1,
      })
      const wrapper = mountFull()
      await flushPromises()
      expect(wrapper.find('[data-testid="sample-decided"]').text()).toBe('10')
      expect(wrapper.find('[data-testid="sample-bad-rate"]').text()).toContain(
        '10.0% were bad'
      )
    })

    it('falls back to every sample when the API does not say how many were decided', async () => {
      const wrapper = mountFull()
      await flushPromises()
      expect(wrapper.find('[data-testid="sample-decided"]').exists()).toBe(
        false
      )
      expect(wrapper.find('[data-testid="sample-bad-rate"]').text()).toContain(
        '2.5% were bad'
      )
    })
  })

  // Most of a recent sample is still waiting for somebody to look at it, so the share that were
  // bad is over the ones a moderator has decided. Over everything sampled it reads low for as
  // long as they wait, and the comparison with the auto-published rate then flatters it.
  describe('the quality sample', () => {
    const sampled = {
      ...sampleStats,
      qualitySampled: 40,
      qualitySampleDecided: 10,
      qualitySampleBad: 2,
    }
    const slot = (tag = 'div') => ({
      template: `<${tag}><slot /></${tag}>`,
    })
    function mountFull() {
      return mount(ModSysAdminModerationStats, {
        global: {
          plugins: [createPinia()],
          stubs: {
            'b-form-group': slot(),
            'b-form-input': true,
            'b-button': slot('button'),
            'b-card': {
              props: ['header'],
              template: '<div class="card"><h4>{{ header }}</h4><slot /></div>',
            },
            'b-table-simple': slot('table'),
            'b-tbody': slot('tbody'),
            'b-tr': slot('tr'),
            'b-td': slot('td'),
            NoticeMessage: slot(),
          },
        },
      })
    }

    it('takes the share that were bad over the posts a moderator has decided', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue(sampled)
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.vm.sampleBadRate).toBe('20.0%')
    })

    it('shows how many of the sample have been decided and the rate over those', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue(sampled)
      const wrapper = mountFull()
      await flushPromises()
      expect(wrapper.find('[data-testid="sample-decided"]').text()).toBe('10')
      expect(wrapper.find('[data-testid="sample-bad-rate"]').text()).toContain(
        '20.0% were bad'
      )
    })

    it('says why the rate is over the decided ones', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue(sampled)
      const wrapper = mountFull()
      await flushPromises()
      expect(wrapper.text()).toContain('the rest are still waiting')
    })

    it('judges the verdict on the decided rate', async () => {
      // 2 of 10 decided is 20% bad against 0.5% after publishing: far worse. Over all 40
      // sampled it would be 5%, a milder concern.
      mockMessageStore.fetchModerationStats.mockResolvedValue({
        ...sampled,
        autoApproved: 200,
        autoLaterActioned: 1,
      })
      const wrapper = mountFull()
      await flushPromises()
      expect(wrapper.text()).toContain('Concern')
    })

    it('says so when posts were sampled but none has been decided', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue({
        ...sampled,
        qualitySampled: 6,
        qualitySampleDecided: 0,
        qualitySampleBad: 0,
      })
      const wrapper = mountFull()
      await flushPromises()
      expect(wrapper.vm.sampleBadRate).toBe('-')
      expect(wrapper.text()).toContain(
        '6 posts were held back for a manual quality check'
      )
      expect(wrapper.text()).toContain(
        'no moderator has decided any of them yet'
      )
      expect(wrapper.text()).not.toContain(
        'No quality-check sample in this period'
      )
    })

    it('says "1 post was" for a sample of one', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue({
        ...sampled,
        qualitySampled: 1,
        qualitySampleDecided: 0,
        qualitySampleBad: 0,
      })
      const wrapper = mountFull()
      await flushPromises()
      expect(wrapper.text()).toContain('1 post was held back')
    })

    it('still says there is no sample when nothing was sampled', async () => {
      mockMessageStore.fetchModerationStats.mockResolvedValue({
        ...sampled,
        qualitySampled: 0,
        qualitySampleDecided: 0,
        qualitySampleBad: 0,
      })
      const wrapper = mountFull()
      await flushPromises()
      expect(wrapper.text()).toContain('No quality-check sample in this period')
    })
  })
})
