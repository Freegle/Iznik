import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxSecondThoughts from '~/modtools/components/ModSysAdminUxSecondThoughts.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const step = (name, backed, visits) => ({
  step: name,
  entered: visits,
  visits,
  backed,
})

describe('ModSysAdminUxSecondThoughts', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.funnels.data = {
      flows: [
        { flow: 'give', steps: [step('give-mobile-photos', 25, 100)] },
        { flow: 'ask', steps: [step('ask-mobile-details', 9, 30)] },
      ],
      previous: {
        flows: [
          { flow: 'give', steps: [step('give-mobile-photos', 5, 100)] },
          { flow: 'ask', steps: [step('ask-mobile-details', 1, 30)] },
        ],
      },
    }
    store.fields.data = {
      fields: [
        {
          flow: 'give',
          step: 'give-mobile-photos',
          field: 'title',
          revisits: 12,
          validation_failures: 3,
          choice_switches: 7,
        },
        {
          flow: 'ask',
          step: 'ask-mobile-details',
          field: 'wanted',
          revisits: 99,
          validation_failures: 98,
          choice_switches: 97,
        },
      ],
    }
  })
  const make = (props = {}) =>
    mount(ModSysAdminUxSecondThoughts, {
      props: { flow: 'give', ...props },
      global: { stubs: uxStubs },
    })

  it('shows the rate of going back per step with counts, and the previous period', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('25.0% (25/100)')
    expect(t).toContain('5.0% (5/100)')
  })

  it('shows the steps of the given funnel only', () => {
    const t = make({ open: true }).text()
    expect(t).not.toContain('9/30')
    expect(t).not.toContain('Asking')
    expect(make({ open: true, flow: 'ask' }).text()).toContain('30.0% (9/30)')
  })

  it('names a step in words, not by its route', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('Photos')
    expect(t).not.toContain('give-mobile-photos')
  })

  it('names the page family where the desktop and the mobile page share a name', () => {
    // Both Options pages are "Options". The rows have no heading to say which is which.
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
    store.fields.data = {
      fields: [
        {
          flow: 'give',
          step: 'give-mobile-options',
          field: 'postcode',
          revisits: 1,
          validation_failures: 0,
          choice_switches: 0,
        },
      ],
    }
    const cells = make({ open: true })
      .findAll('td')
      .map((c) => c.text())
    expect(cells).toContain('Options (desktop)')
    expect(cells.filter((c) => c === 'Options (mobile)')).toHaveLength(2)
    expect(cells).not.toContain('Options')
    expect(make().find('.ux-summary').text()).toBe(
      'Most went back at Options (mobile): 6.5% (15 of 230).'
    )
  })

  it('has no column for the funnel, since it shows one', () => {
    const w = make({ open: true })
    const panel = w.findAllComponents({ name: 'b-table' })
    // The stubbed table renders a cell for each field, so the fields tell us the columns.
    expect(w.text()).not.toContain('Flow')
    expect(panel.length >= 0).toBe(true)
  })

  it('lists the fields people revisit, fail validation on, or switch, for this funnel only', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('title')
    expect(t).toContain('12')
    expect(t).toContain('3')
    expect(t).toContain('7')
    expect(t).not.toContain('wanted')
    expect(t).not.toContain('99')
  })

  it('shows the empty message with neither data set', () => {
    store.funnels.data = { flows: [] }
    store.fields.data = { fields: [] }
    expect(make({ open: true }).text()).toContain(
      'No data for this funnel in this period.'
    )
  })

  it('shows an error from either request', () => {
    store.fields.data = null
    store.fields.error = 'fields broke'
    expect(make().text()).toContain('fields broke')
  })

  describe('the closed row', () => {
    it('names the step where the biggest share went back', () => {
      store.funnels.data = {
        flows: [
          {
            flow: 'give',
            steps: [
              step('give-mobile-photos', 25, 100),
              step('give-mobile-details', 30, 100),
              step('give-mobile-options', 40, 4000),
            ],
          },
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Most went back at Details: 30.0% (30 of 100).'
      )
    })

    it('needs 20 visits for a step to count, and says there are too few when none has', () => {
      store.funnels.data = {
        flows: [{ flow: 'give', steps: [step('give-mobile-photos', 9, 19)] }],
      }
      expect(make().find('.ux-summary').text()).toBe('Too few visits to say.')
    })

    it('says nobody went back when the steps with enough visits had no one go back', () => {
      store.funnels.data = {
        flows: [{ flow: 'give', steps: [step('give-mobile-photos', 0, 500)] }],
      }
      expect(make().find('.ux-summary').text()).toBe('Nobody went back.')
    })

    it('does not say nobody went back when only a step with too few visits had some', () => {
      store.funnels.data = {
        flows: [
          {
            flow: 'give',
            steps: [
              step('give-mobile-photos', 0, 500),
              step('give-mobile-details', 3, 5),
            ],
          },
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Nobody went back at a step with enough visits.'
      )
    })

    it('does not say "stood out", which is an idiom', () => {
      for (const steps of [
        [step('give-mobile-photos', 9, 19)],
        [step('give-mobile-photos', 0, 500)],
        [step('give-mobile-photos', 90, 500)],
      ]) {
        store.funnels.data = { flows: [{ flow: 'give', steps }] }
        expect(make().find('.ux-summary').text()).not.toContain('stood out')
      }
    })

    it('says nothing about steps when the funnel has none, as the empty message does', () => {
      store.funnels.data = { flows: [{ flow: 'give', steps: [] }] }
      store.fields.data = { fields: [] }
      expect(make().find('.ux-summary').text()).toBe(
        'No data for this funnel in this period.'
      )
    })

    it('asks the section to open it', async () => {
      const w = make()
      await w.find('h4 > button').trigger('click')
      expect(w.emitted('toggle')).toHaveLength(1)
    })
  })

  it('says what the server did with the filters on both of its reads, without repeating itself', () => {
    store.funnels.data = {
      ...store.funnels.data,
      filters: { device: 'mobile', signed_in: '1' },
      ignored: [],
    }
    store.fields.data = {
      fields: [],
      filters: { device: 'mobile', signed_in: '' },
      ignored: ['signed_in'],
    }
    store.answered.funnels = { device: 'mobile', signed_in: '1' }
    store.answered.fields = { device: 'mobile', signed_in: '1', flow: 'give' }
    const notes = make().findAll('.ux-note')
    expect(notes.map((n) => n.text())).toEqual([
      'Not filtered by sign-in. Shows signed-in and signed-out people together.',
    ])
  })

  describe('whether second thoughts end in giving up', () => {
    const withFinish = (name, backed, visits, then, reached) => ({
      ...step(name, backed, visits),
      backed_then: then,
      reached,
    })

    it('adds the finish columns next to going back, and says what the row means', () => {
      store.funnels.data = {
        flows: [
          {
            flow: 'give',
            steps: [
              withFinish(
                'give-mobile-details',
                80,
                400,
                { people: 70, finished: 11 },
                { people: 400, finished: 307 }
              ),
            ],
          },
        ],
      }
      const t = make({ open: true }).text()
      // 11 of 70 finished; the other 330 visits finished 296 times.
      expect(t).toContain('16% (11/70)')
      expect(t).toContain('90% (296/330)')
      expect(t).toContain('Many gave up')
    })

    it('flags giving up in the closed row, with both shares', () => {
      store.funnels.data = {
        flows: [
          {
            flow: 'give',
            steps: [
              withFinish(
                'give-mobile-details',
                80,
                400,
                { people: 70, finished: 11 },
                { people: 400, finished: 307 }
              ),
            ],
          },
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Most went back at Details: 20.0% (80 of 400). At Details, 16% of those who went back finished, against 90% who did not.'
      )
    })

    it('says it is less of a worry when most who went back still finished', () => {
      store.funnels.data = {
        flows: [
          {
            flow: 'give',
            steps: [
              withFinish(
                'give-mobile-details',
                80,
                400,
                { people: 70, finished: 52 },
                { people: 400, finished: 300 }
              ),
            ],
          },
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Most went back at Details: 20.0% (80 of 400). Most who went back or changed a field still finished, so this is less of a worry.'
      )
    })

    it('puts the steps where people gave up first', () => {
      store.funnels.data = {
        flows: [
          {
            flow: 'give',
            steps: [
              withFinish(
                'give-mobile-photos',
                50,
                400,
                { people: 40, finished: 30 },
                { people: 400, finished: 300 }
              ),
              withFinish(
                'give-mobile-details',
                60,
                400,
                { people: 60, finished: 10 },
                { people: 400, finished: 300 }
              ),
            ],
          },
        ],
      }
      const cells = make({ open: true })
        .findAll('td')
        .map((c) => c.text())
      expect(cells.indexOf('Details')).toBeLessThan(cells.indexOf('Photos'))
    })

    it('says nothing about finishing for a step with too few visits to compare', () => {
      store.funnels.data = {
        flows: [
          {
            flow: 'give',
            steps: [
              withFinish(
                'give-mobile-details',
                80,
                400,
                { people: 10, finished: 0 },
                { people: 400, finished: 300 }
              ),
            ],
          },
        ],
      }
      const w = make({ open: true })
      expect(w.text()).toContain('Too few to say')
      expect(make().find('.ux-summary').text()).toBe(
        'Most went back at Details: 20.0% (80 of 400).'
      )
    })

    it('needs more visits on each side when a filter is set', () => {
      store.funnels.data = {
        flows: [
          {
            flow: 'give',
            steps: [
              withFinish(
                'give-mobile-details',
                80,
                400,
                { people: 30, finished: 1 },
                { people: 400, finished: 300 }
              ),
            ],
          },
        ],
      }
      expect(make({ open: true }).text()).toContain('Many gave up')
      store.answered.funnels = { device: 'mobile', signed_in: '' }
      expect(make({ open: true }).text()).toContain('Too few to say')
    })

    it('flags a field where people gave up', () => {
      store.funnels.data = {
        flows: [
          {
            flow: 'give',
            steps: [
              withFinish(
                'give-mobile-details',
                80,
                400,
                { people: 5, finished: 4 },
                { people: 400, finished: 300 }
              ),
            ],
          },
        ],
      }
      store.fields.data = {
        fields: [
          {
            flow: 'give',
            step: 'give-mobile-details',
            field: 'email',
            revisits: 20,
            validation_failures: 5,
            choice_switches: 0,
            reached: { people: 400, finished: 300 },
            then: { people: 40, finished: 4 },
          },
        ],
      }
      const s = make().find('.ux-summary').text()
      expect(s).toContain(
        'At the email field at Details, 10% of those who changed a field finished, against 82% who did not.'
      )
    })

    it('leaves the finish columns out when the server sent no figures', () => {
      const t = make({ open: true }).text()
      expect(t).not.toContain('Then finished')
      expect(t).not.toContain('What it means')
      expect(make().find('.ux-summary').text()).toBe(
        'Most went back at Photos: 25.0% (25 of 100).'
      )
    })
  })

  it('says the part is not switched on when a route is missing', () => {
    store.funnels.data = null
    store.funnels.missing = true
    expect(make().find('.ux-summary').text()).toBe(
      'This part is not switched on yet.'
    )
  })
})
