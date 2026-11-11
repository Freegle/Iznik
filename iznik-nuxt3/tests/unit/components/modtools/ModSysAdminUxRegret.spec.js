import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxRegret from '~/modtools/components/ModSysAdminUxRegret.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const signal = (name, counts, base, previous = 0) => ({
  signal: name,
  days: counts.map((count, i) => ({
    date: `2026-10-0${i + 1}`,
    count,
    base,
  })),
  total: counts.reduce((a, b) => a + b, 0),
  previous_total: previous,
})

describe('ModSysAdminUxRegret', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.regret.data = {
      signals: [signal('withdrawn_24h', [3, 7], 50, 4)],
    }
  })
  const make = (props = {}) =>
    mount(ModSysAdminUxRegret, { props, global: { stubs: uxStubs } })

  it('shows each signal in words with its total as a percentage of the base, with counts', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('Post withdrawn within 24 hours')
    expect(t).not.toContain('withdrawn_24h')
    expect(t).toContain('10.0% (10/100)')
  })

  it('gives an example of regret that is one of the signals it measures', () => {
    const t = make({ open: true }).text()
    expect(t).toContain(
      'Signs that someone wished they had done something differently, such as withdrawing a post within a day of posting it.'
    )
    // The rollup does not measure editing a post, so it is not the example.
    expect(t).not.toContain('editing a post')
  })

  it('prints a signal it has no words for as it is', () => {
    store.regret.data = { signals: [signal('edit_after_post', [3, 7], 50)] }
    expect(make({ open: true }).text()).toContain('edit_after_post')
  })

  it('shows the previous total and the change', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('4')
    expect(t).toContain('+6')
  })

  it('draws a daily chart with one column per signal, named in words', () => {
    const w = make({ open: true })
    expect(w.find('.gchart').exists()).toBe(true)
    expect(w.vm.chartData).toEqual([
      ['Date', 'Post withdrawn within 24 hours'],
      ['2026-10-01', 3],
      ['2026-10-02', 7],
    ])
  })

  it('draws its own legend in words, matching the chart colours, so no name is cut short', () => {
    store.regret.data = {
      signals: [
        signal('withdrawn_24h', [3, 7], 50),
        signal('duplicate_10m', [1, 2], 50),
      ],
    }
    const w = make({ open: true })
    const items = w.findAll('.legend li')
    expect(items.map((li) => li.text())).toEqual([
      'Post withdrawn within 24 hours',
      'Posted the same thing twice within 10 minutes',
    ])
    const colours = items.map((li) => li.find('.swatch').attributes('style'))
    expect(colours[0]).toContain('#3366cc')
    expect(colours[1]).toContain('#dc3912')
    // The chart is told the same colours, and has no legend of its own.
    const options = w.findComponent({ name: 'GChart' }).props('options')
    expect(options.legend).toEqual({ position: 'none' })
    expect(options.colors.slice(0, 2)).toEqual(['#3366cc', '#dc3912'])
  })

  it('does not draw the chart while closed, since a chart drawn in a hidden box has no size', () => {
    expect(make().find('.gchart').exists()).toBe(false)
  })

  it('shows the empty message', () => {
    store.regret.data = { signals: [] }
    expect(make({ open: true }).text()).toContain('No regret data')
    expect(make().find('.ux-summary').text()).toBe(
      'No regret data for this period.'
    )
  })

  it('shows an error', () => {
    store.regret.data = null
    store.regret.error = 'fail'
    expect(make().text()).toContain('fail')
  })

  describe('the closed row', () => {
    it('names the signal with the highest share of the times it could have happened', () => {
      store.regret.data = {
        signals: [
          signal('withdrawn_24h', [5, 5], 500), // 10 of 1000: 1.0%
          signal('promise_reneged', [2, 2], 20), // 4 of 40: 10.0%
          signal('duplicate_10m', [30, 30], 1000), // 60 of 2000: 3.0%, the most in number
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Highest: Promise broken, 10.0% (4 of 40).'
      )
    })

    it('does not let a signal with no base win over one that has a share', () => {
      store.regret.data = {
        signals: [
          signal('promise_reneged', [9, 9], 0),
          signal('withdrawn_24h', [1, 1], 100),
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Highest: Post withdrawn within 24 hours, 1.0% (2 of 200).'
      )
    })

    it('still names one when none has a base', () => {
      store.regret.data = { signals: [signal('promise_reneged', [2, 1], 0)] }
      expect(make().find('.ux-summary').text()).toBe(
        'Highest: Promise broken, – (3 of 0).'
      )
    })
  })

  it('says when the server did not use the filters that were asked for', () => {
    store.regret.data = {
      signals: [signal('withdrawn_24h', [3], 50)],
      filters: { device: '', signed_in: '' },
      ignored: ['device', 'signed_in'],
    }
    store.answered.regret = { device: 'app', signed_in: '1' }
    expect(make().find('.ux-note').text()).toBe(
      'Not filtered by device or sign-in. Shows everyone.'
    )
  })

  it('says the part is not switched on when the route is missing', () => {
    store.regret.data = null
    store.regret.missing = true
    expect(make().find('.ux-summary').text()).toBe(
      'This part is not switched on yet.'
    )
  })
})
