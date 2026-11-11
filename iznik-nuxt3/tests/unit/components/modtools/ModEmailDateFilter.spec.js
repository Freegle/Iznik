import { describe, it, expect, vi, afterEach } from 'vitest'
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

  describe('on the day the clocks change', () => {
    // Stepping the local date back a day gives the same clock time yesterday,
    // which is 25 hours before now on the last Sunday in October in London.
    // The presets are a number of hours, whatever the clocks did.
    afterEach(() => {
      vi.useRealTimers()
    })

    it('day is still 24 hours and hour is still 60 minutes', () => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date('2026-10-25T08:30:00Z'))

      const day = fetchPayload('day')
      expect(new Date(day.end) - new Date(day.start)).toBe(24 * 3600 * 1000)

      const hour = fetchPayload('hour')
      expect(new Date(hour.end) - new Date(hour.start)).toBe(3600 * 1000)
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

  /**
   * The UX tab reads one-row-a-day rollups, so it needs whole days that end yesterday and can never
   * send a time of day. Without the new props the filter behaves as it always did.
   */
  describe('whole days, for the UX tab', () => {
    const day = (offset) => {
      const n = new Date()
      return new Date(
        Date.UTC(n.getUTCFullYear(), n.getUTCMonth(), n.getUTCDate() + offset)
      )
        .toISOString()
        .slice(0, 10)
    }
    const daysBetween = (a, b) => (new Date(b) - new Date(a)) / 86400000 + 1
    const dailyPayload = (defaultPreset) => {
      const wrapper = mount(ModEmailDateFilter, {
        props: { defaultPreset, daily: true },
      })
      return wrapper.emitted('fetch')[0][0]
    }
    const optionValues = (wrapper) =>
      wrapper.findAll('option').map((o) => o.element.value)

    // The shared stubs leave out the input's type and the form's submit event, which these tests
    // read, so this file's mounts use fuller ones.
    const stubs = {
      'b-form': {
        template:
          '<form @submit.prevent="$emit(\'submit\', $event)"><slot /></form>',
        props: ['inline'],
        // Declared, or the same submit also reaches the root form as a native listener.
        emits: ['submit'],
      },
      'b-form-input': {
        template:
          '<input :type="type" :max="max" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
        props: ['modelValue', 'id', 'type', 'max'],
      },
    }

    it('offers all six presets unless asked for fewer', () => {
      expect(optionValues(mount(ModEmailDateFilter))).toEqual([
        'hour',
        'day',
        '7days',
        '30days',
        '90days',
        'custom',
      ])
    })

    it('offers only the presets it is given', () => {
      const wrapper = mount(ModEmailDateFilter, {
        props: { presets: ['7days', '30days', '90days', 'custom'] },
      })
      expect(optionValues(wrapper)).toEqual([
        '7days',
        '30days',
        '90days',
        'custom',
      ])
      expect(wrapper.text()).not.toContain('Last hour')
      expect(wrapper.text()).not.toContain('Last 24 hours')
    })

    it.each([
      ['7days', 7],
      ['30days', 30],
      ['90days', 90],
    ])('%s ends yesterday and is exactly %i whole days', (preset, n) => {
      const p = dailyPayload(preset)
      expect(p.end).toBe(day(-1))
      expect(daysBetween(p.start, p.end)).toBe(n)
      expect(p.start).toMatch(/^\d{4}-\d{2}-\d{2}$/)
      expect(p.end).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    })

    it('still ends today without the daily prop, as it always did', () => {
      expect(fetchPayload('7days').end).toBe(day(0))
    })

    it('asks for dates, not a time of day, for custom dates', async () => {
      const wrapper = mount(ModEmailDateFilter, {
        props: { daily: true },
        global: { stubs },
      })
      await wrapper.find('select.form-select').setValue('custom')
      const inputs = wrapper.findAll('input')
      expect(inputs).toHaveLength(2)
      for (const input of inputs) {
        expect(input.attributes('type')).toBe('date')
        expect(input.attributes('max')).toBe(day(-1))
      }
    })

    it('keeps date and time inputs for custom dates without the daily prop', async () => {
      const wrapper = mount(ModEmailDateFilter, { global: { stubs } })
      await wrapper.find('select.form-select').setValue('custom')
      for (const input of wrapper.findAll('input')) {
        expect(input.attributes('type')).toBe('datetime-local')
        expect(input.attributes('max')).toBeUndefined()
      }
    })

    it('starts on the range it was handed when the preset is custom', () => {
      const wrapper = mount(ModEmailDateFilter, {
        props: {
          daily: true,
          defaultPreset: 'custom',
          defaultStart: '2026-09-01',
          defaultEnd: '2026-09-30',
        },
      })
      const events = wrapper.emitted('fetch')
      expect(events).toHaveLength(1)
      expect(events[0][0]).toMatchObject({
        start: '2026-09-01',
        end: '2026-09-30',
        preset: 'custom',
        lokiRange: null,
      })
    })

    it('says nothing for custom dates until both are filled in', async () => {
      const wrapper = mount(ModEmailDateFilter, {
        props: { daily: true, defaultPreset: 'custom' },
        global: { stubs },
      })
      expect(wrapper.emitted('fetch')).toBeUndefined()

      const [from, to] = wrapper.findAll('input')
      await from.setValue('2026-09-01')
      await wrapper.find('form').trigger('submit')
      expect(wrapper.emitted('fetch')).toBeUndefined()

      await to.setValue('2026-09-07')
      await wrapper.find('form').trigger('submit')
      expect(wrapper.emitted('fetch')).toHaveLength(1)
      expect(wrapper.emitted('fetch')[0][0]).toMatchObject({
        start: '2026-09-01',
        end: '2026-09-07',
      })
    })

    it('refetches at once for a fixed preset', async () => {
      const wrapper = mount(ModEmailDateFilter, { props: { daily: true } })
      await wrapper.find('select.form-select').setValue('30days')
      expect(wrapper.emitted('fetch')).toHaveLength(2)
      expect(
        daysBetween(
          wrapper.emitted('fetch')[1][0].start,
          wrapper.emitted('fetch')[1][0].end
        )
      ).toBe(30)
    })

    it('ignores a handed-in range when the preset is not custom', () => {
      const wrapper = mount(ModEmailDateFilter, {
        props: {
          daily: true,
          defaultPreset: '7days',
          defaultStart: '2026-09-01',
          defaultEnd: '2026-09-30',
        },
      })
      expect(wrapper.emitted('fetch')[0][0].end).toBe(day(-1))
    })
  })
})
