import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import Bubble from '~/components/chatshell/Bubble.vue'

describe('Bubble', () => {
  it('puts the member on the right and Freegle on the left with a time', () => {
    const me = mount(Bubble, { props: { from: 'me', time: '10:02' }, slots: { default: 'hello' } })
    expect(me.classes()).toContain('from-me')
    expect(me.text()).toContain('hello')
    expect(me.text()).toContain('10:02')
    const f = mount(Bubble, { props: { from: 'freegle' }, slots: { default: 'hi' } })
    expect(f.classes()).toContain('from-freegle')
  })
  it('shows a coloured name only for other people', () => {
    const them = mount(Bubble, { props: { from: 'them', name: 'Jane', colour: '#123456' }, slots: { default: 'x' } })
    expect(them.find('.bubble-name').text()).toBe('Jane')
    const me = mount(Bubble, { props: { from: 'me', name: 'Me' }, slots: { default: 'x' } })
    expect(me.find('.bubble-name').exists()).toBe(false)
  })
})
