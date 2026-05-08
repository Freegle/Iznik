import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import NationalSlide from '~/components/impact/NationalSlide.vue'

function makeProps(overrides = {}) {
  return {
    year: 2024,
    totalWeight: 11000,
    totalBenefit: 7854000,
    totalCO2: 5610,
    totalGifts: 664408,
    totalMembers: 4500000,
    groupCount: 500,
    ...overrides,
  }
}

describe('NationalSlide — formattedWeight', () => {
  it('shows one decimal place when weight < 10', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ totalWeight: 7.4 }) })
    expect(wrapper.text()).toContain('7.4')
  })

  it('rounds to integer when weight >= 10', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ totalWeight: 12.7 }) })
    expect(wrapper.text()).toContain('13')
  })

  it('formats large numbers with locale separators', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ totalWeight: 11000 }) })
    expect(wrapper.text()).toContain('11,000')
  })
})

describe('NationalSlide — formattedCO2', () => {
  it('shows one decimal place when CO2 < 10', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ totalCO2: 3.6 }) })
    expect(wrapper.text()).toContain('3.6')
  })

  it('rounds to integer when CO2 >= 10', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ totalCO2: 5610.7 }) })
    expect(wrapper.text()).toContain('5,611')
  })
})

describe('NationalSlide — formattedBenefit', () => {
  it('formats as millions when benefit >= 1 million', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ totalBenefit: 7900000 }) })
    expect(wrapper.text()).toContain('£7.9 million')
  })

  it('formats as thousands when benefit < 1 million', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ totalBenefit: 714000 }) })
    expect(wrapper.text()).toContain('£714k')
  })
})

describe('NationalSlide — formattedMembers', () => {
  it('formats as millions when members >= 1 million', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ totalMembers: 4500000 }) })
    expect(wrapper.text()).toContain('4.5 million')
  })

  it('formats as thousands when members < 1 million', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ totalMembers: 250000 }) })
    expect(wrapper.text()).toContain('250k')
  })
})

describe('NationalSlide — slideStyle', () => {
  it('applies scale 0.45 by default', () => {
    const wrapper = mount(NationalSlide, { props: makeProps() })
    const style = wrapper.find('.national-slide').attributes('style')
    expect(style).toContain('width: 900px')
    expect(style).toContain('height: 636px')
  })

  it('applies custom scale', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ scale: 0.3 }) })
    const style = wrapper.find('.national-slide').attributes('style')
    expect(style).toContain('width: 600px')
    expect(style).toContain('height: 424px')
  })
})

describe('NationalSlide — template', () => {
  it('shows the year', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ year: 2024 }) })
    expect(wrapper.find('.ns-year-badge').text()).toBe('2024')
  })

  it('shows group count', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ groupCount: 500 }) })
    expect(wrapper.find('.ns-comm-n').text()).toContain('500')
  })

  it('shows total gifts', () => {
    const wrapper = mount(NationalSlide, { props: makeProps({ totalGifts: 664408 }) })
    expect(wrapper.find('.ns-notepad-big').text()).toContain('664,408')
  })

  it('renders the footer', () => {
    const wrapper = mount(NationalSlide, { props: makeProps() })
    expect(wrapper.find('.ns-footer').text()).toContain('ilovefreegle.org')
  })

  it('renders established year', () => {
    const wrapper = mount(NationalSlide, { props: makeProps() })
    expect(wrapper.find('.ns-established-year').text()).toBe('2009')
  })
})
