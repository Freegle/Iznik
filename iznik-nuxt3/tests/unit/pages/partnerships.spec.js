import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import PartnershipsPage from '~/pages/partnerships.vue'

globalThis.useHead = () => {}

describe('pages/partnerships.vue', () => {
  it('promotes The Reusual Suspects network with its logo and join address', () => {
    const wrapper = mount(PartnershipsPage)
    const section = wrapper.find('.partnerships__reusual')

    expect(section.exists()).toBe(true)
    expect(section.text()).toContain('The Reusual Suspects')
    expect(section.text()).toContain(
      'everyone in the UK & Ireland working in reuse'
    )
    expect(section.text()).toContain('Share your wins')
    expect(section.text()).toContain(
      'Make unexpected connections across sectors'
    )
    expect(section.text()).toContain(
      'Swap ideas, tips and problem-solving strategies'
    )
    expect(section.text()).toContain(
      'Find ways to make more reuse happen together'
    )

    const logo = section.find('img')
    expect(logo.attributes('src')).toBe('/partnerships/reusual-suspects.png')
    expect(logo.attributes('alt')).toBe('The Reusual Suspects')

    const join = section
      .findAll('a')
      .find((a) => a.attributes('href')?.startsWith('mailto:'))
    expect(join.attributes('href')).toBe('mailto:reusualsuspects@gmail.com')
  })

  it('places the network before Get in Touch', () => {
    const wrapper = mount(PartnershipsPage)
    const headings = wrapper.findAll('h2').map((h) => h.text())

    expect(headings.indexOf('The Reusual Suspects')).toBeGreaterThan(-1)
    expect(headings.indexOf('The Reusual Suspects')).toBeLessThan(
      headings.indexOf('Get in Touch')
    )
  })
})
