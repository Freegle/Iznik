import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModSysAdminFirstReply from '~/modtools/components/ModSysAdminFirstReply.vue'

// The matches table names the signal that picked each member, and the name has
// to match what the code behind it does - a sysadmin reads this page to decide
// whether a lever earns its mail. "Saved search" reads as a niche feature few
// people use; the signal is every search a member ran in the last six months,
// which is a different and much larger thing.

const fetchMetrics = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({ firstreply: { fetchMetrics } }),
}))

const STATS = {
  arms: [
    { arm: 'treated', posts: 10, replied: 5, taken: 3 },
    { arm: 'control', posts: 10, replied: 2, taken: 1 },
  ],
  passthrough: { web: 1, email: 1, unsized: 0 },
  matches: [
    {
      reason: 'wanted',
      mailed: 10,
      replied: 4,
      medianhours: 2,
      posts: 8,
      taken: 3,
    },
    {
      reason: 'search',
      mailed: 6,
      replied: 3,
      medianhours: 3,
      posts: 5,
      taken: 2,
    },
    {
      reason: 'frequent',
      mailed: 4,
      replied: 0,
      medianhours: null,
      posts: 4,
      taken: 0,
    },
  ],
  prompts: [],
  daily: [],
}

function createWrapper() {
  return mount(ModSysAdminFirstReply, {
    global: {
      stubs: {
        // Emitting on mount stands in for the sysadmin pressing Fetch.
        ModEmailDateFilter: {
          template: '<div class="date-filter" />',
          emits: ['fetch'],
          mounted() {
            this.$emit('fetch', { start: '2026-01-01', end: '2026-02-01' })
          },
        },
        NoticeMessage: { template: '<div class="notice"><slot /></div>' },
        'b-spinner': { template: '<div class="spinner" />' },
        'b-table-simple': { template: '<table><slot /></table>' },
        'b-thead': { template: '<thead><slot /></thead>' },
        'b-tbody': { template: '<tbody><slot /></tbody>' },
        'b-tr': { template: '<tr><slot /></tr>' },
        'b-th': { template: '<th><slot /></th>' },
        'b-td': { template: '<td><slot /></td>' },
      },
    },
  })
}

// Prose wraps wherever the formatter puts it, so compare on collapsed
// whitespace - otherwise these assertions break on a reflow that changed
// nothing a reader would notice.
async function renderedText() {
  fetchMetrics.mockResolvedValue(STATS)

  const wrapper = createWrapper()
  await flushPromises()

  return wrapper.text().replace(/\s+/g, ' ')
}

describe('ModSysAdminFirstReply match signals', () => {
  it('calls the search signal what it is - a recent search, not a saved one', async () => {
    const text = await renderedText()

    expect(text).toContain('Matching a recent search')
    expect(text).not.toContain('saved search')
  })

  it("says the wanted signal is the member's own outstanding post", async () => {
    expect(await renderedText()).toContain('Matching their WANTED or OFFER')
  })

  it('explains the six-month bound on searches, so the row is readable without the code', async () => {
    expect(await renderedText()).toContain(
      'searched for in the last six months'
    )
  })

  // A 90-day window still contains rows the propensity trial wrote. Dropping
  // them would silently shrink the mail bill the page is there to report, and
  // leaving them unlabelled would make the reason column read as a bug.
  it('renders a propensity row from the trial rather than hiding it', async () => {
    expect(await renderedText()).toContain('Frequent replier nearby')
  })

  it('says propensity is no longer sent, so the row is not read as current', async () => {
    expect(await renderedText()).toContain('no longer a signal')
  })
})
