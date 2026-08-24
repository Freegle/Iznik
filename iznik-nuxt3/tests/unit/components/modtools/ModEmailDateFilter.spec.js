import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import ModEmailDateFilter from '~/modtools/components/ModEmailDateFilter.vue'

/**
 * This filter decides the time window every incoming-email panel queries with. The
 * distinction that matters is precision: the short presets carry a time of day, the
 * multi-day ones are date-only. If "last hour" degraded to a date-only range it would
 * silently become "since midnight" — a much wider window that still looks plausible.
 */
describe('ModEmailDateFilter', () => {
  function fetchPayload(defaultPreset) {
    const wrapper = mount(ModEmailDateFilter, { props: { defaultPreset } })
    const events = wrapper.emitted('fetch')
    expect(
      events,
      'the panel fetches on mount, without waiting for a click'
    ).toBeTruthy()
    return events[0][0]
  }

  it('fetches on mount so the panel arrives populated', () => {
    const wrapper = mount(ModEmailDateFilter)

    expect(wrapper.emitted('fetch')).toHaveLength(1)
  })

  describe('short presets keep the time of day', () => {
    it('hour asks for a window one hour wide, not since midnight', () => {
      const p = fetchPayload('hour')

      expect(p.start).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/)
      expect(p.end).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/)

      const widthMs = new Date(p.end) - new Date(p.start)
      expect(widthMs).toBeGreaterThan(59 * 60 * 1000)
      expect(widthMs).toBeLessThan(61 * 60 * 1000)
    })

    it('day asks for a window 24 hours wide', () => {
      const p = fetchPayload('day')

      expect(p.start).toMatch(/T\d{2}:\d{2}:\d{2}$/)
      const widthMs = new Date(p.end) - new Date(p.start)
      expect(widthMs).toBeGreaterThan(23.5 * 3600 * 1000)
      expect(widthMs).toBeLessThan(24.5 * 3600 * 1000)
    })
  })

  describe('multi-day presets are date-only', () => {
    it.each(['7days', '30days', '90days'])('%s sends bare dates', (preset) => {
      const p = fetchPayload(preset)

      expect(p.start).toMatch(/^\d{4}-\d{2}-\d{2}$/)
      expect(p.end).toMatch(/^\d{4}-\d{2}-\d{2}$/)
      expect(p.start).not.toContain('T')
    })

    it('7days spans a week', () => {
      const p = fetchPayload('7days')

      const days = (new Date(p.end) - new Date(p.start)) / 86400000
      expect(days).toBe(7)
    })
  })

  describe('lokiRange', () => {
    it.each([
      ['hour', '1h'],
      ['day', '24h'],
      ['7days', '7d'],
      ['30days', '30d'],
      ['90days', '90d'],
    ])('maps %s to %s', (preset, expected) => {
      expect(fetchPayload(preset).lokiRange).toBe(expected)
    })

    it('is null for custom dates, so the consumer must use the explicit range', () => {
      expect(fetchPayload('custom').lokiRange).toBeNull()
    })
  })

  it('reports which preset produced the window', () => {
    expect(fetchPayload('30days').preset).toBe('30days')
  })

  it('defaults to the last 7 days', () => {
    const wrapper = mount(ModEmailDateFilter)

    expect(wrapper.emitted('fetch')[0][0].preset).toBe('7days')
  })

  it('falls back to a week for a preset it does not recognise', () => {
    // The window is what the panel queries with, so an unknown preset must land on a
    // sane range rather than an empty or undefined one.
    const p = fetchPayload('sometime-last-tuesday')

    expect(p.start).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect((new Date(p.end) - new Date(p.start)) / 86400000).toBe(7)
  })

  describe('changing the dropdown', () => {
    async function selectPreset(wrapper, value) {
      const select = wrapper.find('select.form-select')
      await select.setValue(value)
      return wrapper
    }

    it('refetches immediately for a fixed preset', async () => {
      const wrapper = mount(ModEmailDateFilter)
      expect(wrapper.emitted('fetch')).toHaveLength(1) // the mount fetch

      await selectPreset(wrapper, 'day')

      expect(wrapper.emitted('fetch')).toHaveLength(2)
      expect(wrapper.emitted('fetch')[1][0].preset).toBe('day')
    })

    it('waits for the Fetch button when custom dates are chosen', async () => {
      // Auto-fetching on 'custom' would fire with both date fields still empty, so the
      // early return is what lets the moderator fill them in first.
      const wrapper = mount(ModEmailDateFilter)
      expect(wrapper.emitted('fetch')).toHaveLength(1)

      await selectPreset(wrapper, 'custom')

      expect(wrapper.emitted('fetch')).toHaveLength(1)
    })
  })
})
