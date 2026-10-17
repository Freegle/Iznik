import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxFunnels from '~/modtools/components/ModSysAdminUxFunnels.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const step = (name, over = {}) => ({
  step: name,
  entered: 100,
  reentered: 0,
  visits: 100,
  completed: 60,
  backed: 10,
  abandoned: 30,
  revisits: 5,
  validation_failures: 2,
  help_sought: 4,
  dwell_p50_ms: 8000,
  dwell_p90_ms: 45000,
  by: {
    device_class: [
      { key: 'mobile', entered: 70, visits: 70, completed: 35, abandoned: 25 },
      { key: 'desktop', entered: 30, visits: 30, completed: 25, abandoned: 5 },
    ],
    logged_in: [
      { key: 'true', entered: 100, visits: 100, completed: 60, abandoned: 30 },
    ],
    variant: [],
  },
  ...over,
})

const funnels = {
  start: '2026-10-01',
  end: '2026-10-07',
  flows: [
    {
      flow: 'give',
      steps: [
        step('give'),
        step('give-whereami', {
          entered: 80,
          visits: 80,
          completed: 20,
          abandoned: 10,
        }),
        step('give-mobile-photos', {
          entered: 120,
          visits: 130,
          completed: 70,
          abandoned: 50,
        }),
      ],
    },
  ],
  previous: {
    start: '2026-09-24',
    end: '2026-09-30',
    flows: [
      {
        flow: 'give',
        steps: [step('give', { entered: 200, visits: 200, completed: 150 })],
      },
    ],
  },
  filters: { device: '', signed_in: '' },
  ignored: [],
}

const outcomes = {
  flows: [
    {
      flow: 'give',
      started: 220,
      submitted: 110,
      submit_ok: 105,
      submit_failed: 5,
      completed: 100,
      abandoned: 90,
      resumed: 3,
    },
  ],
  previous: { flows: [{ flow: 'give', started: 200, completed: 80 }] },
  filters: { device: '', signed_in: '' },
  ignored: [],
}

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

const clone = (o) => JSON.parse(JSON.stringify(o))

describe('ModSysAdminUxFunnels', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.funnels.data = clone(funnels)
    store.outcomes.data = clone(outcomes)
    // The section asks for the field figures itself. These tests do not fetch.
    vi.spyOn(store, 'loadSlots').mockResolvedValue()
  })

  const make = (viewOver = {}, { links = [] } = {}) =>
    mount(ModSysAdminUxFunnels, {
      props: { view: view({ flow: 'give', ...viewOver }) },
      global: {
        stubs: {
          ...uxStubs,
          ModSysAdminUxSamples: {
            name: 'SamplesStub',
            template: '<div class="samples-stub" />',
            props: ['modelValue', 'flow', 'step', 'start', 'end'],
          },
          ModSysAdminUxSecondThoughts: {
            name: 'ThoughtsStub',
            template: '<div class="thoughts-stub" />',
            props: ['flow', 'open'],
            emits: ['toggle'],
          },
          ModSysAdminUxHelp: {
            name: 'HelpStub',
            template: '<div class="help-stub" />',
            props: ['flow', 'open'],
            emits: ['toggle'],
          },
        },
        provide: {
          uxHrefFor: (patch) =>
            `/x?${Object.entries(patch)
              .map(([k, v]) => `${k}=${v}`)
              .join('&')}`,
          uxFunnelLinks: links,
        },
      },
    })

  const charts = (w) =>
    w.findAllComponents({ name: 'ModSysAdminUxFunnelChart' })

  describe('the funnel that is open', () => {
    it('titles the panel with the funnel in words', () => {
      expect(make().find('h4').text()).toBe('Offering something')
      expect(make({ flow: 'chat_outcome' }).find('h4').text()).toBe(
        'Marking taken or received'
      )
    })

    it('leads with a headline sentence a person can act on', () => {
      const t = make().text()
      expect(t).toContain(
        'Offering something: 220 started, 100 finished (45%, before that 40%).'
      )
      expect(t).toContain(
        'Biggest loss: 38% of the 130 visits to Photos ended there'
      )
    })

    it('gives the outcome figures of the old panel under the headline', () => {
      const t = make().find('.outcome-line').text()
      expect(t).toBe(
        'Submitted 50.0% (110/220). 4.5% (5/110) of those failed. Finished 45.5% (100/220). 40.9% (90/220) left without finishing. 3 came back later.'
      )
    })

    it("keeps every figure of the old panel's own outcome row", () => {
      store.outcomes.data = {
        flows: [
          {
            flow: 'give',
            started: 100,
            submitted: 50,
            submit_ok: 45,
            submit_failed: 5,
            completed: 45,
            abandoned: 40,
            resumed: 3,
          },
        ],
      }
      const w = make()
      const line = w.find('.outcome-line').text()
      expect(line).toContain('50.0% (50/100)')
      expect(line).toContain('10.0% (5/50)')
      expect(line).toContain('45.0% (45/100)')
      expect(line).toContain('40.0% (40/100)')
      expect(line).toContain('3 came back later')
      expect(w.find('.headline').text()).toContain('100 started')
    })

    it("says which period the figures are compared with, from the server's dates", () => {
      expect(make().find('.compared').text()).toBe(
        'Compared with 24 Sep to 30 Sep.'
      )
    })

    it('leaves the comparison out when the server gave no dates for it', () => {
      delete store.funnels.data.previous.start
      delete store.funnels.data.previous.end
      expect(make().find('.compared').exists()).toBe(false)
    })

    it('draws a desktop and a mobile funnel for give, with bars in route order', () => {
      const c = charts(make())
      expect(c).toHaveLength(2)
      expect(c[0].props('title')).toBe('Desktop pages')
      expect(c[0].props('steps').map((s) => s.step)).toEqual([
        'give',
        'give-whereami',
      ])
      expect(c[1].props('title')).toBe('Mobile pages')
      expect(c[0].props('previousReach')).toEqual({ give: 1, 'stage:0': 1 })
    })

    it('hands each chart the share that completed in the period before', () => {
      const done = charts(make())[0].props('previousDone')
      expect(done).toEqual({
        give: { completed: 150, visits: 200 },
        'stage:0': { completed: 150, visits: 200 },
      })
    })

    it('keeps the previous period of a stage with ways in apart from each way in', () => {
      const way = (name, entered) =>
        step(name, {
          entered,
          visits: entered,
          completed: entered,
          abandoned: 0,
        })
      store.funnels.data = {
        start: '2026-10-01',
        end: '2026-10-07',
        flows: [
          { flow: 'login', steps: [way('signup', 40), way('signin', 60)] },
        ],
        previous: {
          flows: [
            { flow: 'login', steps: [way('signup', 60), way('signin', 40)] },
          ],
        },
      }
      store.outcomes.data = {
        flows: [{ flow: 'login', started: 100, completed: 50 }],
      }
      const reach = charts(make({ flow: 'login' }))[0].props('previousReach')
      // The heading is the whole stage (everyone), not the 60% who joined last time.
      expect(reach['stage:0']).toBe(1)
      expect(reach.signup).toBeCloseTo(0.6)
      expect(reach.signin).toBeCloseTo(0.4)
    })

    it('hands the chart the exits of a flow, so a password reset is not drawn as a loss', () => {
      store.funnels.data.flows = [
        {
          flow: 'login',
          steps: [
            step('signin', { entered: 100, visits: 120, abandoned: 30 }),
            step('forgot', { entered: 40, visits: 45, abandoned: 40 }),
          ],
        },
      ]
      store.outcomes.data = {
        flows: [{ flow: 'login', started: 100, completed: 70 }],
      }
      const w = make({ flow: 'login' })
      const chart = charts(w)[0]
      expect(chart.props('steps').map((x) => x.step)).toEqual(['signin'])
      expect(chart.props('exits')).toEqual([
        {
          step: 'forgot',
          label: 'Went to reset their password',
          entered: 40,
          visits: 45,
        },
      ])
      expect(w.text()).not.toContain('Forgotten password ended there')
    })

    it('explains every mark on the chart, including the dashed outline of the period before', () => {
      const w = make()
      expect(w.text()).toContain(
        'dashed outline is the same step in the period before'
      )
      const how = w.find('details.how-to-read')
      expect(how.find('summary').text()).toBe('How to read this')
      expect(how.text()).toContain('hatched red end')
      expect(how.text()).toContain(
        'dashed outline is the same step in the period before'
      )
    })

    it('draws the line that explains the outline once, under the last chart', () => {
      const c = charts(make())
      expect(c.map((x) => x.props('showKey'))).toEqual([false, true])
    })
  })

  describe('one Biggest loss', () => {
    it('tags exactly one step in the whole funnel, across the desktop and mobile charts', () => {
      // give-mobile-photos has the highest share of its visits that ended there (50 of 130).
      const w = make()
      expect(w.findAll('.worst-tag')).toHaveLength(1)
      const c = charts(w)
      expect(c.map((x) => x.props('worst'))).toEqual([
        'give-mobile-photos',
        'give-mobile-photos',
      ])
      expect(c[1].find('.worst-tag').exists()).toBe(true)
      expect(c[0].find('.worst-tag').exists()).toBe(false)
    })

    it('is the step the headline names', () => {
      const w = make()
      expect(w.find('.headline').text()).toContain(
        'visits to Photos ended there'
      )
      expect(w.findAll('.worst-tag')).toHaveLength(1)
    })

    it('names the page family in the headline where two pages share a name', () => {
      // The desktop and the mobile Options pages are both "Options". The headline has no heading
      // above it to say which, so it says.
      store.funnels.data.flows[0].steps = [
        step('give-options', { visits: 100, abandoned: 10 }),
        step('give-mobile-options', { visits: 100, abandoned: 60 }),
      ]
      expect(make().find('.headline').text()).toContain(
        'visits to Options (mobile) ended there'
      )
    })

    it('tags nothing when no step qualifies', () => {
      store.funnels.data.flows[0].steps = [
        step('give', { visits: 12, abandoned: 6 }),
      ]
      const w = make()
      expect(w.findAll('.worst-tag')).toHaveLength(0)
      expect(w.find('.headline').text()).toContain('Too few visits to say')
    })

    it('needs more visits for a step once a filter is set', () => {
      store.funnels.data.flows[0].steps = [
        step('give', { visits: 30, abandoned: 15 }),
      ]
      expect(make().findAll('.worst-tag')).toHaveLength(1)
      expect(
        make({ filtersActive: true, device: 'app' }).findAll('.worst-tag')
      ).toHaveLength(0)
    })
  })

  describe('the step figures of the old panel', () => {
    // Master's fixture: a step with 100 visits and 60 completed, one with 80 visits and 20
    // completed, and a period before with 200 visits and 150 completed for the first step only.
    beforeEach(() => {
      store.funnels.data = {
        start: '2026-10-01',
        end: '2026-10-07',
        flows: [
          {
            flow: 'give',
            steps: [
              step('give'),
              step('give-whereami', { entered: 80, visits: 80, completed: 20 }),
            ],
          },
        ],
        previous: {
          flows: [
            {
              flow: 'give',
              steps: [
                step('give', { entered: 200, visits: 200, completed: 150 }),
              ],
            },
          ],
        },
      }
      store.outcomes.data = {
        flows: [
          {
            flow: 'give',
            started: 100,
            submitted: 50,
            submit_ok: 45,
            submit_failed: 5,
            completed: 45,
            abandoned: 40,
            resumed: 3,
            total_dwell_p50_ms: 90000,
          },
        ],
      }
    })

    async function table() {
      const w = make()
      await charts(w)[0]
        .find('[data-testid="chart-view-toggle"]')
        .trigger('click')
      return w
        .findAll('tbody tr')
        .map((r) => r.findAll('td').map((c) => c.text()))
    }

    it('shows conversion as a percentage with its counts, for every step', async () => {
      const rows = await table()
      expect(rows[0][3]).toBe('60.0% (60/100)')
      expect(rows[1][3]).toBe('25.0% (20/80)')
    })

    it('shows the previous period alongside', async () => {
      expect((await table())[0][4]).toBe('75.0% (150/200)')
    })

    it('shows a dash when the previous period has no such step', async () => {
      expect((await table())[1][4]).toBe('–')
    })

    it('divides by visits, so a step entered again cannot exceed 100%', async () => {
      store.funnels.data.flows[0].steps[0] = step('give', {
        entered: 1,
        reentered: 1,
        visits: 2,
        completed: 2,
      })
      expect((await table())[0][3]).toBe('100.0% (2/2)')
    })

    it('summarises the funnel outcome with counts', () => {
      const w = make()
      const line = w.find('.outcome-line').text()
      expect(line).toContain('50.0% (50/100)')
      expect(line).toContain('10.0% (5/50)')
      expect(line).toContain('45.0% (45/100)')
      expect(line).toContain('40.0% (40/100)')
      expect(line).toContain('3 came back later')
      expect(w.find('.headline').text()).toContain('100 started')
    })
  })

  describe('the step that is chosen', () => {
    it('shows no step detail until a step is in the address', () => {
      expect(make().find('.step-detail').exists()).toBe(false)
    })

    it('asks for the step when a bar is chosen', () => {
      const w = make()
      charts(w)[0].vm.$emit('select', 'give')
      expect(w.emitted('navigate')).toEqual([[{ step: 'give' }]])
    })

    it('asks to close the step when the open bar is chosen again', () => {
      const w = make({ step: 'give' })
      charts(w)[0].vm.$emit('select', 'give')
      expect(w.emitted('navigate')).toEqual([[{ step: undefined }]])
    })

    it('shows the breakdown, dwell and previous figure for the step in the address', () => {
      const d = make({ step: 'give' }).find('.step-detail')
      expect(d.exists()).toBe(true)
      expect(d.text()).toContain('Item and photos')
      expect(d.text()).toContain('8.0 s')
      expect(d.text()).toContain('45.0 s')
      expect(d.text()).toContain('50.0% (35/70)')
      expect(d.text()).toContain(
        'Previous period: 75.0% (150/200) finished the step.'
      )
    })

    it('says the figures of the step in plain sentences, with the words of the outcome line', () => {
      const text = make({ step: 'give' })
        .find('.step-detail p')
        .text()
        .replace(/\s+/g, ' ')
      expect(text).toBe(
        '100 people reached this step. Of the 100 visits, 60.0% (60/100) finished the step, 10.0% (10/100) went back and 30.0% (30/100) left without finishing it. Typical time here: 8.0 s. The slowest tenth took 45.0 s or more. Previous period: 75.0% (150/200) finished the step.'
      )
      expect(text).not.toMatch(/completed|abandoned|reentered|slowest tenth \d/)
    })

    it('says how many came back to the step', () => {
      store.funnels.data.flows[0].steps[0].entered = 345
      store.funnels.data.flows[0].steps[0].reentered = 30
      store.funnels.data.flows[0].steps[0].visits = 375
      const text = make({ step: 'give' }).find('.step-detail p').text()
      expect(text).toContain(
        '345 people reached this step and 30 came back to it.'
      )
    })

    it('says so when nobody reached the step for the first time', () => {
      store.funnels.data.flows[0].steps[0].entered = 0
      store.funnels.data.flows[0].steps[0].reentered = 0
      expect(make({ step: 'give' }).find('.step-detail p').text()).toContain(
        '0 people reached this step.'
      )
    })

    it('says one person in the singular', () => {
      store.funnels.data.flows[0].steps[0].entered = 1
      store.funnels.data.flows[0].steps[0].reentered = 1
      expect(make({ step: 'give' }).find('.step-detail p').text()).toContain(
        '1 person reached this step and 1 came back to it.'
      )
    })

    it('leaves out a time the rollup does not have, rather than printing a dash', () => {
      store.funnels.data.flows[0].steps[0].dwell_p50_ms = null
      store.funnels.data.flows[0].steps[0].dwell_p90_ms = null
      const text = make({ step: 'give' }).find('.step-detail p').text()
      expect(text).not.toContain('Typical time')
      expect(text).not.toContain('slowest tenth')
      expect(text).not.toContain('–')
    })

    it('names the step by its page family where two pages share a name', () => {
      store.funnels.data.flows[0].steps.push(
        step('give-options', { entered: 60, visits: 60 }),
        step('give-mobile-options', { entered: 40, visits: 40 })
      )
      expect(
        make({ step: 'give-mobile-options' }).find('.step-detail h6').text()
      ).toBe('Options (mobile)')
      expect(
        make({ step: 'give-options' }).find('.step-detail h6').text()
      ).toBe('Options (desktop)')
      // A name that is not shared stays as it is.
      expect(
        make({ step: 'give-mobile-photos' }).find('.step-detail h6').text()
      ).toBe('Photos')
    })

    it('says so when the step has no figures in the period before', () => {
      expect(
        make({ step: 'give-whereami' }).find('.step-detail').text()
      ).toContain('This step has no figures in the period before.')
    })

    it('marks the chosen bar in the chart', () => {
      expect(charts(make({ step: 'give' }))[0].props('selected')).toBe('give')
    })

    it('closes with the Close button', async () => {
      const w = make({ step: 'give' })
      await w.find('.close-btn').trigger('click')
      expect(w.emitted('navigate')).toEqual([[{ step: undefined }]])
    })

    it('opens the samples window for the open funnel, step and period', async () => {
      const w = make({ step: 'give-mobile-photos' })
      await w.find('.sample-btn').trigger('click')
      const s = w.findComponent({ name: 'SamplesStub' })
      expect(s.props('modelValue')).toBe(true)
      expect(s.props('flow')).toBe('give')
      expect(s.props('step')).toBe('give-mobile-photos')
      expect(s.props('start')).toBe('2026-10-01')
      expect(s.props('end')).toBe('2026-10-07')
    })

    it('names the three groupings in words', () => {
      const t = make({ step: 'give' }).find('.step-detail').text()
      expect(t).toContain('By device')
      expect(t).toContain('By signed in or not')
    })
  })

  describe('readable breakdown keys', () => {
    function withBreakdown(by) {
      store.funnels.data.flows[0].steps[0].by = by
      return make({ step: 'give' }).text()
    }
    const row = (key) => ({
      key,
      entered: 10,
      visits: 10,
      completed: 5,
      abandoned: 2,
    })

    it('says the iOS app, not app-ios', () => {
      const t = withBreakdown({
        device_class: [row('app-ios'), row('mobile')],
        logged_in: [],
        variant: [],
      })
      expect(t).toContain('iOS app')
      expect(t).not.toContain('app-ios')
    })

    it('names a device as the Device filter does, so the table and the select read alike', () => {
      const t = withBreakdown({
        device_class: [
          row('mobile'),
          row('tablet'),
          row('desktop'),
          row('app-ios'),
          row('app-android'),
        ],
        logged_in: [],
        variant: [],
      })
      for (const name of [
        'Website on a phone',
        'Website on a tablet',
        'Website on a desktop',
        'iOS app',
        'Android app',
      ]) {
        expect(t).toContain(name)
      }
      expect(t).not.toContain('mobile web')
    })

    it('heads the groups with the words of the outcome line', () => {
      // The shared table stub draws no headings, so this one does.
      const heads = []
      const w = mount(ModSysAdminUxFunnels, {
        props: { view: view({ flow: 'give', step: 'give' }) },
        global: {
          stubs: {
            ...uxStubs,
            'b-table': {
              template: '<table class="table" />',
              props: ['items', 'fields'],
              setup(props) {
                heads.push(props.fields.map((f) => f.label))
                return {}
              },
            },
            ModSysAdminUxSamples: true,
            ModSysAdminUxSecondThoughts: true,
            ModSysAdminUxHelp: true,
          },
          provide: { uxHrefFor: () => '#', uxFunnelLinks: [] },
        },
      })
      expect(w.find('.step-detail').exists()).toBe(true)
      expect(heads.length).toBeGreaterThan(0)
      for (const labels of heads) {
        expect(labels).toEqual([
          'Group',
          'Visits',
          'Finished',
          'Left without finishing',
        ])
      }
    })

    it('says a visit with no device recorded in words', () => {
      const t = withBreakdown({
        device_class: [row('')],
        logged_in: [],
        variant: [],
      })
      expect(t).toContain('No device recorded')
    })

    it('says signed in and not signed in, not true and false and not logged in', () => {
      const t = withBreakdown({
        device_class: [],
        logged_in: [row('true'), row('false')],
        variant: [],
      })
      expect(t).toContain('Signed in')
      expect(t).toContain('Not signed in')
      expect(t).not.toContain('ogged in')
    })

    it('says not in an experiment for an empty version, and spaces out the arms', () => {
      const t = withBreakdown({
        device_class: [],
        logged_in: [],
        variant: [row(''), row('photos-skip:b,show-hint:control')],
      })
      expect(t).toContain('By experiment version')
      expect(t).toContain('Not in an experiment')
      expect(t).toContain('photos-skip: b; show-hint: control')
    })

    it('leaves the experiment table out when its only row is "Not in an experiment"', () => {
      const t = withBreakdown({
        device_class: [row('mobile')],
        logged_in: [],
        variant: [row('')],
      })
      expect(t).not.toContain('By experiment version')
      expect(t).not.toContain('Not in an experiment')
    })
  })

  describe('which funnel is open', () => {
    it('is the one in the address', () => {
      expect(make({ flow: 'give' }).find('h4').text()).toBe(
        'Offering something'
      )
    })

    it('is the first funnel with starts when the address names none', () => {
      store.outcomes.data = {
        flows: [
          { flow: 'reply', started: 40, completed: 20 },
          { flow: 'ask', started: 60, completed: 30 },
        ],
      }
      store.funnels.data = { flows: [], previous: { flows: [] } }
      const w = make({ flow: '' })
      expect(w.find('h4').text()).toBe('Asking for something')
      const pressed = w.findAll('[aria-pressed="true"]')
      expect(pressed).toHaveLength(1)
      expect(pressed[0].find('.name').text()).toBe('Asking')
    })

    it('is give when nobody has started anything', () => {
      store.outcomes.data = { flows: [] }
      store.funnels.data = { flows: [] }
      expect(make({ flow: '' }).find('h4').text()).toBe('Offering something')
    })

    it('is give when the outcomes failed and the address names none', () => {
      store.outcomes.data = null
      store.outcomes.error = 'outcomes broke'
      expect(make({ flow: '' }).find('h4').text()).toBe('Offering something')
    })

    it('is the funnel in the address when the outcomes failed', () => {
      store.outcomes.data = null
      store.outcomes.error = 'outcomes broke'
      expect(make({ flow: 'reply' }).find('h4').text()).toBe(
        'Replying to a post'
      )
    })

    it('ignores a ux_flow that is no funnel, as a section that is not about one funnel may leave', () => {
      store.outcomes.data = {
        flows: [{ flow: 'ask', started: 60, completed: 30 }],
      }
      store.funnels.data = { flows: [] }
      const w = make({ flow: 'none' })
      expect(w.find('h4').text()).toBe('Asking for something')
      // No button is for "none". The open funnel is the default one.
      expect(
        w.findAll('a.ux-pick').map((a) => a.attributes('href'))
      ).not.toContain(expect.stringContaining('none'))
      expect(w.emitted('navigate')).toBeUndefined()
    })

    it('opens a flow the server returned that is not one of the seven', () => {
      store.outcomes.data = {
        flows: [{ flow: 'newflow', started: 10, completed: 5 }],
      }
      store.funnels.data = { flows: [] }
      expect(make({ flow: 'newflow' }).find('h4').text()).toBe('newflow')
    })

    it('treats a flow outside the seven as unknown until the server has answered', () => {
      store.funnels.data = null
      store.funnels.loading = true
      store.outcomes.data = null
      store.outcomes.loading = true
      expect(make({ flow: 'newflow' }).find('h4').text()).toBe(
        'Offering something'
      )
    })

    describe('while the reads reload', () => {
      // Only Asking has people in it, so the funnel that opens when the address names none is not
      // Offering. A reload keeps the earlier answers in the slots until the new ones arrive.
      beforeEach(() => {
        store.outcomes.data = {
          flows: [{ flow: 'ask', started: 60, completed: 30 }],
        }
        store.funnels.data = { flows: [] }
      })

      const pressed = (w) =>
        w.findAll('[aria-pressed="true"]').map((a) => a.find('.name').text())

      it('stays on the funnel it had chosen, and keeps its button pressed', async () => {
        const w = make({ flow: '' })
        expect(w.find('h4').text()).toBe('Asking for something')

        store.funnels.loading = true
        store.outcomes.loading = true
        await nextTick()

        expect(w.find('h4').text()).toBe('Asking for something')
        expect(pressed(w)).toEqual(['Asking'])
      })

      it('stays on it when only one of the reads reloads', async () => {
        const w = make({ flow: '' })
        store.outcomes.loading = true
        await nextTick()
        expect(w.find('h4').text()).toBe('Asking for something')
        expect(pressed(w)).toEqual(['Asking'])
      })

      it('moves to the new default only when the reloaded answers say it changed', async () => {
        const w = make({ flow: '' })
        store.funnels.loading = true
        store.outcomes.loading = true
        await nextTick()

        store.outcomes.data = {
          flows: [{ flow: 'reply', started: 80, completed: 40 }],
        }
        store.funnels.loading = false
        store.outcomes.loading = false
        await nextTick()
        expect(w.find('h4').text()).toBe('Replying to a post')
      })

      it('follows a funnel that is chosen while the reads reload', async () => {
        const w = make({ flow: '' })
        store.funnels.loading = true
        store.outcomes.loading = true
        await w.setProps({ view: view({ flow: 'reply' }) })
        expect(w.find('h4').text()).toBe('Replying to a post')
        expect(pressed(w)).toEqual(['Replying'])
      })

      it('goes back to Offering when the reload fails and the address names no funnel', async () => {
        const w = make({ flow: '' })
        store.funnels.loading = true
        store.outcomes.loading = true
        await nextTick()
        store.outcomes.data = null
        store.outcomes.loading = false
        store.outcomes.error = 'outcomes broke'
        await nextTick()
        expect(w.find('h4').text()).toBe('Offering something')
      })

      it('opens Offering before anything has loaded', () => {
        store.funnels.data = null
        store.outcomes.data = null
        store.funnels.loading = true
        store.outcomes.loading = true
        expect(make({ flow: '' }).find('h4').text()).toBe('Offering something')
      })
    })
  })

  describe('the picker', () => {
    it('always lists all seven funnels, in the order members meet them', () => {
      const w = make()
      expect(w.findAll('a.ux-pick .name').map((n) => n.text())).toEqual([
        'Offering',
        'Asking',
        'Replying',
        'Promising',
        'Marking taken',
        'Signing in',
        'Settings',
      ])
    })

    it('says how many started in each, and "Nobody started" for a quiet one', () => {
      const counts = make()
        .findAll('a.ux-pick .count')
        .map((c) => c.text())
      expect(counts[0]).toBe('220 started')
      expect(counts[1]).toBe('Nobody started')
    })

    it('adds a flow the server returned that it does not know, under its key', () => {
      store.outcomes.data.flows.push({
        flow: 'newflow',
        started: 5,
        completed: 1,
      })
      const names = make()
        .findAll('a.ux-pick .name')
        .map((n) => n.text())
      expect(names.at(-1)).toBe('newflow')
      expect(names).toHaveLength(8)
    })

    it('marks the open funnel as pressed', () => {
      const w = make({ flow: 'reply' })
      expect(
        w.findAll('a.ux-pick[aria-pressed="true"] .name').map((n) => n.text())
      ).toEqual(['Replying'])
    })

    it('passes a choice on, so the address can move', () => {
      const w = make()
      w.findComponent({ name: 'ModSysAdminUxFunnelPicker' }).vm.$emit(
        'navigate',
        {
          flow: 'ask',
        }
      )
      expect(w.emitted('navigate')).toEqual([[{ flow: 'ask' }]])
    })

    it('does not know the counts until the outcomes have arrived', () => {
      store.outcomes.data = null
      store.outcomes.loading = true
      expect(make().findAll('a.ux-pick .count')).toHaveLength(0)
    })
  })

  describe('the field figures', () => {
    beforeEach(() => {
      store.held.funnels = {
        start: '2026-10-01',
        end: '2026-10-07',
        device: 'app',
      }
    })

    it('are asked for the open funnel, with the dates and filters of the funnels', () => {
      make({ flow: 'give' })
      expect(store.loadSlots).toHaveBeenCalledWith(['fields'], {
        start: '2026-10-01',
        end: '2026-10-07',
        device: 'app',
        flow: 'give',
      })
    })

    it('are asked for the first funnel with starts on a first visit, never for give', () => {
      store.outcomes.data = {
        flows: [{ flow: 'ask', started: 60, completed: 30 }],
      }
      store.funnels.data = { flows: [] }
      const w = make({ flow: '' })
      const flows = store.loadSlots.mock.calls.map((c) => c[1].flow)
      expect(flows).toEqual(['ask'])
      expect(w.emitted('navigate')).toBeUndefined()
    })

    it('wait for the funnels and the outcomes to settle', async () => {
      store.funnels.data = null
      store.funnels.loading = true
      store.outcomes.data = null
      store.outcomes.loading = true
      make({ flow: '' })
      expect(store.loadSlots).not.toHaveBeenCalled()

      store.outcomes.data = {
        flows: [{ flow: 'ask', started: 60, completed: 30 }],
      }
      store.outcomes.loading = false
      await nextTick()
      // The funnels are still loading.
      expect(store.loadSlots).not.toHaveBeenCalled()

      store.funnels.data = { flows: [] }
      store.funnels.loading = false
      await nextTick()
      expect(store.loadSlots.mock.calls.map((c) => c[1].flow)).toEqual(['ask'])
    })

    it('are asked again for the funnel that is chosen', async () => {
      const w = make({ flow: 'give' })
      store.loadSlots.mockClear()
      await w.setProps({ view: view({ flow: 'reply' }) })
      expect(store.loadSlots).toHaveBeenCalledWith(
        ['fields'],
        expect.objectContaining({ flow: 'reply' })
      )
    })

    it('are asked again when the filters change', async () => {
      const w = make({ flow: 'give' })
      store.loadSlots.mockClear()
      store.held.funnels = {
        start: '2026-10-01',
        end: '2026-10-07',
        device: 'web',
      }
      await nextTick()
      expect(store.loadSlots).toHaveBeenCalledWith(
        ['fields'],
        expect.objectContaining({ device: 'web', flow: 'give' })
      )
      expect(w.emitted('navigate')).toBeUndefined()
    })

    it('are never asked for a ux_flow that is no funnel', () => {
      store.outcomes.data = {
        flows: [{ flow: 'ask', started: 60, completed: 30 }],
      }
      store.funnels.data = { flows: [] }
      make({ flow: 'none' })
      const flows = store.loadSlots.mock.calls.map((c) => c[1].flow)
      expect(flows).not.toContain('none')
      expect(flows).toEqual(['ask'])
    })
  })

  describe('a step in the address that the funnel does not have', () => {
    it('is taken out once the funnels have settled', () => {
      const w = make({ step: 'not-a-step' })
      expect(w.emitted('navigate')).toEqual([[{ step: undefined }]])
    })

    it('is kept when the funnel has it', () => {
      const w = make({ step: 'give-whereami' })
      expect(w.emitted('navigate')).toBeUndefined()
    })

    it('is kept while the funnels are loading', () => {
      store.funnels.data = null
      store.funnels.loading = true
      const w = make({ step: 'not-a-step' })
      expect(w.emitted('navigate')).toBeUndefined()
    })

    it('is kept after the funnels failed', () => {
      store.funnels.data = null
      store.funnels.error = 'broke'
      const w = make({ step: 'not-a-step' })
      expect(w.emitted('navigate')).toBeUndefined()
    })

    it('is taken out of a funnel that nobody started', () => {
      const w = make({ flow: 'settings', step: 'settings' })
      expect(w.emitted('navigate')).toEqual([[{ step: undefined }]])
    })
  })

  describe('words that go with the filters', () => {
    it('warns that Signed in is rough for the funnels where someone can sign in part way', () => {
      for (const flow of ['give', 'ask', 'reply', 'login']) {
        const w = make({ flow, signed: 'in', filtersActive: true })
        expect(w.text(), flow).toContain(
          'Signed in is the state at each step, not at the start of the visit.'
        )
      }
    })

    it('does not warn for the other funnels, or when Signed in is not set', () => {
      for (const flow of ['chat_promise', 'chat_outcome', 'settings']) {
        expect(
          make({ flow, signed: 'in', filtersActive: true }).text(),
          flow
        ).not.toContain('Signed in is the state at each step')
      }
      expect(make({ flow: 'give' }).text()).not.toContain(
        'Signed in is the state'
      )
    })

    it('says Started and finished count everyone when Signed in is set, and the server left it alone', () => {
      store.outcomes.data = {
        ...clone(outcomes),
        filters: { device: '', signed_in: '' },
        ignored: ['signed_in'],
      }
      store.answered.outcomes = { start: 'a', end: 'b', signed_in: '1' }
      const t = make({ signed: 'in', filtersActive: true }).text()
      expect(t).toContain(
        'Started and finished count everyone. Signed in changes the steps only.'
      )
      expect(t).not.toContain('Not filtered by sign-in')
    })

    it('says so, once, when the server does not say which filters it used', () => {
      store.funnels.data = {
        flows: clone(funnels).flows,
        previous: { flows: [] },
      }
      store.outcomes.data = { flows: clone(outcomes).flows }
      store.answered.funnels = { device: 'mobile' }
      store.answered.outcomes = { device: 'mobile' }
      const notes = make({ device: 'mobile', filtersActive: true }).findAll(
        '.ux-note'
      )
      expect(notes.map((n) => n.text())).toEqual([
        'The server did not say which filters it used, so these figures may not be filtered.',
      ])
    })

    it('says when fewer than 100 people started', () => {
      store.outcomes.data.flows[0].started = 40
      expect(make().find('.ux-note').text()).toBe(
        'Only 40 people started this funnel. Percentages from so few people can change a lot.'
      )
    })

    it('says one person in the singular', () => {
      store.outcomes.data.flows[0].started = 1
      expect(make().find('.ux-note').text()).toContain(
        'Only 1 person started this funnel.'
      )
    })

    it('does not say it at 100 people, or when nobody started', () => {
      store.outcomes.data.flows[0].started = 100
      expect(make().text()).not.toContain('Only 100')
      store.outcomes.data.flows[0].started = 0
      expect(make().text()).not.toContain('Percentages from so few people')
    })
  })

  describe('when nobody started', () => {
    it('says so', () => {
      store.outcomes.data = { flows: [] }
      store.funnels.data = { flows: [] }
      const w = make()
      expect(w.find('.nobody').text()).toBe(
        'Nobody started this funnel in this period.'
      )
      expect(w.find('.headline').exists()).toBe(false)
      expect(w.findAll('.worst-tag')).toHaveLength(0)
    })

    it('still draws second thoughts and help for the funnel, which say so when they are empty', () => {
      store.outcomes.data = { flows: [] }
      store.funnels.data = { flows: [] }
      const w = make()
      expect(w.findComponent({ name: 'ThoughtsStub' }).props('flow')).toBe(
        'give'
      )
      expect(w.findComponent({ name: 'HelpStub' }).props('flow')).toBe('give')
    })

    it('still offers the sections that want a link from a funnel', () => {
      store.outcomes.data = { flows: [] }
      store.funnels.data = { flows: [] }
      const w = make(
        {},
        { links: [{ key: 'experiments', label: 'Experiments' }] }
      )
      expect(w.find('.also').text()).toContain('Experiments')
    })

    it('says so with these filters, and offers to show everyone', async () => {
      store.outcomes.data = { flows: [] }
      store.funnels.data = { flows: [] }
      const w = make({ device: 'app-ios', filtersActive: true })
      expect(w.find('.nobody').text()).toContain(
        'Nobody started this funnel with these filters.'
      )
      await w.find('.nobody button').trigger('click')
      expect(w.emitted('navigate')).toEqual([
        [{ device: undefined, signed: undefined }],
      ])
    })

    it('offers no reset link when no filter is set', () => {
      store.outcomes.data = { flows: [] }
      store.funnels.data = { flows: [] }
      expect(make().find('.nobody button').exists()).toBe(false)
    })
  })

  describe('also for this funnel', () => {
    it('links each section that wants a link from here, for the open funnel', async () => {
      const w = make(
        {},
        {
          links: [
            { key: 'experiments', label: 'Experiments' },
            { key: 'members', label: 'What members say' },
          ],
        }
      )
      const also = w.find('.also')
      expect(also.text()).toBe(
        'Also for this funnel: Experiments, What members say'
      )
      const links = also.findAll('a')
      expect(links[0].attributes('href')).toBe(
        '/x?section=experiments&flow=give'
      )
      await links[1].trigger('click')
      expect(w.emitted('navigate')).toEqual([
        [{ section: 'members', flow: 'give' }],
      ])
    })

    it('leaves a click with a modifier key to the browser', async () => {
      const w = make(
        {},
        { links: [{ key: 'experiments', label: 'Experiments' }] }
      )
      await w.find('.also a').trigger('click', { ctrlKey: true })
      expect(w.emitted('navigate')).toBeUndefined()
    })

    it('is not drawn when no section wants one', () => {
      expect(make().find('.also').exists()).toBe(false)
    })
  })

  describe('second thoughts and help', () => {
    it('are given the open funnel, and are closed unless the address opens them', () => {
      store.outcomes.data.flows.push({
        flow: 'ask',
        started: 60,
        completed: 30,
      })
      const w = make({ flow: 'ask' })
      expect(w.findComponent({ name: 'ThoughtsStub' }).props()).toEqual({
        flow: 'ask',
        open: false,
      })
      expect(w.findComponent({ name: 'HelpStub' }).props()).toEqual({
        flow: 'ask',
        open: false,
      })
      const open = make({ open: 'help' })
      expect(open.findComponent({ name: 'HelpStub' }).props('open')).toBe(true)
      expect(open.findComponent({ name: 'ThoughtsStub' }).props('open')).toBe(
        false
      )
    })

    it('ask the address to open and close them', () => {
      const w = make()
      w.findComponent({ name: 'ThoughtsStub' }).vm.$emit('toggle')
      expect(w.emitted('navigate')).toEqual([[{ open: 'thoughts' }]])
      const open = make({ open: 'help' })
      open.findComponent({ name: 'HelpStub' }).vm.$emit('toggle')
      expect(open.emitted('navigate')).toEqual([[{ open: undefined }]])
    })
  })

  describe('loading, failing and a missing route', () => {
    it('shows a spinner, not an empty funnel, before the first answer', () => {
      store.funnels.data = null
      store.outcomes.data = null
      const w = make()
      expect(w.find('.spinner').exists()).toBe(true)
      expect(w.find('.nobody').exists()).toBe(false)
    })

    it('shows an error from either request', () => {
      store.funnels.data = null
      store.funnels.error = 'funnels broke'
      expect(make().text()).toContain('funnels broke')
      store.funnels.error = null
      store.funnels.data = clone(funnels)
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

    it('says the part is not switched on when the route is missing, and shows no fault', () => {
      store.funnels.data = null
      store.funnels.missing = true
      const w = make()
      expect(w.text()).toContain('This part is not switched on yet.')
      expect(w.find('.notice-message.danger').exists()).toBe(false)
    })
  })
})
