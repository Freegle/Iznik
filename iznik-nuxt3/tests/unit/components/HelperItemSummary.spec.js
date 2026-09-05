import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import HelperItemSummary from '~/components/HelperItemSummary.vue'

const mountOpts = { global: { stubs: { 'b-badge': true } } }

describe('HelperItemSummary', () => {
  it('renders FSM-group badges for the item states', () => {
    const w = mount(HelperItemSummary, {
      ...mountOpts,
      props: {
        itemStates: [
          { state: 'ALLOCATED' },
          { state: 'QUALIFIED' },
          { state: 'GATHERING' },
          { state: 'REJECTED' },
        ],
      },
    })
    expect(w.find('[data-testid="helper-itemsummary"]').exists()).toBe(true)
    expect(w.vm.summary).toMatchObject({
      allocated: 1,
      pool: 1,
      outreach: 1,
      inactive: 1,
      total: 4,
    })
    expect(w.find('[data-testid="sum-allocated"]').exists()).toBe(true)
    expect(w.find('[data-testid="sum-pool"]').exists()).toBe(true)
    expect(w.find('[data-testid="sum-outreach"]').exists()).toBe(true)
    expect(w.find('[data-testid="sum-inactive"]').exists()).toBe(true)
  })

  it('renders nothing when there are no item states', () => {
    const w = mount(HelperItemSummary, {
      ...mountOpts,
      props: { itemStates: [] },
    })
    expect(w.find('[data-testid="helper-itemsummary"]').exists()).toBe(false)
  })
})
