/**
 * Flow telemetry: where members get stuck, go back, change their minds or give up in a
 * multi-step flow. The contract is docs/developers/reference/ux-telemetry.md section 2.
 *
 * This is the ONLY place that emits event_type 'flow'. Events carry names, kinds and counts, never
 * a value a member typed: the field helpers take no value arguments, and any name that is not a
 * plain token is sent as 'other'.
 *
 * State lives at module level, one instance per flow, so a flow that spans several pages (or a
 * store) shares one instance id.
 */
import { action, flush, isSignedIn } from '~/composables/useClientLog'
import {
  experimentVariant,
  useExperiment,
  noteExposure,
  armInVariant,
} from '~/composables/useExperiment'

export const FlowName = {
  GIVE: 'give',
  ASK: 'ask',
  REPLY: 'reply',
  LOGIN: 'login',
  CHAT_PROMISE: 'chat_promise',
  CHAT_OUTCOME: 'chat_outcome',
  SETTINGS: 'settings',
}

export const FlowEvent = {
  FLOW_START: 'flow_start',
  STEP_ENTER: 'step_enter',
  STEP_COMPLETE: 'step_complete',
  STEP_BACK: 'step_back',
  FIELD_REVISIT: 'field_revisit',
  VALIDATION_FAILED: 'validation_failed',
  CHOICE_SWITCHED: 'choice_switched',
  FLOW_SUBMIT: 'flow_submit',
  FLOW_SUBMIT_RESULT: 'flow_submit_result',
  FLOW_COMPLETE: 'flow_complete',
  FLOW_ABANDON: 'flow_abandon',
  FLOW_RESUME: 'flow_resume',
  HELP_SOUGHT: 'help_sought',
}

export const ChangeKind = {
  REPLACED: 'replaced',
  CLEARED: 'cleared',
  TOGGLED: 'toggled',
  REMOVED: 'removed',
}

export const AbandonReason = {
  NAVIGATED: 'navigated',
  CLOSED: 'closed',
  HIDDEN: 'hidden',
  IDLE: 'idle',
}

export const IDLE_TIMEOUT_MS = 30 * 60 * 1000

const CHANGE_KINDS = new Set(Object.values(ChangeKind))
const ABANDON_REASONS = new Set(Object.values(AbandonReason))
const TOKEN = /^[A-Za-z0-9_:.-]{1,40}$/

// Flow name -> live instance. Insertion order is start order.
const instances = new Map()

// Flow name -> instance ended by the idle timer. The member may still be on the page and carry on;
// if they do, the same instance comes back with a flow_resume (reason idle), and the rollup takes
// that abandon back off, so a slow but successful post is not counted as given up.
const dormant = new Map()

function uuid() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID()
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}

// A name, rule or enumerated choice. Anything else could be member text, so it is not sent.
function token(value) {
  const s = value === null || value === undefined ? '' : String(value)
  return TOKEN.test(s) ? s : 'other'
}

function live(flow) {
  return instances.get(flow) || null
}

// The live instance, reviving an idle-ended one if there is one.
function liveOrRevive(flow) {
  const inst = live(flow)
  if (inst) return inst
  const idle = dormant.get(flow)
  if (!idle) return null
  dormant.delete(flow)
  idle.ended = false
  instances.set(flow, idle)
  emit(idle, FlowEvent.FLOW_RESUME, {
    reason: AbandonReason.IDLE,
    age_ms: Date.now() - idle.endedAt,
  })
  return idle
}

// Telemetry must never throw into a flow, whatever state the auth store is in.
function signedInNow() {
  try {
    return !!isSignedIn()
  } catch {
    return false
  }
}

function emit(inst, event, extra = {}) {
  // Only the browser has members; module state on the server would be shared between requests.
  if (typeof window === 'undefined') return

  armIdle(inst)

  action(event, {
    event_type: 'flow',
    flow: inst.flow,
    flow_instance_id: inst.id,
    step: inst.step,
    step_seq: inst.stepSeq,
    variant: inst.variant,
    // Frozen at flow_start and sent on every event, so the batch can read a flow by who was
    // signed in when it BEGAN (signing in is often part of the flow) and count only flows that
    // began early enough to have finished, whenever their events arrive.
    start_logged_in: inst.startLoggedIn,
    started_ms: inst.startedAt,
    ...extra,
  })
}

function armIdle(inst) {
  clearTimeout(inst.idleTimer)
  inst.idleTimer = setTimeout(
    () => end(inst, AbandonReason.IDLE),
    IDLE_TIMEOUT_MS
  )
}

function stepDwell(inst) {
  return Date.now() - (inst.stepEnteredAt ?? inst.startedAt)
}

// Send the terminal event and forget the instance. At most one terminal event is ever sent.
function end(inst, reason) {
  if (inst.ended) return
  inst.ended = true
  inst.endedAt = Date.now()
  clearTimeout(inst.idleTimer)

  if (reason === null) {
    // The step a flow finishes on (the give and ask step that submits, the reply that sends) is
    // never left by navigation, so it would otherwise stay open forever and read as 0 completed.
    if (inst.stepOpen) {
      inst.stepOpen = false
      emit(inst, FlowEvent.STEP_COMPLETE, { dwell_ms: stepDwell(inst) })
    }
    emit(inst, FlowEvent.FLOW_COMPLETE, {
      dwell_ms: Date.now() - inst.startedAt,
    })
  } else {
    emit(inst, FlowEvent.FLOW_ABANDON, {
      reason,
      last_step: inst.step,
      dwell_ms: stepDwell(inst),
    })
  }

  clearTimeout(inst.idleTimer)
  if (instances.get(inst.flow) === inst) {
    instances.delete(inst.flow)
    if (reason === AbandonReason.IDLE) dormant.set(inst.flow, inst)
  }
}

/**
 * The id of the most recently started live instance, or null. api/BaseAPI.js sends it as
 * X-Freegle-Flow-Instance so the API request can be joined to the flow.
 */
export function getLiveFlowInstanceId() {
  const all = [...instances.values()]
  return all.length ? all.at(-1).id : null
}

/**
 * The member's arm of an experiment for variant UI inside a flow. While an instance of the flow is
 * live (or only idle-ended and still resumable) this is the arm frozen into it at flow_start, the
 * same one every event of the instance is stamped with, so a step shown differently to the member
 * is always counted in the arm that was shown. Signing in, a config refresh or the hourly bandit
 * cannot change it halfway. With no instance it is the member's arm now (useExperiment).
 *
 * Reading it counts as an exposure, once per page view.
 */
export function flowArm(flowName, name) {
  const inst = live(flowName) || dormant.get(flowName) || null
  if (!inst) return useExperiment(name)
  const arm = armInVariant(inst.variant, name)
  noteExposure(name, arm)
  return arm
}

/**
 * Global help hook: the member went to help (help page, a help option, contacting support).
 * Recorded on every live flow.
 */
export function noteHelpSought() {
  for (const inst of [...instances.values()]) {
    emit(inst, FlowEvent.HELP_SOUGHT)
  }
}

export function _resetFlowTelemetryForTests() {
  for (const inst of instances.values()) {
    clearTimeout(inst.idleTimer)
  }
  instances.clear()
  dormant.clear()
}

if (typeof window !== 'undefined') {
  // A hidden tab is not an abandon: the camera or photo picker, a share sheet or switching apps
  // all hide it and the member comes back. Only flush, in case the tab is killed. A pagehide that
  // is not going into the back/forward cache is the page really going away.
  window.addEventListener('pagehide', (e) => {
    if (e.persisted) return
    for (const inst of [...instances.values()]) {
      end(inst, AbandonReason.HIDDEN)
    }
    dormant.clear()
    flush(true)
  })
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush(true)
  })
}

/**
 * Telemetry for one flow.
 *
 *   const flow = useFlowTelemetry(FlowName.GIVE)
 *   flow.start('navbar')
 *   flow.enterStep('give-whereami')
 *
 * Every method does nothing unless an instance is live (start() was called and no terminal event
 * has been sent), so call sites need no guards. An instance ended only by the idle timer counts as
 * live: the next call brings it back.
 */
export function useFlowTelemetry(flowName) {
  const withLive = (fn) => {
    const inst = liveOrRevive(flowName)
    if (inst) fn(inst)
  }
  const current = () => live(flowName) || dormant.get(flowName) || null

  return {
    // Begin an instance. A second start while one is live is ignored.
    start(entry) {
      if (typeof window === 'undefined' || live(flowName)) return
      // Starting again after an idle abandon is a new attempt; the old one stays abandoned.
      dormant.delete(flowName)
      const now = Date.now()
      const inst = {
        id: uuid(),
        // Frozen now: logging in at the end of give/ask changes who the experiment sees.
        variant: experimentVariant(),
        startLoggedIn: signedInNow(),
        flow: flowName,
        startedAt: now,
        step: null,
        stepSeq: 0,
        stepEnteredAt: null,
        stepOpen: false,
        stepFirstVisit: true,
        revisitSent: new Set(),
        visited: new Set(),
        validation: {},
        submitAttempts: 0,
        submitOk: false,
        ended: false,
        idleTimer: null,
      }
      instances.set(flowName, inst)
      emit(inst, FlowEvent.FLOW_START, { entry: token(entry) })
    },

    enterStep(step) {
      withLive((inst) => {
        const name = token(step)
        if (inst.step === name && inst.stepOpen) return
        inst.stepSeq++
        inst.step = name
        inst.stepEnteredAt = Date.now()
        inst.stepOpen = true
        const first = !inst.visited.has(name)
        inst.visited.add(name)
        inst.stepFirstVisit = first
        inst.revisitSent = new Set()
        emit(inst, FlowEvent.STEP_ENTER, { first_visit: first })
      })
    },

    completeStep() {
      withLive((inst) => {
        if (!inst.stepOpen) return
        inst.stepOpen = false
        emit(inst, FlowEvent.STEP_COMPLETE, { dwell_ms: stepDwell(inst) })
      })
    },

    back(toStep) {
      withLive((inst) => {
        if (!inst.stepOpen) return
        inst.stepOpen = false
        emit(inst, FlowEvent.STEP_BACK, {
          dwell_ms: stepDwell(inst),
          to_step: token(toStep),
        })
      })
    },

    // A member went back to a field they had already filled in. kind is one of ChangeKind.
    fieldRevisit(field, kind) {
      withLive((inst) => {
        if (!CHANGE_KINDS.has(kind)) return

        // Typing into a field changes it on every keystroke, so a field is reported once per visit
        // of a step. Removals and toggles are discrete acts and each counts.
        const name = token(field)
        if (kind === ChangeKind.REPLACED || kind === ChangeKind.CLEARED) {
          if (inst.revisitSent.has(name)) return
          inst.revisitSent.add(name)
        }

        emit(inst, FlowEvent.FIELD_REVISIT, {
          field: name,
          change_kind: kind,
        })
      })
    },

    validationFailed(field, rule) {
      withLive((inst) => {
        const name = token(field)
        inst.validation[name] = (inst.validation[name] || 0) + 1
        emit(inst, FlowEvent.VALIDATION_FAILED, {
          field: name,
          rule: token(rule),
          attempt: inst.validation[name],
        })
      })
    },

    // Only for enumerated choices (type, group, delivery), never free text.
    choiceSwitched(field, from, to) {
      withLive((inst) => {
        emit(inst, FlowEvent.CHOICE_SWITCHED, {
          field: token(field),
          from: token(from),
          to: token(to),
        })
      })
    },

    submit() {
      withLive((inst) => {
        inst.submitAttempts++
        emit(inst, FlowEvent.FLOW_SUBMIT, { attempt: inst.submitAttempts })
      })
    },

    submitResult(ok, status, errorCode) {
      withLive((inst) => {
        inst.submitOk = !!ok
        emit(inst, FlowEvent.FLOW_SUBMIT_RESULT, {
          ok: !!ok,
          status: Number.isFinite(Number(status)) ? Number(status) : null,
          error_code: errorCode ? token(errorCode) : null,
        })
      })
    },

    complete() {
      withLive((inst) => end(inst, null))
    },

    abandon(reason) {
      withLive((inst) => {
        if (ABANDON_REASONS.has(reason)) end(inst, reason)
      })
    },

    resume(ageMs) {
      withLive((inst) => {
        emit(inst, FlowEvent.FLOW_RESUME, {
          age_ms: Math.max(0, Number(ageMs) || 0),
        })
      })
    },

    helpSought() {
      withLive((inst) => emit(inst, FlowEvent.HELP_SOUGHT))
    },

    // True when the open step has been entered before, i.e. the member came back to it.
    isRevisit() {
      const inst = current()
      return !!inst && inst.stepOpen && !inst.stepFirstVisit
    },

    isLive() {
      return !!current()
    },

    instanceId() {
      return current()?.id ?? null
    },

    // True once a submit_result with ok=true was sent on the live instance.
    submitSucceeded() {
      return !!current()?.submitOk
    },
  }
}
