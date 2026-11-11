/**
 * A fake ~/composables/useFlowTelemetry for component tests: every flow method records its call.
 *
 *   vi.mock('~/composables/useFlowTelemetry', async () =>
 *     (await import('../helpers/flowTelemetryMock')).flowTelemetryModule)
 *   import { flowCalls, resetFlowCalls } from '../helpers/flowTelemetryMock'
 *
 * flowCalls is a list of [method, ...args] in order, across all flows.
 */
export const flowCalls = []

const METHODS = [
  'start',
  'enterStep',
  'completeStep',
  'back',
  'fieldRevisit',
  'validationFailed',
  'choiceSwitched',
  'submit',
  'submitResult',
  'complete',
  'abandon',
  'resume',
  'helpSought',
]

const state = { live: false, succeeded: false }

const flow = {
  isLive: () => state.live,
  isRevisit: () => false,
  submitSucceeded: () => state.succeeded,
}

for (const name of METHODS) {
  flow[name] = (...args) => {
    flowCalls.push([name, ...args])
    if (name === 'start') state.live = true
    if (name === 'complete' || name === 'abandon') state.live = false
    if (name === 'submitResult') state.succeeded = !!args[0]
  }
}

export function resetFlowCalls() {
  flowCalls.length = 0
  state.live = false
  state.succeeded = false
}

export const flowTelemetryModule = {
  FlowName: {
    GIVE: 'give',
    ASK: 'ask',
    REPLY: 'reply',
    LOGIN: 'login',
    CHAT_PROMISE: 'chat_promise',
    CHAT_OUTCOME: 'chat_outcome',
    SETTINGS: 'settings',
  },
  ChangeKind: {
    REPLACED: 'replaced',
    CLEARED: 'cleared',
    TOGGLED: 'toggled',
    REMOVED: 'removed',
  },
  useFlowTelemetry: () => flow,
  noteHelpSought: () => flowCalls.push(['noteHelpSought']),
}
