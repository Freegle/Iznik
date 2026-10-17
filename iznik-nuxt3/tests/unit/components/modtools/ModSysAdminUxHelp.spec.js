import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxHelp from '~/modtools/components/ModSysAdminUxHelp.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const step = (name, help, visits) => ({
  step: name,
  entered: visits,
  visits,
  help_sought: help,
})

describe('ModSysAdminUxHelp', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.funnels.data = {
      flows: [
        { flow: 'give', steps: [step('give-mobile-photos', 30, 200)] },
        { flow: 'ask', steps: [step('ask-mobile-details', 8, 40)] },
      ],
      previous: {
        flows: [{ flow: 'give', steps: [step('give-mobile-photos', 5, 100)] }],
      },
    }
  })
  const make = (props = {}) =>
    mount(ModSysAdminUxHelp, {
      props: { flow: 'give', ...props },
      global: { stubs: uxStubs },
    })

  it('shows help sought per step as a percentage with counts', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('Photos')
    expect(t).not.toContain('give-mobile-photos')
    expect(t).toContain('15.0% (30/200)')
  })

  it('shows the steps of the given funnel only', () => {
    const t = make({ open: true }).text()
    expect(t).not.toContain('8/40')
    expect(make({ open: true, flow: 'ask' }).text()).toContain('20.0% (8/40)')
  })

  it('names the page family where the desktop and the mobile page share a name', () => {
    store.funnels.data = {
      flows: [
        {
          flow: 'give',
          steps: [
            step('give-options', 5, 310),
            step('give-mobile-options', 15, 230),
          ],
        },
      ],
      previous: { flows: [] },
    }
    const cells = make({ open: true })
      .findAll('td')
      .map((c) => c.text())
    expect(cells).toContain('Options (desktop)')
    expect(cells).toContain('Options (mobile)')
    expect(cells).not.toContain('Options')
    expect(make().find('.ux-summary').text()).toBe(
      'Most asked for help at Options (mobile): 6.5% (15 of 230).'
    )
  })

  it('shows the previous period', () => {
    expect(make({ open: true }).text()).toContain('5.0% (5/100)')
  })

  it('shows a dash for previous when the step is new', () => {
    store.funnels.data.previous = { flows: [] }
    expect(
      make({ open: true })
        .findAll('td')
        .map((c) => c.text())
    ).toContain('–')
  })

  it('shows the empty message', () => {
    store.funnels.data = { flows: [] }
    expect(make({ open: true }).text()).toContain(
      'No data for this funnel in this period.'
    )
  })

  it('shows an error', () => {
    store.funnels.data = null
    store.funnels.error = 'oops'
    expect(make().text()).toContain('oops')
  })

  describe('the closed row', () => {
    it('names the step where the biggest share asked for help', () => {
      store.funnels.data = {
        flows: [
          {
            flow: 'give',
            steps: [
              step('give-mobile-photos', 30, 200),
              step('give-mobile-details', 45, 100),
              step('give-mobile-options', 90, 5000),
            ],
          },
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Most asked for help at Details: 45.0% (45 of 100).'
      )
    })

    it('says there are too few visits when no step has enough', () => {
      store.funnels.data = {
        flows: [{ flow: 'give', steps: [step('give-mobile-photos', 9, 19)] }],
      }
      expect(make().find('.ux-summary').text()).toBe('Too few visits to say.')
    })

    it('says nobody asked for help when the steps with enough visits had no one ask', () => {
      store.funnels.data = {
        flows: [{ flow: 'give', steps: [step('give-mobile-photos', 0, 400)] }],
      }
      expect(make().find('.ux-summary').text()).toBe('Nobody asked for help.')
    })

    it('does not say nobody asked when only a step with too few visits had some', () => {
      store.funnels.data = {
        flows: [
          {
            flow: 'give',
            steps: [
              step('give-mobile-photos', 0, 400),
              step('give-mobile-details', 2, 4),
            ],
          },
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Nobody asked for help at a step with enough visits.'
      )
    })

    it('does not say "stood out", which is an idiom', () => {
      for (const steps of [
        [step('give-mobile-photos', 9, 19)],
        [step('give-mobile-photos', 0, 400)],
      ]) {
        store.funnels.data = { flows: [{ flow: 'give', steps }] }
        expect(make().find('.ux-summary').text()).not.toContain('stood out')
      }
    })

    it('asks the section to open it', async () => {
      const w = make()
      await w.find('h4 > button').trigger('click')
      expect(w.emitted('toggle')).toHaveLength(1)
    })
  })

  it('says what the server did with the filters', () => {
    store.funnels.data = {
      ...store.funnels.data,
      filters: { device: '', signed_in: '' },
      ignored: ['device'],
    }
    store.answered.funnels = { device: 'app' }
    expect(make().find('.ux-note').text()).toBe(
      'Not filtered by device. Shows every device.'
    )
  })

  it('says the part is not switched on when the route is missing', () => {
    store.funnels.data = null
    store.funnels.missing = true
    expect(make().find('.ux-summary').text()).toBe(
      'This part is not switched on yet.'
    )
  })
})
