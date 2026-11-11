/**
 * Route -> step map for the routed flows (give, ask) and the router hook that turns navigation
 * into flow telemetry, so the pages need no calls of their own for steps.
 *
 * A step is a route name. The rank orders the steps within a flow so a move to a lower rank is a
 * step_back, a higher rank a step_complete, and an equal rank (a redirect between the desktop and
 * mobile version of the same step) is neither.
 *
 * The sequences are:
 *   give desktop: give, give-whereami, give-options, give-whoami
 *   give mobile:  give-mobile-photos, give-mobile-details, give-mobile-options, give-mobile-whereami
 *   ask desktop:  ask, ask-whereami, ask-whoami
 *   ask mobile:   ask-mobile-photos, ask-mobile-details, ask-mobile-whereami
 */
import { FlowName, useFlowTelemetry } from '~/composables/useFlowTelemetry'

export const FLOW_ROUTES = {
  [FlowName.GIVE]: {
    give: 1,
    'give-mobile': 1,
    'give-mobile-photos': 1,
    'give-clearance': 1,
    'give-whereami': 2,
    'give-mobile-details': 2,
    'give-options': 3,
    'give-mobile-options': 3,
    'give-whoami': 4,
    'give-mobile-whereami': 4,
  },
  [FlowName.ASK]: {
    ask: 1,
    'ask-mobile': 1,
    'ask-mobile-photos': 1,
    'ask-whereami': 2,
    'ask-mobile-details': 2,
    'ask-whoami': 3,
    'ask-mobile-whereami': 3,
  },
}

// The compose store's type for each flow.
export const FLOW_MESSAGE_TYPE = {
  [FlowName.GIVE]: 'Offer',
  [FlowName.ASK]: 'Wanted',
}

// The component last clicked before the flow started -> how the member came in.
const CLICK_ENTRY = {
  NavbarDesktop: 'navbar',
  NavbarMobile: 'navbar',
  NavbarMobilePost: 'post_button',
  GiveAsk: 'give_ask_buttons',
  MyPostsPostsList: 'myposts',
  NotificationAboutMe: 'notification',
}

const CLICK_WINDOW_MS = 3000

// A draft saved less than this long ago was made in this visit, not restored.
const RESTORED_AFTER_MS = 5000

/**
 * How long ago a half-composed message of this type was saved, if there is one. The compose store
 * is persisted, so a message with content that was saved before this visit is a restored draft.
 * setup() creates a blank message on every visit, hence the content test.
 */
export function draftAgeFromStore(composeStore, type) {
  const draft = (composeStore?.messages || []).find(
    (m) =>
      m &&
      m.type === type &&
      !m.submitted &&
      (m.item || m.description || m.attachments?.length)
  )

  if (!draft?.savedAt) return null

  const age = Date.now() - draft.savedAt
  return age >= RESTORED_AFTER_MS ? age : null
}

function flowOf(routeName) {
  for (const [flow, routes] of Object.entries(FLOW_ROUTES)) {
    if (routeName in routes) {
      return { flow, rank: routes[routeName] }
    }
  }
  return null
}

/**
 * @param {Object} deps
 * @param {(type: string) => number|null} deps.draftAge ms since a restored draft of this message
 *   type was saved, or null if there is none
 * @param {(maxAgeMs: number) => string|null} deps.recentClick component clicked just now
 */
export function createFlowRouteTracker({ draftAge, recentClick }) {
  // Where we are in each flow, so the next route can be ranked against it.
  const current = {}

  function entryFor(from) {
    const clicked = recentClick(CLICK_WINDOW_MS)
    if (clicked && CLICK_ENTRY[clicked]) return CLICK_ENTRY[clicked]
    if (!from?.name) return 'deep_link'
    if (from.name === 'index') return 'home'
    if (from.name === 'post') return 'post_page'
    return 'from_' + from.name
  }

  function leave(flowName, destination) {
    const flow = useFlowTelemetry(flowName)
    if (!flow.isLive()) return

    if (flow.submitSucceeded()) {
      flow.complete()
    } else {
      const other = destination && destination.flow !== flowName
      if (other) {
        flow.choiceSwitched(
          'type',
          FLOW_MESSAGE_TYPE[flowName],
          FLOW_MESSAGE_TYPE[destination.flow]
        )
      }
      flow.abandon('navigated')
    }
    delete current[flowName]
  }

  function onRoute(to, from) {
    const target = flowOf(to?.name)

    for (const flowName of Object.keys(FLOW_ROUTES)) {
      if (target?.flow !== flowName) {
        leave(flowName, target)
      }
    }

    if (!target) return

    const flow = useFlowTelemetry(target.flow)

    if (!flow.isLive()) {
      delete current[target.flow]
      const age = draftAge(FLOW_MESSAGE_TYPE[target.flow])
      flow.start(age === null ? entryFor(from) : 'draft_restored')
      if (age !== null) flow.resume(age)
    }

    const prev = current[target.flow]
    if (prev) {
      if (prev.name === to.name) return
      if (target.rank > prev.rank) {
        flow.completeStep()
      } else if (target.rank < prev.rank) {
        flow.back(to.name)
      }
    }

    current[target.flow] = { name: to.name, rank: target.rank }
    flow.enterStep(to.name)
  }

  return { onRoute }
}
