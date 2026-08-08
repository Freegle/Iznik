import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import ModPartnershipTotal from '~/modtools/components/ModPartnershipTotal.vue'

function mountTotal(props) {
  return mount(ModPartnershipTotal, { props })
}

describe('ModPartnershipTotal', () => {
  it('shows the label and the value', () => {
    const wrapper = mountTotal({ label: 'Live deals', value: 4 })

    expect(wrapper.text()).toContain('Live deals')
    expect(wrapper.text()).toContain('4')
  })

  it('adds a pound sign and thousands separators for money', () => {
    const wrapper = mountTotal({ label: 'Paid', value: 12345, money: true })

    expect(wrapper.text()).toContain('£12,345')
  })

  it('rounds pence away - they are noise at this scale', () => {
    const wrapper = mountTotal({ label: 'Paid', value: 1200.49, money: true })

    expect(wrapper.text()).toContain('£1,200')
    expect(wrapper.text()).not.toContain('.49')
  })

  it('omits the pound sign for plain counts', () => {
    const wrapper = mountTotal({ label: 'Live deals', value: 3 })

    expect(wrapper.text()).not.toContain('£')
  })

  it('uses the requested border variant to flag a figure needing attention', () => {
    const wrapper = mountTotal({
      label: 'Outstanding',
      value: 500,
      money: true,
      variant: 'warning',
    })

    expect(wrapper.find('.totalbox').classes()).toContain('border-warning')
  })

  it('falls back to a neutral border', () => {
    const wrapper = mountTotal({ label: 'Paid', value: 0, money: true })

    expect(wrapper.find('.totalbox').classes()).toContain('border-secondary')
  })
})
