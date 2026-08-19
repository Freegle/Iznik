import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import TogetherPage from '~/pages/together.vue'

globalThis.useHead = () => {}

describe('pages/together.vue', () => {
  it('renders the hero, give/get and organisation cards', () => {
    const wrapper = mount(TogetherPage)

    expect(wrapper.text()).toContain(
      'Freegle supports charities & community organisations'
    )
    expect(wrapper.text()).toContain(
      'Individuals and organisations can give and get with Freegle'
    )

    // Every card title from the `cards` array should render, proving the
    // v-for actually iterates over the real data rather than an empty list.
    expect(wrapper.text()).toContain('Register as a Charity Partner')
    expect(wrapper.text()).toContain('Ask for stuff')
    expect(wrapper.text()).toContain('Give stuff away')
    expect(wrapper.text()).toContain('Post a volunteer opportunity')
    expect(wrapper.text()).toContain('Promote your community events')

    const links = wrapper.findAll('a')
    expect(links.some((a) => a.attributes('href') === '/charity')).toBe(true)
    expect(links.some((a) => a.attributes('href') === '/ask')).toBe(true)
    expect(links.some((a) => a.attributes('href') === '/give')).toBe(true)
  })
})
