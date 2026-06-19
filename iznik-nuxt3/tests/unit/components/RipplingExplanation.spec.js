import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import RipplingExplanation from '~/components/RipplingExplanation.vue'

describe('RipplingExplanation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function mountComponent() {
    return mount(RipplingExplanation)
  }

  describe('rendering', () => {
    it('renders a div container', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('div').exists()).toBe(true)
    })

    it('renders the Two ways to look at the same thing heading', () => {
      const wrapper = mountComponent()
      const headings = wrapper.findAll('h5')
      const headingTexts = headings.map((h) => h.text())
      expect(headingTexts).toContain('Two ways to look at the same thing')
    })

    it('renders the How reach works in practice heading', () => {
      const wrapper = mountComponent()
      const headings = wrapper.findAll('h5')
      const headingTexts = headings.map((h) => h.text())
      expect(headingTexts).toContain('How reach works in practice')
    })

    it('renders exactly two section headings', () => {
      const wrapper = mountComponent()
      expect(wrapper.findAll('h5').length).toBe(2)
    })
  })

  describe('two ways section', () => {
    it('renders the Where can a post be seen question', () => {
      const wrapper = mountComponent()
      const listItems = wrapper.findAll('li')
      const texts = listItems.map((li) => li.text())
      expect(texts.some((t) => t.includes('Where can a post be seen?'))).toBe(
        true
      )
    })

    it('renders the Which posts reach me here question', () => {
      const wrapper = mountComponent()
      const listItems = wrapper.findAll('li')
      const texts = listItems.map((li) => li.text())
      expect(texts.some((t) => t.includes('Which posts reach me here?'))).toBe(
        true
      )
    })

    it('explains that the two questions are two ends of the same idea', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('two ends of the same idea')
    })

    it('mentions the catchment area', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('catchment')
    })
  })

  describe('rippling out phrasing', () => {
    it('uses the phrase rippling out', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('rippling out')
    })

    it('uses the phrase ripples out', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('ripples out')
    })
  })

  describe('how reach works section', () => {
    it('explains that reach starts with nearest people', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('nearest')
    })

    it('explains that reach widens over time', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('widens')
    })

    it('mentions rural areas getting wider reach', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('rural')
    })

    it('mentions busy towns getting smaller reach', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('towns')
    })

    it('mentions fewer more relevant notifications', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('fewer, more relevant')
    })
  })
})
