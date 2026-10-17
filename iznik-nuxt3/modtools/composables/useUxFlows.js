// The seven funnels, in the order members meet them, with the names staff read. The keys are the
// flow names in composables/useFlowTelemetry.js (FlowName), which is where a flow is named first.

export const FLOW_ORDER = [
  'give',
  'ask',
  'reply',
  'chat_promise',
  'chat_outcome',
  'login',
  'settings',
]

// The long name, for a heading or a sentence.
export const FLOW_TITLES = {
  give: 'Offering something',
  ask: 'Asking for something',
  reply: 'Replying to a post',
  chat_promise: 'Promising an item',
  chat_outcome: 'Marking taken or received',
  login: 'Signing in or joining',
  settings: 'Settings',
}

// The short name, for a button.
export const FLOW_SHORT = {
  give: 'Offering',
  ask: 'Asking',
  reply: 'Replying',
  chat_promise: 'Promising',
  chat_outcome: 'Marking taken',
  login: 'Signing in',
  settings: 'Settings',
}

// A flow the API returns that is not in the lists above prints as its key.
export const flowTitle = (flow) => FLOW_TITLES[flow] || flow
export const flowShort = (flow) => FLOW_SHORT[flow] || flow
