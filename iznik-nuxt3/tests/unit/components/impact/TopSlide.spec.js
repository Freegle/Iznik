import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import TopSlide from '~/components/impact/TopSlide.vue'

const SAMPLE_COMMUNITIES = [
  { name: 'Oxford Freegle', weightKg: 240000 },
  { name: 'Croydon Freegle', weightKg: 202000 },
  { name: 'Reading Freegle', weightKg: 180000 },
  { name: 'Sheffield Freegle', weightKg: 174000 },
  { name: 'Edinburgh Freegle', weightKg: 174000 },
]

function makeProps(overrides = {}) {
  return {
    year: 2024,
    communities: SAMPLE_COMMUNITIES,
    ...overrides,
  }
}

describe('TopSlide — template', () => {
  it('shows the year badge', () => {
    const wrapper = mount(TopSlide, { props: makeProps() })
    expect(wrapper.find('.ts-year-badge').text()).toBe('2024')
  })

  it('renders all leaderboard entries', () => {
    const wrapper = mount(TopSlide, { props: makeProps() })
    const entries = wrapper.findAll('.ts-entry')
    expect(entries).toHaveLength(5)
  })

  it('shows the rank-1 class for the first entry', () => {
    const wrapper = mount(TopSlide, { props: makeProps() })
    expect(wrapper.findAll('.ts-entry')[0].classes()).toContain('rank-1')
  })

  it('shows the rank-2 class for the second entry', () => {
    const wrapper = mount(TopSlide, { props: makeProps() })
    expect(wrapper.findAll('.ts-entry')[1].classes()).toContain('rank-2')
  })

  it('shows community names', () => {
    const wrapper = mount(TopSlide, { props: makeProps() })
    expect(wrapper.text()).toContain('Oxford Freegle')
    expect(wrapper.text()).toContain('Croydon Freegle')
  })

  it('renders the title', () => {
    const wrapper = mount(TopSlide, { props: makeProps() })
    expect(wrapper.find('.ts-title').text()).toContain('SUPERSTARS')
  })

  it('renders the hashtag', () => {
    const wrapper = mount(TopSlide, { props: makeProps() })
    expect(wrapper.find('.ts-hashtag').text()).toContain('ChooseToReuse')
  })

  it('renders the footer', () => {
    const wrapper = mount(TopSlide, { props: makeProps() })
    expect(wrapper.find('.ts-footer').text()).toContain('ilovefreegle.org')
  })
})

describe('TopSlide — formattedTonnes', () => {
  it('converts kg to tonnes in leaderboard', () => {
    const wrapper = mount(TopSlide, {
      props: makeProps({
        communities: [{ name: 'Test', weightKg: 240000 }],
      }),
    })
    expect(wrapper.find('.ts-tonnes').text()).toBe('240')
  })

  it('shows one decimal for sub-10 tonne entries', () => {
    const wrapper = mount(TopSlide, {
      props: makeProps({
        communities: [{ name: 'Test', weightKg: 7400 }],
      }),
    })
    expect(wrapper.find('.ts-tonnes').text()).toBe('7.4')
  })
})

describe('TopSlide — slideStyle', () => {
  it('applies scale 0.45 by default', () => {
    const wrapper = mount(TopSlide, { props: makeProps() })
    const style = wrapper.find('.top-slide').attributes('style')
    expect(style).toContain('width: 900px')
    expect(style).toContain('height: 636px')
  })

  it('applies custom scale', () => {
    const wrapper = mount(TopSlide, { props: makeProps({ scale: 0.3 }) })
    const style = wrapper.find('.top-slide').attributes('style')
    expect(style).toContain('width: 600px')
    expect(style).toContain('height: 424px')
  })
})

describe('TopSlide — empty communities', () => {
  it('renders without entries when communities is empty', () => {
    const wrapper = mount(TopSlide, { props: makeProps({ communities: [] }) })
    expect(wrapper.findAll('.ts-entry')).toHaveLength(0)
    expect(wrapper.find('.ts-title').exists()).toBe(true)
  })
})
