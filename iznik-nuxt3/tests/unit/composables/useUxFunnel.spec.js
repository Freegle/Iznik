import { describe, it, expect } from 'vitest'
import {
  funnelsFor,
  headline,
  flowTitle,
  stepLabel,
  familyLabel,
  deviceLabel,
  worstStepOf,
  summariseFlow,
  outcomeLine,
  stepName,
  stepWithMost,
  MIN_VISITS_FOR_WORST,
  MIN_VISITS_FILTERED,
  FLOW_TITLES,
} from '~/modtools/composables/useUxFunnel'
import * as flows from '~/modtools/composables/useUxFlows'
import { DEVICE_LABELS } from '~/modtools/composables/useUxFormat'
import { FLOW_ROUTES } from '~/composables/useFlowRoutes'

const s = (step, over = {}) => ({
  step,
  entered: 100,
  reentered: 0,
  visits: 100,
  completed: 60,
  backed: 5,
  abandoned: 20,
  ...over,
})

describe('useUxFunnel', () => {
  describe('funnelsFor', () => {
    it('splits give into desktop and mobile funnels in route order', () => {
      const out = funnelsFor('give', [
        s('give-whoami', { entered: 200 }),
        s('give', { entered: 300 }),
        s('give-mobile-whereami', { entered: 150 }),
        s('give-mobile-photos', { entered: 320 }),
        s('give-whereami', { entered: 250 }),
        s('give-mobile-details', { entered: 220 }),
      ])
      expect(out.map((f) => f.family)).toEqual(['desktop', 'mobile'])
      expect(out[0].steps.map((x) => x.step)).toEqual([
        'give',
        'give-whereami',
        'give-whoami',
      ])
      expect(out[1].steps.map((x) => x.step)).toEqual([
        'give-mobile-photos',
        'give-mobile-details',
        'give-mobile-whereami',
      ])
    })

    it('measures reach against the first step and loss against visits', () => {
      const [desk] = funnelsFor('give', [
        s('give', { entered: 300, visits: 310, abandoned: 93 }),
        s('give-whereami', { entered: 150, visits: 160, abandoned: 16 }),
      ])
      expect(desk.steps[0].reach).toBe(1)
      expect(desk.steps[1].reach).toBe(0.5)
      expect(desk.steps[0].lossRate).toBeCloseTo(0.3)
      expect(desk.steps[1].lossRate).toBeCloseTo(0.1)
      expect(desk.steps[0].label).toBe('Item and photos')
    })

    it('orders state-driven flows as the states happen and leaves out empty steps', () => {
      const [only] = funnelsFor('reply', [
        s('COMPLETED', { entered: 50 }),
        s('JOINING_GROUP', { entered: 60 }),
        s('COMPOSING', { entered: 100 }),
        s('AUTHENTICATING', { entered: 0, visits: 0 }),
      ])
      expect(only.family).toBe('')
      // The member types first, and is joined to the community only after sending and signing in.
      expect(only.steps.map((x) => x.step)).toEqual([
        'COMPOSING',
        'JOINING_GROUP',
        'COMPLETED',
      ])
    })

    it('measures the reply funnel from the moment the member starts typing', () => {
      const [f] = funnelsFor('reply', [
        s('COMPOSING', { entered: 1000 }),
        s('VALIDATING', { entered: 700 }),
        s('AUTHENTICATING', { entered: 300 }),
        s('JOINING_GROUP', { entered: 650 }),
        s('CREATING_CHAT', { entered: 640 }),
        s('COMPLETED', { entered: 600 }),
      ])
      expect(f.steps[0].step).toBe('COMPOSING')
      expect(f.steps[0].reach).toBe(1)
      expect(f.steps.find((x) => x.step === 'VALIDATING').reach).toBe(0.7)
      expect(f.steps.find((x) => x.step === 'COMPLETED').reach).toBe(0.6)
    })

    it('never lets reach exceed one when a later step has more entries', () => {
      const [f] = funnelsFor('reply', [
        s('COMPOSING', { entered: 10 }),
        s('VALIDATING', { entered: 40 }),
      ])
      expect(f.steps[1].reach).toBe(1)
    })

    describe('different ways into one stage', () => {
      it('treats signing in and joining as one stage, not two steps in a row', () => {
        const [f] = funnelsFor('login', [
          s('signup', { entered: 100, visits: 120, abandoned: 40 }),
          s('signin', { entered: 300, visits: 380, abandoned: 60 }),
        ])
        expect(f.steps).toHaveLength(1)
        const stage = f.steps[0]
        expect(stage.label).toBe('Signing in or Joining')
        expect(stage.entered).toBe(400)
        expect(stage.visits).toBe(500)
        expect(stage.abandoned).toBe(100)
        expect(stage.reach).toBe(1)
        expect(stage.lossRate).toBeCloseTo(0.2)
        // Commonest way in first, each on its own figures and a share of everyone who started.
        expect(stage.alternatives.map((a) => a.step)).toEqual([
          'signin',
          'signup',
        ])
        expect(stage.alternatives[0].reach).toBe(0.75)
        expect(stage.alternatives[1].reach).toBe(0.25)
        expect(stage.alternatives[1].lossRate).toBeCloseTo(40 / 120)
      })

      it('groups the Give starts that share a rank: the normal one and the clearance one', () => {
        const [desk] = funnelsFor('give', [
          s('give', { entered: 300 }),
          s('give-clearance', { entered: 60 }),
          s('give-whereami', { entered: 200 }),
        ])
        expect(desk.steps.map((x) => x.label)).toEqual([
          'Item and photos or Clearance start',
          'Location',
        ])
        // Reach is a share of everyone who started either way.
        expect(desk.steps[1].reach).toBeCloseTo(200 / 360)
        expect(desk.steps[0].alternatives).toHaveLength(2)
      })

      it('groups the mobile starts too, and leaves a lone step alone', () => {
        const [mob] = funnelsFor('give', [
          s('give-mobile', { entered: 100 }),
          s('give-mobile-photos', { entered: 100 }),
          s('give-mobile-details', { entered: 150 }),
        ])
        expect(mob.steps).toHaveLength(2)
        expect(mob.steps[0].alternatives).toHaveLength(2)
        expect(mob.steps[1].alternatives).toBeUndefined()
        expect(mob.steps[1].reach).toBeCloseTo(0.75)
      })

      it('does not run unknown steps together', () => {
        const [f] = funnelsFor('reply', [
          s('A_MYSTERY', { entered: 5 }),
          s('B_MYSTERY', { entered: 4 }),
        ])
        expect(f.steps).toHaveLength(2)
      })
    })

    describe('a way out that is not a step', () => {
      it('takes the forgotten password step out of the funnel and into exits', () => {
        const [f] = funnelsFor('login', [
          s('signin', { entered: 300, visits: 380 }),
          s('forgot', { entered: 40, visits: 45, abandoned: 40 }),
        ])
        expect(f.steps.map((x) => x.step)).toEqual(['signin'])
        expect(f.exits).toEqual([
          {
            step: 'forgot',
            label: 'Went to reset their password',
            entered: 40,
            visits: 45,
          },
        ])
      })

      it('still shows the exit when it is all that happened', () => {
        const out = funnelsFor('login', [
          s('forgot', { entered: 3, visits: 3 }),
        ])
        expect(out).toHaveLength(1)
        expect(out[0].steps).toEqual([])
        expect(out[0].exits[0].entered).toBe(3)
      })

      it('gives every other flow no exits', () => {
        const [f] = funnelsFor('reply', [s('COMPOSING')])
        expect(f.exits).toEqual([])
      })
    })

    it('puts unknown steps last', () => {
      const [f] = funnelsFor('reply', [
        s('MYSTERY', { entered: 999 }),
        s('COMPOSING', { entered: 1 }),
      ])
      expect(f.steps.map((x) => x.step)).toEqual(['COMPOSING', 'MYSTERY'])
      expect(stepLabel('MYSTERY')).toBe('MYSTERY')
    })

    it('handles no steps', () => {
      expect(funnelsFor('give', [])).toEqual([])
      expect(funnelsFor('give', undefined)).toEqual([])
    })
  })

  describe('headline', () => {
    const outcome = { started: 822, completed: 393 }
    const steps = [
      s('give-mobile-photos', {
        visits: 311,
        abandoned: 97,
        by: {
          device_class: [
            { key: 'mobile', visits: 280, abandoned: 90 },
            { key: 'tablet', visits: 31, abandoned: 7 },
          ],
        },
      }),
      s('give-whereami', { visits: 210, abandoned: 2 }),
      s('tiny', { visits: 5, abandoned: 5 }),
    ]

    it('names the flow, the figures, the previous period and the worst step with its worst device', () => {
      const t = headline('give', outcome, steps, {
        started: 800,
        completed: 360,
      })
      expect(t).toBe(
        'Offering something: 822 started, 393 finished (48%, before that 45%). Biggest loss: 31% of the 311 visits to Photos ended there, worst for Website on a phone (32%).'
      )
    })

    it('never names the forgotten password exit as the biggest loss', () => {
      const t = headline(
        'login',
        { started: 500, completed: 300 },
        [
          s('signin', { visits: 400, abandoned: 40 }),
          s('forgot', { visits: 100, abandoned: 95 }),
        ],
        null
      )
      expect(t).toContain('visits to Signing in ended there')
      expect(t).not.toContain('Forgotten password')
    })

    it('ignores steps with too few visits when picking the worst', () => {
      const t = headline('give', outcome, steps, null)
      expect(t).not.toContain('tiny')
    })

    it('says so when no step has enough visits to say', () => {
      const t = headline(
        'give',
        { started: 30, completed: 10 },
        [s('give', { visits: 12, abandoned: 6 })],
        null
      )
      expect(t).toBe(
        'Offering something: 30 started, 10 finished (33%). Too few visits to say which step lost most.'
      )
    })

    it('needs more visits for a step when a filter is set', () => {
      const few = [s('give', { visits: 30, abandoned: 15 })]
      expect(headline('give', outcome, few, null)).toContain('Biggest loss')
      expect(
        headline('give', outcome, few, null, { filtered: true })
      ).toContain('Too few visits to say')
    })

    it('leaves a device with no name out of the worst-device clause', () => {
      const t = headline(
        'give',
        outcome,
        [
          s('give', {
            visits: 100,
            abandoned: 30,
            by: {
              device_class: [
                { key: '', visits: 60, abandoned: 10 },
                { key: 'desktop', visits: 40, abandoned: 20 },
              ],
            },
          }),
        ],
        null
      )
      expect(t).not.toContain('worst for')
    })

    it('says so when nobody started', () => {
      expect(headline('ask', { started: 0, completed: 0 }, steps, null)).toBe(
        'Asking for something: nobody started in this period.'
      )
      expect(headline('ask', undefined, steps, null)).toContain(
        'nobody started'
      )
    })

    it('says so when no step lost many people', () => {
      const t = headline(
        'reply',
        { started: 100, completed: 90 },
        [s('COMPOSING', { abandoned: 0 })],
        null
      )
      expect(t).toContain('No step lost many people.')
    })

    it('leaves the device out when only one device has enough visits', () => {
      const t = headline(
        'give',
        outcome,
        [
          s('give', {
            visits: 100,
            abandoned: 30,
            by: {
              device_class: [{ key: 'desktop', visits: 100, abandoned: 30 }],
            },
          }),
        ],
        null
      )
      expect(t).toContain('ended there.')
      expect(t).not.toContain('worst for')
    })
  })

  describe('worstStepOf', () => {
    it('needs 20 visits and at least one abandon', () => {
      expect(MIN_VISITS_FOR_WORST).toBe(20)
      expect(
        worstStepOf([s('a', { visits: 19, abandoned: 19 })], { flow: 'give' })
      ).toBeNull()
      expect(
        worstStepOf([s('a', { visits: 20, abandoned: 1 })], { flow: 'give' })
          .step
      ).toBe('a')
      expect(
        worstStepOf([s('a', { visits: 500, abandoned: 0 })], { flow: 'give' })
      ).toBeNull()
    })

    it('needs 50 visits when asked to', () => {
      expect(MIN_VISITS_FILTERED).toBe(50)
      const steps = [s('a', { visits: 49, abandoned: 40 })]
      expect(worstStepOf(steps, { flow: 'give' }).step).toBe('a')
      expect(
        worstStepOf(steps, { flow: 'give', minVisits: MIN_VISITS_FILTERED })
      ).toBeNull()
    })

    it('takes the highest share of its visits, not the most people', () => {
      const w = worstStepOf(
        [
          s('big', { visits: 1000, abandoned: 200 }),
          s('small', { visits: 50, abandoned: 25 }),
        ],
        { flow: 'give' }
      )
      expect(w.step).toBe('small')
    })

    it('ignores a way out of the flow', () => {
      const w = worstStepOf(
        [
          s('signin', { visits: 400, abandoned: 40 }),
          s('forgot', { visits: 100, abandoned: 95 }),
        ],
        { flow: 'login' }
      )
      expect(w.step).toBe('signin')
    })

    it('breaks a tie with the step that has more visits, then the earlier one', () => {
      expect(
        worstStepOf(
          [
            s('few', { visits: 100, abandoned: 30 }),
            s('many', { visits: 200, abandoned: 60 }),
          ],
          { flow: 'give' }
        ).step
      ).toBe('many')
      expect(
        worstStepOf(
          [
            s('first', { visits: 100, abandoned: 30 }),
            s('second', { visits: 100, abandoned: 30 }),
          ],
          { flow: 'give' }
        ).step
      ).toBe('first')
    })

    it('returns null when nothing qualifies, and for no steps', () => {
      expect(worstStepOf([], { flow: 'give' })).toBeNull()
      expect(worstStepOf(undefined, { flow: 'give' })).toBeNull()
    })
  })

  describe('summariseFlow', () => {
    const outcome = { started: 755, completed: 389 }
    const steps = [s('give-mobile-photos', { visits: 375, abandoned: 112 })]

    it('gives the figures and the rates', () => {
      const out = summariseFlow('give', outcome, steps, {
        started: 800,
        completed: 392,
      })
      expect(out).toMatchObject({
        flow: 'give',
        started: 755,
        finished: 389,
        finishedRate: 389 / 755,
        beforeRate: 392 / 800,
      })
    })

    it('has no rate for the period before when nobody started then', () => {
      expect(summariseFlow('give', outcome, steps, null).beforeRate).toBeNull()
      expect(
        summariseFlow('give', outcome, steps, { started: 0, completed: 0 })
          .beforeRate
      ).toBeNull()
    })

    it('names the step that lost most', () => {
      const out = summariseFlow('give', outcome, steps, null)
      expect(out.loss).toEqual({
        state: 'step',
        step: 'give-mobile-photos',
        label: 'Photos',
        lossRate: 112 / 375,
        visits: 375,
        abandoned: 112,
      })
    })

    it.each([
      ['nobody', undefined, steps],
      ['nobody', { started: 0, completed: 0 }, steps],
      ['few', outcome, [s('a', { visits: 5, abandoned: 5 })]],
      ['few', outcome, []],
      ['none', outcome, [s('a', { visits: 90, abandoned: 0 })]],
      ['step', outcome, steps],
    ])('is %s for outcome %j', (state, o, st) => {
      expect(summariseFlow('give', o, st, null).loss.state).toBe(state)
    })

    it('has no figures for a flow nobody started', () => {
      const out = summariseFlow('ask', undefined, steps, null)
      expect(out).toMatchObject({
        started: 0,
        finished: 0,
        finishedRate: null,
        beforeRate: null,
      })
    })

    it('counts a way out as no loss, so a flow of only that is "few"', () => {
      const out = summariseFlow(
        'login',
        { started: 100, completed: 50 },
        [s('forgot', { visits: 100, abandoned: 90 })],
        null
      )
      expect(out.loss.state).toBe('few')
    })

    it('needs more visits when a filter is set', () => {
      const thin = [s('a', { visits: 30, abandoned: 15 })]
      expect(summariseFlow('give', outcome, thin, null).loss.state).toBe('step')
      expect(
        summariseFlow('give', outcome, thin, null, { filtered: true }).loss
          .state
      ).toBe('few')
    })

    it('names the same step in the headline and in the all-funnels table', () => {
      const many = [
        s('give', { visits: 800, abandoned: 80 }),
        s('give-whereami', { visits: 400, abandoned: 120 }),
        s('give-mobile-photos', { visits: 375, abandoned: 150 }),
        s('give-mobile-details', { visits: 300, abandoned: 30 }),
      ]
      const table = summariseFlow('give', outcome, many, null).loss
      const text = headline('give', outcome, many, null)
      expect(table.step).toBe('give-mobile-photos')
      expect(text).toContain(
        `${Math.round(table.lossRate * 100)}% of the 375 visits to ${table.label} ended there`
      )
    })

    it('has exactly one worst step across the desktop and mobile charts', () => {
      // The chart used to work this out inside each page family and the headline over the whole
      // flow, so a funnel could show three different "biggest losses".
      const many = [
        s('give', { entered: 800, visits: 800, abandoned: 160 }),
        s('give-whereami', { entered: 500, visits: 500, abandoned: 60 }),
        s('give-mobile-photos', { entered: 300, visits: 300, abandoned: 120 }),
        s('give-mobile-details', { entered: 200, visits: 200, abandoned: 90 }),
      ]
      const worst = worstStepOf(many, { flow: 'give' })
      expect(worst.step).toBe('give-mobile-details')
      const families = funnelsFor('give', many)
      expect(families.map((f) => f.family)).toEqual(['desktop', 'mobile'])
      const named = families.flatMap((f) =>
        f.steps.flatMap((st) => (st.alternatives || [st]).map((x) => x.step))
      )
      expect(named.filter((step) => step === worst.step)).toHaveLength(1)
      expect(summariseFlow('give', outcome, many, null).loss.step).toBe(
        worst.step
      )
    })
  })

  describe('outcomeLine', () => {
    it('gives every figure of the old panel, as a percent with its count over its base', () => {
      expect(
        outcomeLine({
          started: 755,
          submitted: 600,
          submit_failed: 12,
          completed: 389,
          abandoned: 280,
          resumed: 40,
        })
      ).toBe(
        'Submitted 79.5% (600/755). 2.0% (12/600) of those failed. Finished 51.5% (389/755). 37.1% (280/755) left without finishing. 40 came back later.'
      )
    })

    it("reads the old panel's own row the same way", () => {
      const t = outcomeLine({
        started: 100,
        submitted: 50,
        submit_failed: 5,
        completed: 45,
        abandoned: 40,
        resumed: 3,
      })
      expect(t).toContain('50.0% (50/100)')
      expect(t).toContain('10.0% (5/50)')
      expect(t).toContain('45.0% (45/100)')
      expect(t).toContain('40.0% (40/100)')
      expect(t).toContain('3 came back later')
    })

    it('says so when a count is none', () => {
      const t = outcomeLine({
        started: 100,
        submitted: 50,
        submit_failed: 0,
        completed: 0,
        abandoned: 0,
        resumed: 0,
      })
      expect(t).toContain('None of those failed.')
      expect(t).toContain('Nobody finished.')
      expect(t).toContain('Nobody left without finishing.')
      expect(t).toContain('Nobody came back later.')
    })

    it('says nobody submitted, and leaves out the failed clause', () => {
      const t = outcomeLine({
        started: 100,
        submitted: 0,
        submit_failed: 0,
        completed: 0,
        abandoned: 100,
        resumed: 0,
      })
      expect(t.startsWith('Nobody submitted.')).toBe(true)
      expect(t).not.toContain('failed')
    })

    it('puts thousands separators in the counts', () => {
      const t = outcomeLine({
        started: 12400,
        submitted: 9000,
        submit_failed: 90,
        completed: 8000,
        abandoned: 3000,
        resumed: 1500,
      })
      expect(t).toContain('72.6% (9,000/12,400)')
      expect(t).toContain('1,500 came back later')
    })

    it('gives nothing for no row, and nothing when nobody started', () => {
      expect(outcomeLine(undefined)).toBeNull()
      expect(outcomeLine(null)).toBeNull()
      expect(outcomeLine({ started: 0, submitted: 0 })).toBeNull()
    })
  })

  it('keeps the funnel names in one place', () => {
    expect(FLOW_TITLES).toBe(flows.FLOW_TITLES)
    expect(flowTitle).toBe(flows.flowTitle)
  })

  it('labels flows, families and devices for people', () => {
    expect(flowTitle('chat_promise')).toBe('Promising an item')
    expect(flowTitle('other')).toBe('other')
    expect(familyLabel('mobile')).toBe('Mobile pages')
    expect(familyLabel('')).toBe('')
    expect(deviceLabel('weird')).toBe('weird')
  })

  it('names a device as the Device filter does, so the select and the sentences read alike', () => {
    for (const [key, text] of Object.entries(DEVICE_LABELS)) {
      expect(deviceLabel(key)).toBe(text)
    }
    expect(deviceLabel('mobile')).toBe('Website on a phone')
    expect(deviceLabel('app-ios')).toBe('iOS app')
    expect(deviceLabel('app-android')).toBe('Android app')
  })

  describe('stepName', () => {
    it('is the label of a step, where the label belongs to one page', () => {
      expect(stepName('give', 'give-mobile-photos')).toBe('Photos')
      expect(stepName('give', 'give-whereami')).toBe('Location')
      expect(stepName('ask', 'ask-mobile')).toBe('Start')
      expect(stepName('reply', 'COMPLETED')).toBe('Sent')
    })

    it('adds the page family where the desktop and the mobile page share a label', () => {
      expect(stepName('give', 'give-options')).toBe('Options (desktop)')
      expect(stepName('give', 'give-mobile-options')).toBe('Options (mobile)')
    })

    it('adds it only where the label is shared, and only within one funnel', () => {
      // Offering and Asking both have a mobile page called Start or Photos, in funnels of their own.
      expect(stepName('ask', 'ask-mobile-photos')).toBe('Photos')
      expect(stepName('give', 'give-mobile')).toBe('Start')
    })

    it('leaves a step it does not know as it is', () => {
      expect(stepName('give', 'give-mystery')).toBe('give-mystery')
      expect(stepName('nothing', 'x')).toBe('x')
    })

    it('does not add a family to a funnel that is not made of pages', () => {
      expect(stepName('login', 'signin')).toBe('Signing in')
      expect(stepName('reply', 'AUTHENTICATING')).toBe('Signing in')
    })

    it('never gives two pages of one funnel the same name', () => {
      for (const flow of ['give', 'ask']) {
        const names = Object.keys(FLOW_ROUTES[flow]).map((step) =>
          stepName(flow, step)
        )
        expect(new Set(names).size, flow).toBe(names.length)
      }
    })
  })

  describe('the page family in the biggest loss', () => {
    // Both Options pages. Which of them lost people is the whole point of the sentence.
    const steps = [
      s('give-options', { visits: 310, abandoned: 5 }),
      s('give-mobile-options', { visits: 230, abandoned: 120 }),
    ]

    it('is in the label the all-funnels table prints', () => {
      const out = summariseFlow('give', { started: 600, completed: 300 }, steps)
      expect(out.loss.step).toBe('give-mobile-options')
      expect(out.loss.label).toBe('Options (mobile)')
    })

    it('is in the headline', () => {
      const t = headline('give', { started: 600, completed: 300 }, steps, null)
      expect(t).toContain('visits to Options (mobile) ended there')
    })
  })

  describe('stepWithMost', () => {
    const rows = (...list) =>
      list.map(([step, backed, visits]) => ({ step, backed, visits }))

    it('is the step where the biggest share of its visits had the figure', () => {
      const out = stepWithMost(
        rows(['a', 25, 100], ['b', 30, 100], ['c', 40, 4000]),
        'backed'
      )
      expect(out).toMatchObject({ state: 'step' })
      expect(out.step.step).toBe('b')
    })

    it('needs a step to have 20 visits, and says there are too few when none has', () => {
      expect(stepWithMost(rows(['a', 9, 19]), 'backed')).toEqual({
        state: 'few',
      })
      expect(stepWithMost([], 'backed')).toEqual({ state: 'few' })
      expect(stepWithMost(undefined, 'backed')).toEqual({ state: 'few' })
    })

    it('says nobody when the steps with enough visits had none', () => {
      expect(stepWithMost(rows(['a', 0, 500]), 'backed')).toEqual({
        state: 'none',
        elsewhere: false,
      })
    })

    it('says so when the only ones that had some are steps with too few visits', () => {
      expect(stepWithMost(rows(['a', 0, 500], ['b', 3, 5]), 'backed')).toEqual({
        state: 'none',
        elsewhere: true,
      })
    })

    it('ignores a step with too few visits even when its share is the biggest', () => {
      const out = stepWithMost(rows(['a', 5, 100], ['b', 5, 6]), 'backed')
      expect(out.step.step).toBe('a')
    })

    it('reads any figure by name', () => {
      const out = stepWithMost(
        [{ step: 'a', help_sought: 8, visits: 40 }],
        'help_sought'
      )
      expect(out.step.help_sought).toBe(8)
    })

    it('takes the first on a tie', () => {
      const out = stepWithMost(rows(['a', 10, 100], ['b', 10, 100]), 'backed')
      expect(out.step.step).toBe('a')
    })
  })
})
