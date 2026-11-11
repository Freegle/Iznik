import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

import {
  FLOW_ROUTES,
  createFlowRouteTracker,
  draftAgeFromStore,
} from '~/composables/useFlowRoutes'
import {
  FlowName,
  useFlowTelemetry,
  _resetFlowTelemetryForTests,
} from '~/composables/useFlowTelemetry'

const log = vi.hoisted(() => ({ action: vi.fn(), flush: vi.fn() }))
vi.mock('~/composables/useClientLog', () => ({
  action: log.action,
  flush: log.flush,
}))
vi.mock('~/composables/useExperiment', () => ({ experimentVariant: () => '' }))

const r = (name) => ({ name })

function events() {
  return log.action.mock.calls.map(([name, ctx]) => ({ name, ...ctx }))
}
const names = () => events().map((e) => e.name)

describe('useFlowRoutes', () => {
  let tracker
  let draftAge
  let recentClick

  beforeEach(() => {
    vi.useFakeTimers()
    _resetFlowTelemetryForTests()
    log.action.mockClear()
    draftAge = vi.fn(() => null)
    recentClick = vi.fn(() => null)
    tracker = createFlowRouteTracker({ draftAge, recentClick })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  describe('the route map', () => {
    it('covers the desktop and mobile routes of give and ask', () => {
      expect(Object.keys(FLOW_ROUTES.give).sort()).toEqual(
        [
          'give',
          'give-clearance',
          'give-mobile',
          'give-mobile-details',
          'give-mobile-options',
          'give-mobile-photos',
          'give-mobile-whereami',
          'give-options',
          'give-whereami',
          'give-whoami',
        ].sort()
      )
      expect(Object.keys(FLOW_ROUTES.ask).sort()).toEqual(
        [
          'ask',
          'ask-mobile',
          'ask-mobile-details',
          'ask-mobile-photos',
          'ask-mobile-whereami',
          'ask-whereami',
          'ask-whoami',
        ].sort()
      )
    })
  })

  describe('entering a flow', () => {
    it('ignores routes that are not in a flow', () => {
      tracker.onRoute(r('browse'), r('index'))
      expect(log.action).not.toHaveBeenCalled()
    })

    it('starts the flow and enters the step', () => {
      tracker.onRoute(r('give-mobile-photos'), r('index'))
      expect(names()).toEqual(['flow_start', 'step_enter'])
      expect(events()[0].flow).toBe('give')
      expect(events()[1].step).toBe('give-mobile-photos')
    })

    it('starts an ask flow for ask routes', () => {
      tracker.onRoute(r('ask-whereami'), r('index'))
      expect(events()[0].flow).toBe('ask')
    })

    it.each([
      [undefined, null, 'deep_link'],
      ['index', null, 'home'],
      ['post', null, 'post_page'],
      ['browse', 'NavbarDesktop', 'navbar'],
      ['browse', 'NavbarMobile', 'navbar'],
      ['browse', 'NavbarMobilePost', 'post_button'],
      ['myposts', 'MyPostsPostsList', 'myposts'],
      ['browse', 'GiveAsk', 'give_ask_buttons'],
      ['chitchat', null, 'from_chitchat'],
    ])('entry from %s with click %s is %s', (from, click, entry) => {
      recentClick.mockReturnValue(click)
      tracker.onRoute(r('give'), r(from))
      expect(events()[0].entry).toBe(entry)
    })

    it('a restored draft starts with entry draft_restored and a resume', () => {
      draftAge.mockReturnValue(86400000)
      tracker.onRoute(r('give'), r('index'))
      expect(names()).toEqual(['flow_start', 'flow_resume', 'step_enter'])
      expect(events()[0].entry).toBe('draft_restored')
      expect(events()[1].age_ms).toBe(86400000)
    })

    it('asks for the draft of the right type', () => {
      tracker.onRoute(r('ask-whoami'), r('index'))
      expect(draftAge).toHaveBeenCalledWith('Wanted')
    })
  })

  describe('moving between steps', () => {
    beforeEach(() => {
      tracker.onRoute(r('give-mobile-photos'), r('index'))
      log.action.mockClear()
    })

    it('completes the step left and enters the next one going forward', () => {
      tracker.onRoute(r('give-mobile-details'), r('give-mobile-photos'))
      expect(names()).toEqual(['step_complete', 'step_enter'])
      expect(events()[0].step).toBe('give-mobile-photos')
      expect(events()[1].step).toBe('give-mobile-details')
    })

    it('sends step_back going to an earlier step, then enters it as a revisit', () => {
      tracker.onRoute(r('give-mobile-details'), r('give-mobile-photos'))
      log.action.mockClear()
      tracker.onRoute(r('give-mobile-photos'), r('give-mobile-details'))
      expect(names()).toEqual(['step_back', 'step_enter'])
      expect(events()[0].to_step).toBe('give-mobile-photos')
      expect(events()[1].first_visit).toBe(false)
    })

    it('treats a redirect to an equivalent step as the same position', () => {
      _resetFlowTelemetryForTests()
      log.action.mockClear()
      tracker.onRoute(r('give'), r('index'))
      log.action.mockClear()
      tracker.onRoute(r('give-mobile-photos'), r('give'))
      expect(names()).toEqual(['step_enter'])
    })

    it('does nothing for the same step twice', () => {
      tracker.onRoute(r('give-mobile-photos'), r('give-mobile-photos'))
      expect(log.action).not.toHaveBeenCalled()
    })
  })

  describe('leaving a flow', () => {
    beforeEach(() => {
      tracker.onRoute(r('give-whereami'), r('index'))
      log.action.mockClear()
    })

    it('abandons as navigated when the route leaves the flow', () => {
      tracker.onRoute(r('browse'), r('give-whereami'))
      const e = events().at(-1)
      expect(e.name).toBe('flow_abandon')
      expect(e.reason).toBe('navigated')
      expect(e.last_step).toBe('give-whereami')
    })

    it('completes instead when the submit succeeded, with the step it submitted from', () => {
      useFlowTelemetry(FlowName.GIVE).submitResult(true, 200, null)
      log.action.mockClear()
      tracker.onRoute(r('myposts'), r('give-whereami'))
      expect(names()).toEqual(['step_complete', 'flow_complete'])
      expect(events()[0].step).toBe('give-whereami')
    })

    it('records a switch of type when moving from give to ask', () => {
      tracker.onRoute(r('ask'), r('give-whereami'))
      const sw = events().find((e) => e.name === 'choice_switched')
      expect(sw).toMatchObject({ field: 'type', from: 'Offer', to: 'Wanted' })
      expect(names()).toContain('flow_abandon')
      expect(
        events().find((e) => e.name === 'flow_start' && e.flow === 'ask')
      ).toBeTruthy()
    })

    it('a new instance starts after the last one ended', () => {
      tracker.onRoute(r('browse'), r('give-whereami'))
      log.action.mockClear()
      tracker.onRoute(r('give'), r('browse'))
      expect(names()).toEqual(['flow_start', 'step_enter'])
    })

    it('does not start again for a step within the live flow', () => {
      tracker.onRoute(r('give-options'), r('give-whereami'))
      expect(names()).not.toContain('flow_start')
    })
  })

  describe('draftAgeFromStore', () => {
    const NOW = new Date('2026-10-12T10:00:00Z').getTime()
    const storeWith = (...messages) => ({ messages })

    beforeEach(() => {
      vi.setSystemTime(NOW)
    })

    it('is null with no message of the type', () => {
      expect(draftAgeFromStore(storeWith(), 'Offer')).toBeNull()
      expect(
        draftAgeFromStore(
          storeWith({ type: 'Wanted', item: 'x', savedAt: NOW - 60000 }),
          'Offer'
        )
      ).toBeNull()
    })

    it('is null for a blank message, which setup() creates on every visit', () => {
      expect(
        draftAgeFromStore(
          storeWith({ type: 'Offer', savedAt: NOW - 60000 }),
          'Offer'
        )
      ).toBeNull()
    })

    it('is the age of a message with an item, description or photo', () => {
      for (const content of [
        { item: 'x' },
        { description: 'y' },
        { attachments: [{ id: 1 }] },
      ]) {
        expect(
          draftAgeFromStore(
            storeWith({ type: 'Offer', savedAt: NOW - 90000, ...content }),
            'Offer'
          )
        ).toBe(90000)
      }
    })

    it('is null for something saved a moment ago, which is not a restored draft', () => {
      expect(
        draftAgeFromStore(
          storeWith({ type: 'Offer', item: 'x', savedAt: NOW - 1000 }),
          'Offer'
        )
      ).toBeNull()
    })

    it('ignores submitted messages and holes in the array', () => {
      expect(
        draftAgeFromStore(
          storeWith(undefined, {
            type: 'Offer',
            item: 'x',
            submitted: true,
            savedAt: NOW - 90000,
          }),
          'Offer'
        )
      ).toBeNull()
    })
  })
})
