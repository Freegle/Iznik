/**
 * Tests for composables/useFlowTelemetry.js - the contract is
 * docs/developers/reference/ux-telemetry.md section 2.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

import {
  FlowName,
  FlowEvent,
  useFlowTelemetry,
  getLiveFlowInstanceId,
  noteHelpSought,
  flowArm,
  _resetFlowTelemetryForTests,
} from '~/composables/useFlowTelemetry'

const log = vi.hoisted(() => ({
  action: vi.fn(),
  flush: vi.fn(),
  signedIn: false,
  variant: '',
  now: null,
  exposures: [],
}))

vi.mock('~/composables/useClientLog', () => ({
  action: log.action,
  flush: log.flush,
  isSignedIn: () => log.signedIn,
}))
vi.mock('~/composables/useExperiment', () => ({
  experimentVariant: () => log.variant,
  // The member's arm right now, which can differ from the one frozen into a live flow.
  useExperiment: (name) => {
    log.exposures.push(['now', name])
    return log.now
  },
  noteExposure: (name, arm) => log.exposures.push(['frozen', name, arm]),
  armInVariant: (variant, name) => {
    for (const part of String(variant || '').split(',')) {
      const i = part.indexOf(':')
      if (i > 0 && part.slice(0, i) === name) return part.slice(i + 1)
    }
    return null
  },
}))

function events() {
  return log.action.mock.calls.map(([name, ctx]) => ({ name, ...ctx }))
}

function last() {
  return events().at(-1)
}

describe('useFlowTelemetry', () => {
  let flow

  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-12T10:00:00Z'))
    _resetFlowTelemetryForTests()
    log.action.mockClear()
    log.flush.mockClear()
    log.variant = ''
    log.signedIn = false
    log.now = null
    log.exposures = []
    flow = useFlowTelemetry(FlowName.GIVE)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  describe('names', () => {
    it('exports the flow names of the contract', () => {
      expect(Object.values(FlowName).sort()).toEqual(
        [
          'ask',
          'chat_outcome',
          'chat_promise',
          'give',
          'login',
          'reply',
          'settings',
        ].sort()
      )
    })

    it('exports the event names of the contract', () => {
      expect(Object.values(FlowEvent).sort()).toEqual(
        [
          'flow_start',
          'step_enter',
          'step_complete',
          'step_back',
          'field_revisit',
          'validation_failed',
          'choice_switched',
          'flow_submit',
          'flow_submit_result',
          'flow_complete',
          'flow_abandon',
          'flow_resume',
          'help_sought',
        ].sort()
      )
    })
  })

  describe('start', () => {
    it('sends flow_start as a flow event_type with an instance id and the entry', () => {
      flow.start('navbar')
      const e = last()
      expect(e.name).toBe('flow_start')
      expect(e.event_type).toBe('flow')
      expect(e.flow).toBe('give')
      expect(e.entry).toBe('navbar')
      expect(e.flow_instance_id).toMatch(/^[0-9a-f-]{36}$/)
      expect(e.step_seq).toBe(0)
      expect(e.variant).toBe('')
    })

    it('exposes the live instance id', () => {
      expect(getLiveFlowInstanceId()).toBeNull()
      flow.start('navbar')
      expect(getLiveFlowInstanceId()).toBe(last().flow_instance_id)
    })

    it('does not start a second instance while one is live', () => {
      flow.start('navbar')
      const id = last().flow_instance_id
      log.action.mockClear()
      flow.start('home')
      expect(log.action).not.toHaveBeenCalled()
      expect(getLiveFlowInstanceId()).toBe(id)
    })

    it('starts a new instance with a new id after the last one ended', () => {
      flow.start('navbar')
      const first = last().flow_instance_id
      flow.complete()
      flow.start('navbar')
      expect(last().flow_instance_id).not.toBe(first)
    })

    it('stamps the experiment variant on every event', () => {
      log.variant = 'exp:b'
      flow.start('navbar')
      flow.enterStep('give')
      expect(events().every((e) => e.variant === 'exp:b')).toBe(true)
    })

    it('freezes the variant at start: a later change of arm does not reach the instance', () => {
      log.variant = 'exp:control'
      flow.start('navbar')
      log.variant = 'exp:b'
      flow.enterStep('give')
      flow.submit()
      flow.complete()
      expect(events().every((e) => e.variant === 'exp:control')).toBe(true)
    })

    it('freezes who was signed in, and when the flow began, onto every event', () => {
      log.signedIn = false
      flow.start('navbar')
      const began = Date.now()
      // The member signs in part way through the flow and the flow then completes.
      log.signedIn = true
      vi.advanceTimersByTime(60000)
      flow.enterStep('give')
      flow.complete()
      expect(events().length).toBeGreaterThanOrEqual(3)
      expect(events().every((e) => e.start_logged_in === false)).toBe(true)
      expect(events().every((e) => e.started_ms === began)).toBe(true)
    })

    it('records a flow begun while signed in as such', () => {
      log.signedIn = true
      flow.start('navbar')
      expect(last().start_logged_in).toBe(true)
    })

    it('works without crypto.randomUUID', () => {
      const orig = globalThis.crypto.randomUUID
      Object.defineProperty(globalThis.crypto, 'randomUUID', {
        value: undefined,
        configurable: true,
      })
      try {
        flow.start('navbar')
        expect(last().flow_instance_id).toMatch(/^[0-9a-f-]{36}$/)
      } finally {
        Object.defineProperty(globalThis.crypto, 'randomUUID', {
          value: orig,
          configurable: true,
        })
      }
    })
  })

  describe('events without a live instance', () => {
    it('sends nothing', () => {
      flow.enterStep('give')
      flow.completeStep()
      flow.back('give')
      flow.fieldRevisit('title', 'replaced')
      flow.validationFailed('title', 'required')
      flow.choiceSwitched('type', 'Offer', 'Wanted')
      flow.submit()
      flow.submitResult(true, 200, null)
      flow.complete()
      flow.abandon('closed')
      flow.resume(1000)
      flow.helpSought()
      expect(log.action).not.toHaveBeenCalled()
    })
  })

  describe('steps', () => {
    beforeEach(() => {
      flow.start('navbar')
      log.action.mockClear()
    })

    it('step_enter carries the step, step_seq and first_visit', () => {
      flow.enterStep('give')
      let e = last()
      expect(e.name).toBe('step_enter')
      expect(e.step).toBe('give')
      expect(e.step_seq).toBe(1)
      expect(e.first_visit).toBe(true)

      flow.enterStep('give-whereami')
      e = last()
      expect(e.step_seq).toBe(2)
      expect(e.first_visit).toBe(true)
    })

    it('first_visit is false on re-entering a step', () => {
      flow.enterStep('give')
      flow.enterStep('give-whereami')
      flow.back('give')
      flow.enterStep('give')
      const e = last()
      expect(e.first_visit).toBe(false)
      expect(e.step_seq).toBe(3)
    })

    it('ignores entering the step it is already on', () => {
      flow.enterStep('give')
      log.action.mockClear()
      flow.enterStep('give')
      expect(log.action).not.toHaveBeenCalled()
    })

    it('step_complete has the dwell time since step_enter', () => {
      flow.enterStep('give')
      vi.advanceTimersByTime(4200)
      flow.completeStep()
      const e = last()
      expect(e.name).toBe('step_complete')
      expect(e.step).toBe('give')
      expect(e.dwell_ms).toBe(4200)
    })

    it('step_back has the dwell and the step gone back to', () => {
      flow.enterStep('give-whereami')
      vi.advanceTimersByTime(1500)
      flow.back('give')
      const e = last()
      expect(e.name).toBe('step_back')
      expect(e.step).toBe('give-whereami')
      expect(e.to_step).toBe('give')
      expect(e.dwell_ms).toBe(1500)
    })

    it('step_complete or step_back without a step entered sends nothing', () => {
      flow.completeStep()
      flow.back('give')
      expect(log.action).not.toHaveBeenCalled()
    })
  })

  describe('fields, validation and choices', () => {
    beforeEach(() => {
      flow.start('navbar')
      flow.enterStep('give')
      log.action.mockClear()
    })

    it('field_revisit has field and change_kind', () => {
      flow.fieldRevisit('title', 'replaced')
      const e = last()
      expect(e.name).toBe('field_revisit')
      expect(e.field).toBe('title')
      expect(e.change_kind).toBe('replaced')
      expect(e.step).toBe('give')
    })

    it.each(['replaced', 'cleared', 'toggled', 'removed'])(
      'accepts change_kind %s',
      (kind) => {
        flow.fieldRevisit('photos', kind)
        expect(last().change_kind).toBe(kind)
      }
    )

    it('drops a field_revisit with an unknown change_kind', () => {
      flow.fieldRevisit('title', 'typed something')
      expect(log.action).not.toHaveBeenCalled()
    })

    it('validation_failed counts attempts per field', () => {
      flow.validationFailed('postcode', 'required')
      expect(last().attempt).toBe(1)
      expect(last().rule).toBe('required')
      flow.validationFailed('postcode', 'invalid')
      expect(last().attempt).toBe(2)
      flow.validationFailed('title', 'required')
      expect(last().attempt).toBe(1)
    })

    it('choice_switched has field, from and to', () => {
      flow.choiceSwitched('type', 'Offer', 'Wanted')
      const e = last()
      expect(e.name).toBe('choice_switched')
      expect(e.field).toBe('type')
      expect(e.from).toBe('Offer')
      expect(e.to).toBe('Wanted')
    })
  })

  describe('revisits', () => {
    beforeEach(() => {
      flow.start('navbar')
      flow.enterStep('give')
      log.action.mockClear()
    })

    it('isRevisit is false on the first visit and true on returning', () => {
      expect(flow.isRevisit()).toBe(false)
      flow.enterStep('give-whereami')
      flow.back('give')
      flow.enterStep('give')
      expect(flow.isRevisit()).toBe(true)
    })

    it('isRevisit is false when no step is open or no instance is live', () => {
      flow.completeStep()
      expect(flow.isRevisit()).toBe(false)
      flow.complete()
      expect(flow.isRevisit()).toBe(false)
    })

    it('sends one replaced or cleared per field per visit of a step', () => {
      flow.fieldRevisit('title', 'replaced')
      flow.fieldRevisit('title', 'replaced')
      flow.fieldRevisit('title', 'cleared')
      flow.fieldRevisit('description', 'replaced')
      expect(events().map((e) => e.field)).toEqual(['title', 'description'])
    })

    it('allows the same field again on the next visit of the step', () => {
      flow.fieldRevisit('title', 'replaced')
      flow.enterStep('give-whereami')
      flow.back('give')
      flow.enterStep('give')
      flow.fieldRevisit('title', 'replaced')
      expect(events().filter((e) => e.name === 'field_revisit')).toHaveLength(2)
    })

    it('never dedupes removed or toggled', () => {
      flow.fieldRevisit('photos', 'removed')
      flow.fieldRevisit('photos', 'removed')
      flow.fieldRevisit('delivery', 'toggled')
      flow.fieldRevisit('delivery', 'toggled')
      expect(events()).toHaveLength(4)
    })
  })

  describe('no member-typed value in any payload', () => {
    const SAFE_KEYS = new Set([
      'event_type',
      'flow',
      'flow_instance_id',
      'step',
      'step_seq',
      'first_visit',
      'dwell_ms',
      'variant',
      'entry',
      'to_step',
      'field',
      'change_kind',
      'rule',
      'attempt',
      'from',
      'to',
      'ok',
      'status',
      'error_code',
      'reason',
      'last_step',
      'age_ms',
      'start_logged_in',
      'started_ms',
    ])

    it('takes no value arguments: the signatures are names and kinds only', () => {
      expect(flow.fieldRevisit.length).toBe(2)
      expect(flow.validationFailed.length).toBe(2)
      expect(flow.choiceSwitched.length).toBe(3)
    })

    it('turns anything that is not a plain token into "other"', () => {
      flow.start('navbar')
      flow.enterStep('give')
      log.action.mockClear()

      const typed = 'Sofa, 3 seater, call me on 07700 900123'
      flow.validationFailed(typed, typed)
      flow.choiceSwitched(typed, typed, typed)
      flow.fieldRevisit(typed, 'replaced')
      const [v, c, f] = events()
      expect(v.field).toBe('other')
      expect(v.rule).toBe('other')
      expect(c.from).toBe('other')
      expect(c.to).toBe('other')
      expect(f.field).toBe('other')
      expect(JSON.stringify(events())).not.toContain('Sofa')
      expect(JSON.stringify(events())).not.toContain('07700')
    })

    it('only ever sends keys on the contract, for every event', () => {
      flow.start('navbar')
      flow.enterStep('give')
      flow.fieldRevisit('title', 'replaced')
      flow.validationFailed('title', 'required')
      flow.choiceSwitched('type', 'Offer', 'Wanted')
      flow.completeStep()
      flow.enterStep('give-whereami')
      flow.back('give')
      flow.submit()
      flow.submitResult(false, 422, 'validation')
      flow.resume(5000)
      flow.helpSought()
      flow.complete()
      for (const e of events()) {
        for (const key of Object.keys(e)) {
          if (key === 'name') continue
          expect(SAFE_KEYS.has(key), `${e.name} sent ${key}`).toBe(true)
        }
        expect(e.event_type).toBe('flow')
      }
    })
  })

  describe('submit', () => {
    beforeEach(() => {
      flow.start('navbar')
      flow.enterStep('give-whereami')
      log.action.mockClear()
    })

    it('flow_submit counts attempts', () => {
      flow.submit()
      expect(last().name).toBe('flow_submit')
      expect(last().attempt).toBe(1)
      flow.submit()
      expect(last().attempt).toBe(2)
    })

    it('flow_submit_result has ok, status and error_code', () => {
      flow.submitResult(false, 422, 'validation')
      const e = last()
      expect(e.name).toBe('flow_submit_result')
      expect(e.ok).toBe(false)
      expect(e.status).toBe(422)
      expect(e.error_code).toBe('validation')
    })

    it('records that a submit succeeded', () => {
      expect(flow.submitSucceeded()).toBe(false)
      flow.submitResult(true, 200, null)
      expect(flow.submitSucceeded()).toBe(true)
    })
  })

  describe('terminal events', () => {
    beforeEach(() => {
      flow.start('navbar')
      flow.enterStep('give')
      log.action.mockClear()
    })

    it('flow_complete has dwell since flow_start and ends the instance', () => {
      vi.advanceTimersByTime(60000)
      flow.complete()
      const e = last()
      expect(e.name).toBe('flow_complete')
      expect(e.dwell_ms).toBe(60000)
      expect(getLiveFlowInstanceId()).toBeNull()
    })

    it('completing the flow completes the step it finished on, before flow_complete', () => {
      // The step a flow submits from is never left by navigation, so nothing else closes it.
      vi.advanceTimersByTime(2500)
      flow.complete()
      const names = events().map((e) => e.name)
      expect(names).toEqual(['step_complete', 'flow_complete'])
      expect(events()[0].step).toBe('give')
      expect(events()[0].dwell_ms).toBe(2500)
    })

    it('does not complete the last step twice when it was already completed', () => {
      flow.completeStep()
      flow.complete()
      expect(events().filter((e) => e.name === 'step_complete')).toHaveLength(1)
    })

    it('abandoning does not complete the open step', () => {
      flow.abandon('navigated')
      expect(events().map((e) => e.name)).toEqual(['flow_abandon'])
    })

    it('flow_abandon has reason, last_step and dwell since step_enter', () => {
      vi.advanceTimersByTime(3000)
      flow.abandon('navigated')
      const e = last()
      expect(e.name).toBe('flow_abandon')
      expect(e.reason).toBe('navigated')
      expect(e.last_step).toBe('give')
      expect(e.dwell_ms).toBe(3000)
      expect(getLiveFlowInstanceId()).toBeNull()
    })

    it('flow_abandon with no step entered measures from the start', () => {
      _resetFlowTelemetryForTests()
      flow.start('navbar')
      vi.advanceTimersByTime(800)
      flow.abandon('closed')
      expect(last().dwell_ms).toBe(800)
      expect(last().last_step).toBeNull()
    })

    it('ignores an unknown abandon reason', () => {
      flow.abandon('because')
      expect(log.action).not.toHaveBeenCalled()
      expect(getLiveFlowInstanceId()).not.toBeNull()
    })

    it('sends at most one terminal event per instance', () => {
      flow.complete()
      flow.abandon('navigated')
      flow.complete()
      flow.abandon('hidden')
      const terminal = events().filter((e) =>
        ['flow_complete', 'flow_abandon'].includes(e.name)
      )
      expect(terminal).toHaveLength(1)
    })

    it('sends nothing else after the instance ended', () => {
      flow.complete()
      log.action.mockClear()
      flow.enterStep('give')
      flow.fieldRevisit('title', 'replaced')
      flow.submit()
      expect(log.action).not.toHaveBeenCalled()
    })
  })

  describe('resume and help', () => {
    beforeEach(() => {
      flow.start('draft_restored')
      log.action.mockClear()
    })

    it('flow_resume carries the age of the draft', () => {
      flow.resume(86400000)
      expect(last().name).toBe('flow_resume')
      expect(last().age_ms).toBe(86400000)
    })

    it('help_sought is on the live instance, with flow and step in the envelope', () => {
      flow.enterStep('give')
      flow.helpSought()
      const e = last()
      expect(e.name).toBe('help_sought')
      expect(e.flow).toBe('give')
      expect(e.step).toBe('give')
    })

    it('the global hook sends help_sought on every live instance', () => {
      const ask = useFlowTelemetry(FlowName.ASK)
      ask.start('navbar')
      log.action.mockClear()
      noteHelpSought()
      const helps = events().filter((e) => e.name === 'help_sought')
      expect(helps.map((e) => e.flow).sort()).toEqual(['ask', 'give'])
    })

    it('the global hook does nothing when no flow is live', () => {
      flow.complete()
      log.action.mockClear()
      noteHelpSought()
      expect(log.action).not.toHaveBeenCalled()
    })
  })

  describe('idle timeout', () => {
    it('abandons with reason idle after 30 minutes with no flow event', () => {
      flow.start('navbar')
      flow.enterStep('give')
      log.action.mockClear()
      vi.advanceTimersByTime(30 * 60 * 1000 - 1)
      expect(log.action).not.toHaveBeenCalled()
      vi.advanceTimersByTime(2)
      expect(last().name).toBe('flow_abandon')
      expect(last().reason).toBe('idle')
    })

    it('restarts the 30 minutes on each flow event', () => {
      flow.start('navbar')
      vi.advanceTimersByTime(20 * 60 * 1000)
      flow.enterStep('give')
      log.action.mockClear()
      vi.advanceTimersByTime(20 * 60 * 1000)
      expect(log.action).not.toHaveBeenCalled()
      vi.advanceTimersByTime(11 * 60 * 1000)
      expect(last().reason).toBe('idle')
    })

    it('brings the same instance back when the member carries on after an idle abandon', () => {
      flow.start('navbar')
      flow.enterStep('give-details')
      const id = flow.instanceId()
      vi.advanceTimersByTime(30 * 60 * 1000 + 5 * 60 * 1000)
      expect(last().name).toBe('flow_abandon')
      expect(flow.isLive()).toBe(true)
      log.action.mockClear()

      flow.completeStep()
      flow.complete()

      expect(events().map((e) => e.name)).toEqual([
        'flow_resume',
        'step_complete',
        'flow_complete',
      ])
      expect(events()[0].reason).toBe('idle')
      expect(events()[0].step).toBe('give-details')
      expect(events()[0].age_ms).toBe(5 * 60 * 1000)
      expect(events().every((e) => e.flow_instance_id === id)).toBe(true)
      expect(flow.isLive()).toBe(false)
    })

    it('starts a new instance, not a revival, when start is called after an idle abandon', () => {
      flow.start('navbar')
      const id = flow.instanceId()
      vi.advanceTimersByTime(31 * 60 * 1000)
      log.action.mockClear()
      flow.start('navbar')
      expect(events().map((e) => e.name)).toEqual(['flow_start'])
      expect(flow.instanceId()).not.toBe(id)
    })

    it('does not fire after the instance ended', () => {
      flow.start('navbar')
      flow.complete()
      log.action.mockClear()
      vi.advanceTimersByTime(60 * 60 * 1000)
      expect(log.action).not.toHaveBeenCalled()
    })
  })

  describe('page hidden', () => {
    function hideTab() {
      Object.defineProperty(document, 'visibilityState', {
        value: 'hidden',
        configurable: true,
      })
      document.dispatchEvent(new Event('visibilitychange'))
    }

    function pagehide(persisted) {
      const e = new Event('pagehide')
      e.persisted = persisted
      window.dispatchEvent(e)
    }

    afterEach(() => {
      Object.defineProperty(document, 'visibilityState', {
        value: 'visible',
        configurable: true,
      })
    })

    it('only flushes when the tab is hidden: a photo picker or app switch is not an abandon', () => {
      flow.start('navbar')
      flow.enterStep('give')
      log.action.mockClear()
      hideTab()
      expect(events().filter((e) => e.name === 'flow_abandon')).toHaveLength(0)
      expect(log.flush).toHaveBeenCalledWith(true)
      expect(getLiveFlowInstanceId()).not.toBeNull()
    })

    it('keeps the same instance when the tab comes back', () => {
      flow.start('navbar')
      const id = last().flow_instance_id
      hideTab()
      Object.defineProperty(document, 'visibilityState', {
        value: 'visible',
        configurable: true,
      })
      document.dispatchEvent(new Event('visibilitychange'))
      flow.enterStep('give')
      expect(last().flow_instance_id).toBe(id)
    })

    it('abandons with reason hidden on a pagehide that is not going into the back/forward cache', () => {
      flow.start('navbar')
      flow.enterStep('give')
      log.action.mockClear()
      pagehide(false)
      expect(last().name).toBe('flow_abandon')
      expect(last().reason).toBe('hidden')
      expect(log.flush).toHaveBeenCalledWith(true)
    })

    it('does not abandon on a pagehide that is persisted', () => {
      flow.start('navbar')
      log.action.mockClear()
      pagehide(true)
      expect(events().filter((e) => e.name === 'flow_abandon')).toHaveLength(0)
    })

    it('does nothing when no flow is live', () => {
      pagehide(false)
      expect(log.action).not.toHaveBeenCalled()
    })
  })

  describe('independent flows', () => {
    it('keeps a separate instance per flow', () => {
      const ask = useFlowTelemetry(FlowName.ASK)
      flow.start('navbar')
      const giveId = last().flow_instance_id
      ask.start('navbar')
      const askId = last().flow_instance_id
      expect(last().flow).toBe('ask')
      expect(askId).not.toBe(giveId)
      flow.complete()
      expect(getLiveFlowInstanceId()).toBe(askId)
    })
  })

  describe('flowArm', () => {
    it('is the arm now when no instance is live', () => {
      log.now = 'b'
      expect(flowArm(FlowName.GIVE, 'exp')).toBe('b')
      expect(log.exposures).toEqual([['now', 'exp']])
    })

    it('is the arm frozen at flow_start while an instance is live', () => {
      log.variant = 'exp:control,other:b'
      flow.start('navbar')
      // The member signs in, the config refreshes, the arm now is b; the flow keeps control.
      log.now = 'b'
      log.variant = 'exp:b,other:b'
      expect(flowArm(FlowName.GIVE, 'exp')).toBe('control')
      expect(flowArm(FlowName.GIVE, 'other')).toBe('b')
      expect(flowArm(FlowName.GIVE, 'absent')).toBeNull()
      expect(log.exposures).toContainEqual(['frozen', 'exp', 'control'])
    })

    it('is null for a flow started before the member was in the experiment', () => {
      log.variant = ''
      flow.start('navbar')
      log.now = 'b'
      expect(flowArm(FlowName.GIVE, 'exp')).toBeNull()
    })

    it('does not match a name that is only the end of another', () => {
      log.variant = 'login_cta:b'
      flow.start('navbar')
      expect(flowArm(FlowName.GIVE, 'cta')).toBeNull()
    })

    it('keeps reading the frozen arm after an idle end, and drops it after a real end', () => {
      log.variant = 'exp:b'
      flow.start('navbar')
      vi.advanceTimersByTime(31 * 60 * 1000)
      log.variant = 'exp:control'
      log.now = 'control'
      expect(flowArm(FlowName.GIVE, 'exp')).toBe('b')
      flow.complete()
      expect(flowArm(FlowName.GIVE, 'exp')).toBe('control')
    })

    it("reads another flow's instance only for that flow", () => {
      log.variant = 'exp:b'
      flow.start('navbar')
      log.now = 'control'
      expect(flowArm(FlowName.ASK, 'exp')).toBe('control')
    })
  })
})
