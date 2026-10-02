import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

import ModPartnershipTimeline from '~/modtools/components/ModPartnershipTimeline.vue'

const deal = (overrides = {}) => ({
  id: 1,
  authorityid: 10,
  name: 'Essex County Council',
  startdate: '2025-01-01',
  enddate: '2027-12-31',
  amount: 9300,
  status: 'Confirmed',
  ...overrides,
})

describe('ModPartnershipTimeline', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-09-30T12:00:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('draws nothing when there are no deals', () => {
    const wrapper = mount(ModPartnershipTimeline, {
      props: { partnerships: [] },
    })

    expect(wrapper.find('.timeline').exists()).toBe(false)
  })

  it('draws one row per council, with a bar for each deal', () => {
    const wrapper = mount(ModPartnershipTimeline, {
      props: {
        partnerships: [
          deal({ id: 1, startdate: '2024-06-01', enddate: '2024-12-31' }),
          deal({ id: 2 }),
          deal({ id: 3, authorityid: 11, name: 'Cheltenham' }),
        ],
      },
    })

    const rows = wrapper.findAll('.tl-row')
    expect(rows).toHaveLength(2)
    // Alphabetical by council.
    expect(rows[0].find('.label').text()).toBe('Cheltenham')
    expect(rows[1].findAll('.bar')).toHaveLength(2)
  })

  it('colours a bar by where the deal is up to and describes it on hover', () => {
    const wrapper = mount(ModPartnershipTimeline, {
      props: { partnerships: [deal({ status: 'Overdue' })] },
    })

    const bar = wrapper.find('.bar')
    expect(bar.classes()).toContain('status-Overdue')
    expect(bar.attributes('title')).toContain('Overdue')
    expect(bar.attributes('title')).toContain('3 years')
    expect(bar.attributes('title')).toContain('£9,300')
  })

  it('marks when to ask about renewal, three months before the end', () => {
    const wrapper = mount(ModPartnershipTimeline, {
      props: { partnerships: [deal()] },
    })

    expect(wrapper.find('.ask').attributes('title')).toBe(
      'Ask about renewal by 30 Sept 2027'
    )
  })

  it('leaves out deals that ended before the window', () => {
    const wrapper = mount(ModPartnershipTimeline, {
      props: {
        partnerships: [
          deal({ startdate: '2019-01-01', enddate: '2020-01-01' }),
        ],
      },
    })

    expect(wrapper.findAll('.tl-row')).toHaveLength(0)
  })

  it('tells the page which deal was clicked', async () => {
    const wrapper = mount(ModPartnershipTimeline, {
      props: { partnerships: [deal({ id: 42 })] },
    })

    await wrapper.find('.bar').trigger('click')

    expect(wrapper.emitted('select')[0]).toEqual([42])
  })
})
