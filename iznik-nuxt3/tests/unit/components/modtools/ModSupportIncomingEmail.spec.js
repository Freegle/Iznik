import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import ModSupportIncomingEmail from '~/modtools/components/ModSupportIncomingEmail.vue'

// The value of this component is its classification of a routing outcome into a
// human label and a badge colour, and the way its two filters interact - the
// bounce filter borrows the outcome filter and the search box, so toggling it
// off has to put the user's own search back. Those are the decisions pinned here.

const mockStore = {
  incomingLoading: false,
  incomingCountsLoading: false,
  incomingError: null,
  incomingEntries: [],
  incomingCountsTotal: 0,
  incomingOutcomeCounts: null,
  incomingOutcomeFilter: '',
  incomingSearch: '',
  incomingTimeRange: '',
  incomingHasMore: false,
  filteredIncomingEntries: [],
  bounceEntries: [],
  formattedStats: null,
  setFilters: vi.fn(),
  fetchIncomingEmails: vi.fn().mockResolvedValue({}),
  fetchIncomingCounts: vi.fn().mockResolvedValue({}),
  fetchBounceEvents: vi.fn().mockResolvedValue({}),
  fetchStats: vi.fn().mockResolvedValue({}),
  fetchTimeSeries: vi.fn().mockResolvedValue({}),
}

vi.mock('~/modtools/stores/emailtracking', () => ({
  useEmailTrackingStore: () => mockStore,
}))

vi.mock('~/modtools/composables/useEmailDateFormat', () => ({
  useEmailDateFormat: () => ({
    formatEmailDate: (d) => `formatted:${d}`,
  }),
}))

vi.mock('~/components/NoticeMessage.vue', () => ({
  default: {
    template: '<div class="notice" :class="variant"><slot /></div>',
    props: ['variant'],
  },
}))

vi.mock('~/modtools/components/ModEmailDateFilter.vue', () => ({
  default: {
    template: '<div class="date-filter" />',
    props: ['loading', 'fetchLabel', 'defaultPreset'],
    emits: ['fetch'],
  },
}))

vi.mock('~/modtools/components/ModIncomingEmailDetail.vue', () => ({
  default: {
    template: '<div class="detail" />',
    props: ['modelValue', 'entry'],
  },
}))

vi.mock('~/modtools/components/ModIncomingEmailCharts.vue', () => ({
  default: { template: '<div class="charts" />' },
}))

vi.mock('~/modtools/components/ModEmailStatCard.vue', () => ({
  default: {
    template:
      '<button class="stat-card" :data-label="label" :data-active="active" @click="$emit(\'click\')">{{ value }}</button>',
    props: ['value', 'label', 'clickable', 'active', 'valueColor'],
    emits: ['click'],
  },
}))

describe('ModSupportIncomingEmail', () => {
  function mountComponent() {
    return mount(ModSupportIncomingEmail, {
      global: {
        stubs: {
          'b-input': {
            template:
              '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value); $emit(\'input\')" />',
            props: ['modelValue', 'placeholder', 'size'],
            emits: ['update:modelValue', 'input'],
          },
          'b-table': {
            template: '<table class="table" />',
            props: [
              'items',
              'fields',
              'striped',
              'hover',
              'small',
              'responsive',
              'sortBy',
            ],
          },
          'b-badge': {
            template: '<span class="badge"><slot /></span>',
            props: ['variant'],
          },
          'b-button': {
            template:
              '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['size', 'variant', 'disabled'],
          },
          'v-icon': { template: '<i />', props: ['name'] },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    Object.assign(mockStore, {
      incomingLoading: false,
      incomingCountsLoading: false,
      incomingError: null,
      incomingEntries: [],
      incomingCountsTotal: 0,
      incomingOutcomeCounts: null,
      incomingOutcomeFilter: '',
      incomingSearch: '',
      incomingHasMore: false,
      filteredIncomingEntries: [],
      bounceEntries: [],
      formattedStats: null,
    })
  })

  describe('grouping outcomes', () => {
    it('splits counts into delivered, not delivered and error', () => {
      mockStore.incomingOutcomeCounts = {
        Approved: 5,
        Dropped: 2,
        Error: 1,
        ToUser: 3,
        IncomingSpam: 4,
      }
      const wrapper = mountComponent()

      expect(wrapper.vm.deliveredOutcomes.map((o) => o.outcome)).toEqual([
        'Approved',
        'ToUser',
      ])
      expect(wrapper.vm.notDeliveredOutcomes.map((o) => o.outcome)).toEqual([
        'Dropped',
        'IncomingSpam',
      ])
      expect(wrapper.vm.errorOutcomes.map((o) => o.outcome)).toEqual(['Error'])
    })

    it('orders delivered outcomes by the pipeline order, not the order they arrived', () => {
      // Pending before Approved before ToUser is the order an email travels in,
      // and is why the sort exists at all.
      mockStore.incomingOutcomeCounts = { ToUser: 1, Pending: 2, Approved: 3 }
      const wrapper = mountComponent()

      expect(wrapper.vm.deliveredOutcomes.map((o) => o.outcome)).toEqual([
        'Pending',
        'Approved',
        'ToUser',
      ])
    })

    it('drops an outcome it does not recognise rather than mis-filing it', () => {
      mockStore.incomingOutcomeCounts = { Approved: 1, SomethingNew: 9 }
      const wrapper = mountComponent()

      const all = [
        ...wrapper.vm.deliveredOutcomes,
        ...wrapper.vm.notDeliveredOutcomes,
        ...wrapper.vm.errorOutcomes,
      ].map((o) => o.outcome)
      expect(all).not.toContain('SomethingNew')
    })

    it('copes with counts not having loaded', () => {
      mockStore.incomingOutcomeCounts = null
      const wrapper = mountComponent()

      expect(wrapper.vm.deliveredOutcomes).toEqual([])
      expect(wrapper.vm.notDeliveredOutcomes).toEqual([])
      expect(wrapper.vm.errorOutcomes).toEqual([])
    })
  })

  describe('total count', () => {
    it('prefers the server total over the number of rows fetched so far', () => {
      mockStore.incomingCountsTotal = 900
      mockStore.incomingEntries = [{ id: 1 }, { id: 2 }]
      const wrapper = mountComponent()

      expect(wrapper.vm.totalIncomingCount).toBe(900)
    })

    it('falls back to the rows fetched when the total has not arrived', () => {
      mockStore.incomingCountsTotal = 0
      mockStore.incomingEntries = [{ id: 1 }, { id: 2 }]
      const wrapper = mountComponent()

      expect(wrapper.vm.totalIncomingCount).toBe(2)
    })
  })

  describe('bounce stats', () => {
    it('prefers the database figures over the log-derived ones', () => {
      mockStore.formattedStats = {
        totalBounces: 30,
        permanentBounces: 20,
        temporaryBounces: 10,
      }
      mockStore.bounceEntries = [{ is_permanent: true }]
      const wrapper = mountComponent()

      expect(wrapper.vm.bounceStats).toEqual({
        total: 30,
        permanent: 20,
        temporary: 10,
      })
    })

    it('falls back to the logs, splitting on is_permanent', () => {
      mockStore.formattedStats = { totalBounces: 0 }
      mockStore.bounceEntries = [
        { is_permanent: true },
        { is_permanent: true },
        { is_permanent: false },
      ]
      const wrapper = mountComponent()

      expect(wrapper.vm.bounceStats).toEqual({
        total: 3,
        permanent: 2,
        temporary: 1,
      })
    })
  })

  describe('outcome labels', () => {
    // The log fields are snake_case on the wire, hence the quoted keys.
    const label = (wrapper, outcome, reason) =>
      wrapper.vm.formatOutcomeLabel({
        routing_outcome: outcome,
        routing_reason: reason,
      })

    it('names the kind of system mail behind a ToSystem routing', () => {
      const w = mountComponent()

      expect(label(w, 'ToSystem', 'Bounce detected')).toBe('System: Bounce')
      expect(label(w, 'ToSystem', 'FBL report')).toBe('System: FBL')
      expect(label(w, 'ToSystem', 'Item taken')).toBe('System: Outcome')
      expect(label(w, 'ToSystem', 'Item received')).toBe('System: Outcome')
      expect(label(w, 'ToSystem', 'Digest off')).toBe('System: Unsub')
      expect(label(w, 'ToSystem', 'Newsletters off')).toBe('System: Unsub')
      expect(label(w, 'ToSystem', 'Subscribe command')).toBe('System: Sub')
      expect(label(w, 'ToSystem', 'Closed group')).toBe('System: Closed')
      expect(label(w, 'ToSystem', 'something else entirely')).toBe('System')
    })

    it('says why a mail was dropped', () => {
      const w = mountComponent()

      expect(label(w, 'Dropped', 'Auto-reply detected')).toBe(
        'Dropped: Auto-reply'
      )
      expect(label(w, 'Dropped', 'Self-sent')).toBe('Dropped: Self')
      expect(label(w, 'Dropped', 'Known spammer')).toBe('Dropped: Spammer')
      expect(label(w, 'Dropped', 'unrecognised')).toBe('Dropped')
    })

    it('says what failed on an error', () => {
      const w = mountComponent()

      expect(label(w, 'Error', 'Could not parse message')).toBe('Error: Parse')
      expect(label(w, 'Error', 'Bounce handling blew up')).toBe('Error: Bounce')
      expect(label(w, 'Error', 'unrecognised')).toBe('Error')
    })

    it('calls a missing outcome Unknown rather than showing a blank badge', () => {
      const w = mountComponent()

      expect(label(w, undefined, '')).toBe('Unknown')
      expect(label(w, '', 'Bounce detected')).toBe('Unknown')
    })

    it('depends on the writer sending PascalCase, which normalizeOutcome does not repair', () => {
      // normalizeOutcome only uppercases the FIRST character, so 'tosystem'
      // becomes 'Tosystem' and matches no branch. That is safe today only
      // because the value comes from the RoutingResult PHP enum
      // (iznik-batch/app/Services/Mail/Incoming/RoutingResult.php), whose cases
      // are already 'ToSystem', 'IncomingSpam' and so on. Pinned so that a
      // future writer emitting lower case fails here rather than quietly
      // showing every mail as an unrecognised outcome.
      const w = mountComponent()

      expect(label(w, 'tosystem', 'Bounce detected')).toBe('Tosystem')
      expect(w.vm.outcomeVariant('tosystem')).toBe('light')
    })

    it('leaves an outcome alone when there is no reason to elaborate on', () => {
      const w = mountComponent()

      expect(label(w, 'Approved', '')).toBe('Approved')
      expect(label(w, 'ToSystem', '')).toBe('ToSystem')
    })
  })

  describe('badge colours', () => {
    it('colours each known outcome by whether it got through', () => {
      const w = mountComponent()

      expect(w.vm.outcomeVariant('Approved')).toBe('success')
      expect(w.vm.outcomeVariant('Pending')).toBe('warning')
      expect(w.vm.outcomeVariant('IncomingSpam')).toBe('danger')
      expect(w.vm.outcomeVariant('ToVolunteers')).toBe('primary')
    })

    it('falls back to light for anything unrecognised', () => {
      const w = mountComponent()

      expect(w.vm.outcomeVariant('SomethingNew')).toBe('light')
      expect(w.vm.outcomeVariant(null)).toBe('light')
    })
  })

  describe('outcome filter', () => {
    it('applies the outcome and refetches', () => {
      const w = mountComponent()

      w.vm.filterOutcome('Dropped')

      expect(mockStore.incomingOutcomeFilter).toBe('Dropped')
      expect(mockStore.fetchIncomingEmails).toHaveBeenCalled()
    })

    it('clicking the active outcome again clears it', () => {
      mockStore.incomingOutcomeFilter = 'Dropped'
      const w = mountComponent()

      w.vm.filterOutcome('Dropped')

      expect(mockStore.incomingOutcomeFilter).toBe('')
    })

    it('clears any bounce filter, which owns the same outcome field', () => {
      const w = mountComponent()
      w.vm.filterBounce('permanent')
      expect(w.vm.bounceFilter).toBe('permanent')

      w.vm.filterOutcome('Approved')

      expect(w.vm.bounceFilter).toBeNull()
    })
  })

  describe('bounce filter', () => {
    it('narrows to ToSystem with a search term matching the bounce type', () => {
      const w = mountComponent()

      w.vm.filterBounce('permanent')
      expect(mockStore.incomingOutcomeFilter).toBe('ToSystem')
      expect(mockStore.incomingSearch).toBe('permanent bounce')

      w.vm.filterBounce('temporary')
      expect(mockStore.incomingSearch).toBe('temporary bounce')

      w.vm.filterBounce('all')
      expect(mockStore.incomingSearch).toBe('bounce')
    })

    it('toggling off restores the search the user typed, not an empty box', () => {
      // The bounce filter commandeers the search field, so turning it off has
      // to hand the field back rather than silently widening the results.
      const w = mountComponent()
      w.vm.searchInput = 'angry member'
      w.vm.filterBounce('all')
      expect(mockStore.incomingSearch).toBe('bounce')

      w.vm.filterBounce('all')

      expect(w.vm.bounceFilter).toBeNull()
      expect(mockStore.incomingOutcomeFilter).toBe('')
      expect(mockStore.incomingSearch).toBe('angry member')
    })

    it('refetches whichever way it was toggled', () => {
      const w = mountComponent()

      w.vm.filterBounce('all')
      w.vm.filterBounce('all')

      expect(mockStore.fetchIncomingEmails).toHaveBeenCalledTimes(2)
    })
  })

  describe('fetching', () => {
    it('a Loki range goes to the time range and no stats filter is set', () => {
      const w = mountComponent()

      w.vm.onFilterFetch({ lokiRange: '24h' })

      expect(mockStore.incomingTimeRange).toBe('24h')
      expect(mockStore.setFilters).not.toHaveBeenCalled()
    })

    it('an explicit start/end sets both the range and the stats filter', () => {
      const w = mountComponent()

      w.vm.onFilterFetch({ start: '2026-08-01', end: '2026-08-02' })

      expect(mockStore.incomingTimeRange).toBe('2026-08-01')
      expect(mockStore.setFilters).toHaveBeenCalledWith({
        start: '2026-08-01',
        end: '2026-08-02',
      })
    })

    it('pulls counts and bounce data as well as the rows, so the cards agree with the table', () => {
      const w = mountComponent()

      w.vm.onFilterFetch({ lokiRange: '24h' })

      expect(mockStore.fetchIncomingEmails).toHaveBeenCalled()
      expect(mockStore.fetchIncomingCounts).toHaveBeenCalled()
      expect(mockStore.fetchBounceEvents).toHaveBeenCalled()
      expect(mockStore.fetchStats).toHaveBeenCalled()
      expect(mockStore.fetchTimeSeries).toHaveBeenCalled()
    })

    it('load more asks for the next page rather than restarting', () => {
      const w = mountComponent()

      w.vm.loadMore()

      expect(mockStore.fetchIncomingEmails).toHaveBeenCalledWith(true)
    })
  })

  describe('row detail', () => {
    it('opens the modal on the row that was clicked', () => {
      const w = mountComponent()
      const entry = { id: 7, subject: 'Hello' }

      w.vm.onRowClick(entry)

      expect(w.vm.selectedEntry).toEqual(entry)
      expect(w.vm.showDetail).toBe(true)
    })

    it('starts with the modal closed and nothing selected', () => {
      const w = mountComponent()

      expect(w.vm.showDetail).toBe(false)
      expect(w.vm.selectedEntry).toBeNull()
    })
  })
})
