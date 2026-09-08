import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import Chips from '~/components/chatshell/Chips.vue'

describe('Chips', () => {
  const options = [
    { value: 'a', label: 'A' },
    { value: 'b', label: 'B' },
    { value: 'c', label: 'C' },
    { value: 'd', label: 'D' },
  ]
  it('renders at most three real buttons and emits the picked option', async () => {
    const wrapper = mount(Chips, { props: { options }, global: { stubs: { 'v-icon': true } } })
    const buttons = wrapper.findAll('button')
    expect(buttons).toHaveLength(3)
    await buttons[1].trigger('click')
    expect(wrapper.emitted('pick')[0][0]).toEqual({ value: 'b', label: 'B' })
  })
  it('disables buttons while busy and renders nothing with no options', () => {
    const busy = mount(Chips, { props: { options, disabled: true }, global: { stubs: { 'v-icon': true } } })
    expect(busy.find('button').attributes('disabled')).toBeDefined()
    const empty = mount(Chips, { props: { options: [] }, global: { stubs: { 'v-icon': true } } })
    expect(empty.find('button').exists()).toBe(false)
  })
})
