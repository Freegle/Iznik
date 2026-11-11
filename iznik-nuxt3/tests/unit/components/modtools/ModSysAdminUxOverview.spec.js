import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxOverview from '~/modtools/components/ModSysAdminUxOverview.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const step = (name, over = {}) => ({
  step: name,
  entered: 400,
  visits: 400,
  completed: 250,
  abandoned: 100,
  ...over,
})

const outcome = (flow, started, completed) => ({ flow, started, completed })

const view = (over = {}) => ({
  section: 'overview',
  period: '7days',
  from: '',
  to: '',
  device: '',
  signed: '',
  flow: '',
  step: '',
  open: '',
  uses: ['period', 'device'],
  filtersActive: false,
  filterParams: {},
  extra: {},
  ...over,
})

const FLOWS = [
  'give',
  'ask',
  'reply',
  'chat_promise',
  'chat_outcome',
  'login',
  'settings',
]

describe('ModSysAdminUxOverview', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.health.data = { metrics: [] }
    store.funnels.data = {
      flows: [
        {
          flow: 'give',
          steps: [step('give-mobile-photos', { visits: 375, abandoned: 112 })],
        },
        { flow: 'ask', steps: [step('ask-mobile-details', { abandoned: 0 })] },
        {
          flow: 'reply',
          steps: [step('COMPOSING', { visits: 10, abandoned: 5 })],
        },
      ],
      previous: { flows: [] },
    }
    store.outcomes.data = {
      flows: [
        outcome('give', 1240, 640),
        outcome('ask', 300, 150),
        outcome('reply', 40, 20),
      ],
      previous: { flows: [outcome('give', 1000, 490)] },
    }
  })

  const make = (viewOver = {}) =>
    mount(ModSysAdminUxOverview, {
      props: { view: view(viewOver) },
      global: {
        stubs: uxStubs,
        provide: {
          uxHrefFor: (patch) =>
            `/x?${Object.entries(patch)
              .map(([k, v]) => `${k}=${v}`)
              .join('&')}`,
        },
      },
    })

  const rows = (w) => w.findAll('tbody tr')
  const cells = (w, flow) =>
    w.find(`tbody tr[data-flow="${flow}"]`).findAll('th, td')

  describe('the table of all funnels', () => {
    it('has a row for each of the seven funnels, in the order members meet them', () => {
      const w = make()
      expect(rows(w).map((r) => r.attributes('data-flow'))).toEqual(FLOWS)
    })

    it('keeps a funnel nobody started in its place', () => {
      const w = make()
      expect(cells(w, 'settings').map((c) => c.text())).toEqual([
        'Settings',
        '0',
        '–',
        '–',
      ])
    })

    it('shows started, finished, and the period before in words', () => {
      const c = cells(make(), 'give').map((x) => x.text())
      expect(c[0]).toBe('Offering something')
      expect(c[1]).toBe('1,240')
      expect(c[2]).toContain('52% (640)')
      expect(c[2]).toContain('Before: 49% (3 points higher)')
    })

    it('says there are no figures for the period before when there were none', () => {
      expect(cells(make(), 'ask')[2].text()).toContain(
        'No figures for the period before.'
      )
    })

    it('says which page where the desktop and the mobile page share a name', () => {
      store.funnels.data.flows[0].steps = [
        step('give-options', { visits: 400, abandoned: 20 }),
        step('give-mobile-options', { visits: 400, abandoned: 200 }),
      ]
      const link = cells(make(), 'give')[3].find('a')
      expect(link.text()).toBe(
        'Options (mobile): 50% of 400 visits ended there'
      )
      expect(link.attributes('href')).toBe(
        '/x?section=funnels&flow=give&step=give-mobile-options'
      )
    })

    it('names the biggest loss and links to that step', () => {
      const w = make()
      const link = cells(w, 'give')[3].find('a')
      expect(link.text()).toBe('Photos: 30% of 375 visits ended there')
      expect(link.attributes('href')).toBe(
        '/x?section=funnels&flow=give&step=give-mobile-photos'
      )
    })

    it('says no step lost many people when steps qualify and none lost anyone', () => {
      expect(cells(make(), 'ask')[3].text()).toBe('No step lost many people')
    })

    it('says there are too few visits to say when no step has enough', () => {
      expect(cells(make(), 'reply')[3].text()).toBe('Too few visits to say')
    })

    it('needs more visits for a step once a filter is set', () => {
      store.funnels.data.flows[0].steps = [
        step('give-mobile-photos', { visits: 30, abandoned: 15 }),
      ]
      expect(cells(make(), 'give')[3].text()).toContain(
        'Photos: 50% of 30 visits'
      )
      expect(
        cells(make({ device: 'app', filtersActive: true }), 'give')[3].text()
      ).toBe('Too few visits to say')
    })

    it('links each funnel name to that funnel', () => {
      expect(cells(make(), 'reply')[0].find('a').attributes('href')).toBe(
        '/x?section=funnels&flow=reply'
      )
    })

    it('passes a click on to the tab, to move the address', async () => {
      const w = make()
      await cells(w, 'give')[3].find('a').trigger('click')
      expect(w.emitted('navigate')).toEqual([
        [{ section: 'funnels', flow: 'give', step: 'give-mobile-photos' }],
      ])
    })

    it('adds a flow the server returned that is not one of the seven', () => {
      store.outcomes.data.flows.push(outcome('newflow', 8, 4))
      const w = make()
      expect(rows(w)).toHaveLength(8)
      expect(rows(w).at(-1).attributes('data-flow')).toBe('newflow')
    })

    it('is titled and explained', () => {
      const w = make()
      expect(w.text()).toContain('All funnels')
      expect(w.text()).toContain(
        'One row for each funnel. Open one to see where people left.'
      )
    })
  })

  describe('data health', () => {
    it('is a closed row', () => {
      const w = make()
      const toggle = w.find('h4 > button')
      expect(toggle.text()).toBe('Data health')
      expect(toggle.attributes('aria-expanded')).toBe('false')
    })

    it('opens when the address says health', () => {
      expect(
        make({ open: 'health' }).find('h4 > button').attributes('aria-expanded')
      ).toBe('true')
    })

    it('asks the tab to open it, and to close it when it is open', async () => {
      const w = make()
      await w.find('h4 > button').trigger('click')
      expect(w.emitted('navigate')).toEqual([[{ open: 'health' }]])
      const open = make({ open: 'health' })
      await open.find('h4 > button').trigger('click')
      expect(open.emitted('navigate')).toEqual([[{ open: undefined }]])
    })

    it('is held open while a check fails', () => {
      store.health.data = {
        metrics: [
          {
            metric: 'impressions',
            ok: false,
            days: [{ date: 'a', ratio: 0.4, ok: false }],
          },
        ],
      }
      const w = make()
      expect(w.find('h4 > button').attributes('aria-expanded')).toBe('true')
      expect(w.text()).toContain('Not delivering as expected: impressions')
    })
  })

  describe('loading and failing', () => {
    it('shows a spinner, not a table of zeros, before the first answer', () => {
      store.funnels.data = null
      store.outcomes.data = null
      const w = make()
      expect(w.find('.spinner').exists()).toBe(true)
      expect(w.find('table').exists()).toBe(false)
    })

    it('shows a spinner while either read is loading', () => {
      store.outcomes.loading = true
      expect(make().find('.spinner').exists()).toBe(true)
    })

    it('shows an error from either request', () => {
      store.funnels.data = null
      store.funnels.error = 'funnels broke'
      expect(make().text()).toContain('funnels broke')
      store.funnels.error = null
      store.funnels.data = { flows: [] }
      store.outcomes.data = null
      store.outcomes.error = 'outcomes broke'
      expect(make().text()).toContain('outcomes broke')
    })

    it('shows a fault at once, without waiting for the other read', () => {
      store.funnels.data = null
      store.funnels.loading = true
      store.outcomes.data = null
      store.outcomes.error = 'outcomes broke'
      const w = make()
      expect(w.text()).toContain('outcomes broke')
      expect(w.find('.spinner').exists()).toBe(false)
    })

    it('says the part is not switched on when a route is missing', () => {
      store.funnels.data = null
      store.funnels.missing = true
      const w = make()
      expect(w.text()).toContain('This part is not switched on yet.')
      expect(w.find('table').exists()).toBe(false)
    })
  })

  it('says, once, when the server does not say which filters it used', () => {
    store.answered.funnels = { device: 'mobile' }
    store.answered.outcomes = { device: 'mobile' }
    const w = make({ device: 'mobile', filtersActive: true })
    const notes = w.findAll('.ux-note').map((n) => n.text())
    expect(notes).toEqual([
      'The server did not say which filters it used, so these figures may not be filtered.',
    ])
  })

  it('says nothing about the filters when none was asked for', () => {
    expect(make().findAll('.ux-note')).toHaveLength(0)
  })
})
