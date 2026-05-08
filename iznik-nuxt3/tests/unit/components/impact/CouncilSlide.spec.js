import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import CouncilSlide from '~/components/impact/CouncilSlide.vue'

function makeProps(overrides = {}) {
  return {
    authorityName: 'Test Council',
    yearLabel: '2024',
    totalWeight: 100,
    totalBenefit: 50000,
    totalCO2: 51,
    totalGifts: 1234,
    totalMembers: 5678,
    groupCount: 3,
    ...overrides,
  }
}

describe('CouncilSlide — formattedWeight', () => {
  it('shows one decimal place when weight < 10', () => {
    const wrapper = mount(CouncilSlide, { props: makeProps({ totalWeight: 7.4 }) })
    expect(wrapper.text()).toContain('7.4')
  })

  it('rounds to integer when weight >= 10', () => {
    const wrapper = mount(CouncilSlide, { props: makeProps({ totalWeight: 12.7 }) })
    expect(wrapper.text()).toContain('13')
  })

  it('formats large numbers with locale separators', () => {
    const wrapper = mount(CouncilSlide, { props: makeProps({ totalWeight: 1500 }) })
    expect(wrapper.text()).toContain('1,500')
  })
})

describe('CouncilSlide — formattedCO2', () => {
  it('shows one decimal place when CO2 < 10', () => {
    const wrapper = mount(CouncilSlide, { props: makeProps({ totalCO2: 3.6 }) })
    expect(wrapper.text()).toContain('3.6')
  })

  it('rounds to integer when CO2 >= 10', () => {
    const wrapper = mount(CouncilSlide, { props: makeProps({ totalCO2: 51.7 }) })
    expect(wrapper.text()).toContain('52')
  })
})

describe('CouncilSlide — formattedBenefit', () => {
  it('rounds benefit to nearest integer', () => {
    const wrapper = mount(CouncilSlide, { props: makeProps({ totalBenefit: 49876.6 }) })
    expect(wrapper.text()).toContain('£49,877')
  })
})

describe('CouncilSlide — slideStyle', () => {
  it('applies scale 0.45 by default', () => {
    const wrapper = mount(CouncilSlide, { props: makeProps() })
    const style = wrapper.find('.impact-slide').attributes('style')
    expect(style).toContain('width: 900px')
    expect(style).toContain('height: 636px')
  })

  it('scales width/height proportionally for custom scale', () => {
    const wrapper = mount(CouncilSlide, { props: makeProps({ scale: 0.5 }) })
    const style = wrapper.find('.impact-slide').attributes('style')
    expect(style).toContain('width: 1000px')
    expect(style).toContain('height: 707px')
  })
})

describe('CouncilSlide — template', () => {
  it('renders authority name', () => {
    const wrapper = mount(CouncilSlide, {
      props: makeProps({ authorityName: 'Surrey County Council' }),
    })
    expect(wrapper.text()).toContain('Surrey County Council')
  })

  it('renders year label', () => {
    const wrapper = mount(CouncilSlide, {
      props: makeProps({ yearLabel: '2023 –\n2024' }),
    })
    expect(wrapper.text()).toContain('2023')
  })

  it('renders items freegled count', () => {
    const wrapper = mount(CouncilSlide, { props: makeProps({ totalGifts: 9876 }) })
    expect(wrapper.text()).toContain('9,876')
  })

  it('renders member count', () => {
    const wrapper = mount(CouncilSlide, {
      props: makeProps({ totalMembers: 12345 }),
    })
    expect(wrapper.text()).toContain('12,345')
  })

  it('renders group count with singular label for 1 community', () => {
    const wrapper = mount(CouncilSlide, { props: makeProps({ groupCount: 1 }) })
    expect(wrapper.text()).toContain('Freegle community')
    expect(wrapper.text()).not.toContain('Freegle communities')
  })

  it('renders group count with plural label for > 1 communities', () => {
    const wrapper = mount(CouncilSlide, { props: makeProps({ groupCount: 4 }) })
    expect(wrapper.text()).toContain('Freegle communities')
  })

  it('shows partial group count when > 0', () => {
    const wrapper = mount(CouncilSlide, {
      props: makeProps({ groupCount: 2, partialGroupCount: 1 }),
    })
    expect(wrapper.text()).toContain('+ 1 others part-serving this area')
  })

  it('hides partial group count when 0', () => {
    const wrapper = mount(CouncilSlide, {
      props: makeProps({ groupCount: 3, partialGroupCount: 0 }),
    })
    expect(wrapper.text()).not.toContain('part-serving')
  })

  it('renders testimonial quotes when provided', () => {
    const testimonials = [{ quote: 'Great service!', attribution: 'Alice' }]
    const wrapper = mount(CouncilSlide, {
      props: makeProps({ testimonials }),
    })
    expect(wrapper.text()).toContain('Great service!')
    expect(wrapper.text()).toContain('From Alice')
  })

  it('renders photo when photoUrl provided', () => {
    const wrapper = mount(CouncilSlide, {
      props: makeProps({ photoUrl: 'https://example.com/photo.jpg' }),
    })
    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)
    expect(img.attributes('src')).toBe('https://example.com/photo.jpg')
  })

  it('shows placeholder when no photoUrl', () => {
    const wrapper = mount(CouncilSlide, {
      props: makeProps({ photoUrl: null }),
    })
    expect(wrapper.find('.impact-photo-placeholder').exists()).toBe(true)
  })
})
