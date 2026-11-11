import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxSearch from '~/modtools/components/ModSysAdminUxSearch.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const days = [
  {
    date: '2026-10-01',
    searches: 100,
    zero_result: 20,
    re_searched_60s: 10,
    browse_sessions: 200,
    browse_scrolled_out: 50,
  },
  {
    date: '2026-10-02',
    searches: 100,
    zero_result: 30,
    re_searched_60s: 20,
    browse_sessions: 200,
    browse_scrolled_out: 70,
  },
]

describe('ModSysAdminUxSearch', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.search.data = { days }
  })
  const make = (props = {}) =>
    mount(ModSysAdminUxSearch, { props, global: { stubs: uxStubs } })

  it('summarises the period with percentages and counts', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('25.0% (50/200)') // zero results
    expect(t).toContain('15.0% (30/200)') // searched again within a minute
    expect(t).toContain('30.0% (120/400)') // browse scrolled out
  })

  it('calls the repeated search the same thing in the list and in the table', () => {
    // The shared table stub draws no headings, so this one reports them.
    const labels = []
    const w = mount(ModSysAdminUxSearch, {
      props: { open: true },
      global: {
        stubs: {
          ...uxStubs,
          'b-table': {
            template: '<table class="table" />',
            props: ['items', 'fields'],
            setup(props) {
              labels.push(...props.fields.map((f) => f.label))
              return {}
            },
          },
        },
      },
    })
    expect(w.text()).toContain('Searched again within a minute:')
    expect(labels).toContain('Searched again within a minute')
    expect(labels).not.toContain('Searched again <60s')
  })

  it('lists each day with its own percentages', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('2026-10-01')
    expect(t).toContain('20.0% (20/100)')
    expect(t).toContain('30.0% (30/100)')
  })

  it('draws a chart of the rate of searches that found nothing, per day', () => {
    const w = make({ open: true })
    expect(w.find('.gchart').exists()).toBe(true)
    expect(w.vm.chartData).toEqual([
      [
        'Date',
        'Searches with no results (%)',
        'Searched again within a minute (%)',
      ],
      ['2026-10-01', 20, 10],
      ['2026-10-02', 30, 20],
    ])
  })

  it('does not draw the chart while closed', () => {
    expect(make().find('.gchart').exists()).toBe(false)
  })

  it('shows the empty message', () => {
    store.search.data = { days: [] }
    expect(make({ open: true }).text()).toContain('No search data')
    expect(make().find('.ux-summary').text()).toBe(
      'No search data for this period.'
    )
  })

  it('shows an error', () => {
    store.search.data = null
    store.search.error = 'err'
    expect(make().text()).toContain('err')
  })

  describe('the closed row', () => {
    it('says how many searches found nothing', () => {
      expect(make().find('.ux-summary').text()).toBe(
        '25.0% of 200 searches found nothing.'
      )
    })

    it('says so when nobody searched', () => {
      store.search.data = {
        days: [{ ...days[0], searches: 0, zero_result: 0, re_searched_60s: 0 }],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'No searches in this period.'
      )
    })
  })

  it('says when the server did not use the device that was asked for', () => {
    store.search.data = {
      days,
      filters: { device: '', signed_in: '' },
      ignored: ['device'],
    }
    store.answered.search = { device: 'tablet' }
    expect(make().find('.ux-note').text()).toBe(
      'Not filtered by device. Shows every device.'
    )
  })

  it('says the part is not switched on when the route is missing', () => {
    store.search.data = null
    store.search.missing = true
    expect(make().find('.ux-summary').text()).toBe(
      'This part is not switched on yet.'
    )
  })
})
