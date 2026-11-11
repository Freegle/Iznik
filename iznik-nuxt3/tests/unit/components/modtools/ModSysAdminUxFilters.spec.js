import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxFilters from '~/modtools/components/ModSysAdminUxFilters.vue'
import { uxStubs } from './uxStubs'

const view = (over = {}) => ({
  section: 'funnels',
  period: '7days',
  from: '',
  to: '',
  device: '',
  signed: '',
  flow: '',
  step: '',
  open: '',
  uses: ['period', 'device', 'signed'],
  filtersActive: false,
  filterParams: {},
  extra: {},
  ...over,
})

// The date filter draws what is put in its extra-filters slot, as the real one does.
const stubs = {
  ...uxStubs,
  ModEmailDateFilter: {
    name: 'DateFilterStub',
    template: '<div class="date-filter"><slot name="extra-filters" /></div>',
    props: [
      'loading',
      'fetchLabel',
      'defaultPreset',
      'presets',
      'daily',
      'defaultStart',
      'defaultEnd',
    ],
    emits: ['fetch'],
  },
  'b-form-select': {
    template:
      '<select :id="id" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)">' +
      '<option v-for="o in options" :key="o.value" :value="o.value">{{ o.text }}</option></select>',
    props: ['modelValue', 'id', 'options', 'size'],
    emits: ['update:modelValue'],
  },
}

const make = (props = {}) =>
  mount(ModSysAdminUxFilters, {
    props: { view: view(), ...props },
    global: { stubs },
  })

const selects = (w) => w.findAll('select')

describe('ModSysAdminUxFilters', () => {
  it('gives the date filter whole days, four periods and a button that says Update', () => {
    const w = make({
      view: view({ period: 'custom', from: '2026-09-01', to: '2026-09-30' }),
    })
    const date = w.findComponent({ name: 'DateFilterStub' })
    expect(date.props()).toMatchObject({
      fetchLabel: 'Update',
      daily: true,
      presets: ['7days', '30days', '90days', 'custom'],
      defaultPreset: 'custom',
      defaultStart: '2026-09-01',
      defaultEnd: '2026-09-30',
    })
  })

  it('passes on the loading state', () => {
    expect(
      make({ loading: true })
        .findComponent({ name: 'DateFilterStub' })
        .props('loading')
    ).toBe(true)
  })

  it('sends the dates the date filter chose', () => {
    const w = make()
    w.findComponent({ name: 'DateFilterStub' }).vm.$emit('fetch', {
      start: '2026-10-08',
      end: '2026-10-14',
      lokiRange: '7d',
      preset: '7days',
    })
    expect(w.emitted('dates')).toEqual([
      [{ start: '2026-10-08', end: '2026-10-14', preset: '7days' }],
    ])
  })

  describe('which filters show', () => {
    it('shows Device and Signed in when the section uses both', () => {
      const w = make()
      expect(w.text()).toContain('Device:')
      expect(w.text()).toContain('Signed in:')
      expect(selects(w)).toHaveLength(2)
    })

    it('shows Device alone when the section does not use Signed in', () => {
      const w = make({ view: view({ uses: ['period', 'device'] }) })
      expect(w.text()).toContain('Device:')
      expect(w.text()).not.toContain('Signed in:')
      expect(selects(w)).toHaveLength(1)
    })

    it('shows neither when the section uses only the period', () => {
      const w = make({ view: view({ uses: ['period'] }) })
      expect(selects(w)).toHaveLength(0)
      expect(w.find('details').exists()).toBe(false)
    })
  })

  describe('keeping each label with its select', () => {
    // The bar wraps on a phone. A label left at the end of a line, away from its select, reads as
    // unfinished, so a label and its select are one group and wrap as one.
    it('puts a label and its select in one group', () => {
      const pairs = make().findAll('.filter-pair')
      expect(pairs).toHaveLength(2)
      expect(pairs[0].find('label').text()).toBe('Device:')
      expect(pairs[0].findAll('select')).toHaveLength(1)
      expect(pairs[1].find('label').text()).toBe('Signed in:')
      expect(pairs[1].findAll('select')).toHaveLength(1)
    })

    it('makes a group only for a filter the section uses', () => {
      const pairs = make({
        view: view({ uses: ['period', 'device'] }),
      }).findAll('.filter-pair')
      expect(pairs).toHaveLength(1)
      expect(pairs[0].find('label').text()).toBe('Device:')
      expect(
        make({ view: view({ uses: ['period'] }) })
          .find('.filter-pair')
          .exists()
      ).toBe(false)
    })

    it('ties each label to the select in its group', () => {
      for (const pair of make().findAll('.filter-pair')) {
        expect(pair.find('label').attributes('for')).toBe(
          pair.find('select').attributes('id')
        )
      }
    })

    it('does not give a select a width that makes the bar overflow its row', () => {
      // A fixed width wider than the longest choice is what pushed Update onto a row of its own.
      const all = make().findAll('select')
      expect(all).toHaveLength(2)
      for (const select of all) {
        expect(select.attributes('style') || '').not.toMatch(/width:\s*\d+px/)
      }
    })
  })

  describe('choosing', () => {
    it('offers the devices in words', () => {
      const w = make()
      expect(
        selects(w)[0]
          .findAll('option')
          .map((o) => [o.element.value, o.text()])
      ).toEqual([
        ['', 'All devices'],
        ['web', 'Website, all devices'],
        ['app', 'App, iOS and Android'],
        ['mobile', 'Website on a phone'],
        ['tablet', 'Website on a tablet'],
        ['desktop', 'Website on a desktop'],
        ['app-ios', 'iOS app'],
        ['app-android', 'Android app'],
      ])
    })

    it('offers signed in, not signed in and everyone', () => {
      const w = make()
      expect(
        selects(w)[1]
          .findAll('option')
          .map((o) => [o.element.value, o.text()])
      ).toEqual([
        ['', 'Everyone'],
        ['in', 'Signed in'],
        ['out', 'Not signed in'],
      ])
    })

    it('shows what the address says', () => {
      const w = make({ view: view({ device: 'tablet', signed: 'out' }) })
      expect(selects(w)[0].element.value).toBe('tablet')
      expect(selects(w)[1].element.value).toBe('out')
    })

    it('tells the tab at once when a device is chosen', async () => {
      const w = make()
      await selects(w)[0].setValue('app-ios')
      expect(w.emitted('change')).toEqual([[{ device: 'app-ios' }]])
    })

    it('tells the tab at once when sign-in is chosen', async () => {
      const w = make()
      await selects(w)[1].setValue('in')
      expect(w.emitted('change')).toEqual([[{ signed: 'in' }]])
    })

    it('takes a filter off when All devices or Everyone is chosen', async () => {
      const w = make({
        view: view({ device: 'web', signed: 'in', filtersActive: true }),
      })
      await selects(w)[0].setValue('')
      await selects(w)[1].setValue('')
      expect(w.emitted('change')).toEqual([
        [{ device: undefined }],
        [{ signed: undefined }],
      ])
    })
  })

  describe('the line under the bar', () => {
    it('prints the dates the server gave, and the period before', () => {
      const w = make({
        summaryData: {
          start: '2026-10-08',
          end: '2026-10-14',
          previous: { start: '2026-10-01', end: '2026-10-07' },
        },
      })
      expect(w.find('.ux-summary').text()).toBe(
        'Showing 8 Oct to 14 Oct 2026 (7 days, UTC). Compared with 1 Oct to 7 Oct.'
      )
    })

    it('names the device and sign-in that are set', () => {
      const w = make({
        view: view({ device: 'app-ios', signed: 'in', filtersActive: true }),
        summaryData: { start: '2026-10-08', end: '2026-10-14' },
      })
      expect(w.find('.ux-summary').text()).toContain(
        'Device: iOS app. Signed in only.'
      )
    })

    it('does not say Signed in for a section that has no such select', () => {
      const w = make({
        view: view({ uses: ['period', 'device'], signed: 'in' }),
        summaryData: { start: '2026-10-08', end: '2026-10-14' },
      })
      expect(w.find('.ux-summary').text()).not.toContain('Signed in only')
    })

    it('says which period before it has any dates', () => {
      expect(make().find('.ux-summary').text()).toBe('Showing the last 7 days.')
    })

    it("says when each day's figures arrive", () => {
      expect(make().text()).toContain(
        "Each day's figures arrive at 02:20 UTC the next day."
      )
    })
  })

  describe('the reset link', () => {
    it('is there while a filter the section uses is set, and sets everyone', async () => {
      const w = make({ view: view({ device: 'mobile', filtersActive: true }) })
      const link = w.find('p button')
      expect(link.text()).toBe('Show everyone and all devices')
      await link.trigger('click')
      expect(w.emitted('change')).toEqual([
        [{ device: undefined, signed: undefined }],
      ])
    })

    it('is not there when no filter is set', () => {
      expect(make().text()).not.toContain('Show everyone and all devices')
    })
  })

  describe('the device help', () => {
    it('says how phone, tablet and desktop are told apart', () => {
      const t = make().find('details').text()
      expect(t).toContain('under 768 pixels')
      expect(t).toContain('768 to 1199 pixels')
      expect(t).toContain('1200 pixels or more')
      expect(t).toContain('A small desktop window counts as a tablet.')
    })

    it('says that some figures have no device and count under All devices only', () => {
      const t = make().find('details').text()
      expect(t).toContain('Some figures have no device recorded.')
      expect(t).toContain('counted under All devices only')
    })
  })
})
