import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import RipplingLegend from '~/modtools/components/RipplingLegend.vue'

/**
 * The legend is the only thing telling a moderator what the colours on the rippling
 * map mean, so a wrong mapping here misreads the map rather than looking broken. The
 * catchment key in particular is documented as deriving from the bands actually drawn
 * ("so the key always matches"), which only holds while it renders from the prop.
 */
describe('RipplingLegend', () => {
  const bands = [
    { color: '#ff0000', label: '10 minutes', sub: '2 hours' },
    { color: '#00ff00', label: '20 minutes' },
  ]

  function mountLegend(props) {
    return mount(RipplingLegend, { props })
  }

  describe('catchment mode', () => {
    it('takes its swatch colours from the bands drawn on the map, not a hardcoded list', () => {
      const wrapper = mountLegend({ mode: 'catchment', bands })

      const swatches = wrapper.findAll('.rpl-leg-swatch')
      const styles = swatches.map((s) => s.attributes('style') || '')

      expect(
        styles.some(
          (s) => s.includes('rgb(255, 0, 0)') || s.includes('#ff0000')
        )
      ).toBe(true)
      expect(
        styles.some(
          (s) => s.includes('rgb(0, 255, 0)') || s.includes('#00ff00')
        )
      ).toBe(true)
    })

    it('renders one entry per band, plus the group-area key', () => {
      const wrapper = mountLegend({ mode: 'catchment', bands })

      // One item per band + the trailing "Group area" entry.
      expect(wrapper.findAll('.rpl-leg-item')).toHaveLength(bands.length + 1)
      expect(wrapper.text()).toContain('Group area')
    })

    it('labels each band with the band label', () => {
      const wrapper = mountLegend({ mode: 'catchment', bands })

      expect(wrapper.text()).toContain('10 minutes')
      expect(wrapper.text()).toContain('20 minutes')
    })

    it('explains the delay only for bands that carry one', () => {
      const wrapper = mountLegend({ mode: 'catchment', bands })

      // The first band has sub: '2 hours'; the second has none, so exactly one
      // "reached ... after posting" line should appear.
      expect(wrapper.text()).toContain('reached 2 hours after posting')
      expect(wrapper.text().match(/after posting/g)).toHaveLength(1)
    })

    it('asks for a group instead of keying an empty map', () => {
      const wrapper = mountLegend({ mode: 'catchment', bands: [] })

      // No bands means no group is picked, so nothing is drawn. This used to keep
      // the heading and a blue "Group area" swatch, which read as "the group area
      // is that blue thing" and sent a moderator hunting for an outline that was
      // never drawn (Discourse 9808/728).
      expect(wrapper.findAll('.rpl-leg-item')).toHaveLength(0)
      expect(wrapper.text()).not.toContain('Group area')
      expect(wrapper.text()).not.toContain('Ripples in within')
      expect(wrapper.text()).toContain('No group selected')
      expect(wrapper.text()).toContain('Pick a group')
    })
  })

  describe('inbound mode', () => {
    it('explains that the number on a pin is its digest position', () => {
      const wrapper = mountLegend({ mode: 'inbound' })

      expect(wrapper.text()).toContain('number = digest position')
    })

    it('distinguishes a rippled-in group from the home group', () => {
      const wrapper = mountLegend({ mode: 'inbound' })

      expect(wrapper.text()).toContain('Active home-group')
      expect(wrapper.text()).toContain('Active rippled in')
      expect(wrapper.text()).toContain('Home-group area')
    })

    it('is not the catchment key', () => {
      const wrapper = mountLegend({ mode: 'inbound' })

      expect(wrapper.text()).not.toContain('Ripples in within')
    })
  })

  describe('outbound mode', () => {
    it('keys the deprivation quintiles shown outside the boundary', () => {
      const wrapper = mountLegend({ mode: 'outbound' })

      expect(wrapper.text()).toContain('Q1 — most deprived')
      expect(wrapper.text()).toContain('Q5 — least deprived')
      expect(wrapper.text()).toContain('Travel time boundary')
    })

    it('omits the deprivation quintiles when minimal, because that view does not draw them', () => {
      const wrapper = mountLegend({ mode: 'outbound', minimal: true })

      expect(wrapper.text()).not.toContain('most deprived')
      expect(wrapper.text()).not.toContain('least deprived')
      expect(wrapper.text()).toContain('Current reach boundary')
    })
  })

  describe('minimal precedence', () => {
    it('is ignored in catchment mode, which keeps its own key', () => {
      // minimal is only checked after mode, so a catchment map still gets the
      // heatmap key rather than the per-post modal's reduced legend.
      const wrapper = mountLegend({ mode: 'catchment', bands, minimal: true })

      expect(wrapper.text()).toContain('Ripples in within')
      expect(wrapper.text()).not.toContain('Current reach boundary')
    })
  })

  describe('mode prop', () => {
    it('accepts only the three modes the map can be in', () => {
      const { validator } = RipplingLegend.props.mode

      expect(validator('outbound')).toBe(true)
      expect(validator('inbound')).toBe(true)
      expect(validator('catchment')).toBe(true)
      expect(validator('sideways')).toBe(false)
    })
  })
})
