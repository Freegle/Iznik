import { describe, it, expect } from 'vitest'
import {
  STEP_LABELS,
  STEP_HELP,
  EXIT_STEPS,
  stepHelp,
  stageHelp,
  wayInHelp,
} from '~/modtools/composables/useUxFunnel'
import { FLOW_ROUTES } from '~/composables/useFlowRoutes'

describe('what each funnel step means', () => {
  // A step that appears in a funnel without an explanation fails here, so a new page cannot be
  // added to the funnels and left as a bare name nobody can place.
  const known = new Set([
    ...Object.keys(STEP_LABELS),
    ...Object.values(FLOW_ROUTES).flatMap((r) => Object.keys(r)),
    ...Object.values(EXIT_STEPS).flatMap((e) => Object.keys(e)),
  ])

  it.each([...known])('explains %s', (step) => {
    // 'choose' means different things in two flows, so it has one entry for each.
    const help = stepHelp(step) || stepHelp(step, 'chat_promise')
    expect(help, step).toBeTruthy()
    expect(help.length, step).toBeGreaterThan(30)
  })

  it('has no explanation for a step that does not exist', () => {
    for (const key of Object.keys(STEP_HELP)) {
      expect(known.has(key.split(':').pop()), key).toBe(true)
    }
  })

  it('gives the route of every page step, so the reader can find the page', () => {
    for (const flow of Object.keys(FLOW_ROUTES)) {
      for (const step of Object.keys(FLOW_ROUTES[flow])) {
        const route = '/' + step.replace(/-/g, '/')
        const text = stepHelp(step, flow)
        expect(text, step).toContain(route)
      }
    }
  })

  it('tells the two Choosing steps apart by flow', () => {
    expect(stepHelp('choose', 'chat_promise')).toContain('promise')
    expect(stepHelp('choose', 'chat_outcome')).toContain('taken')
  })

  it('falls back to nothing for an unknown step', () => {
    expect(stepHelp('nonsense')).toBe('')
  })

  it('explains a way in as how the member arrived', () => {
    const text = wayInHelp({ step: 'signin', label: 'Signing in' }, 'login')
    expect(text).toMatch(/^Came in through Signing in\./)
    expect(text).toContain(stepHelp('signin', 'login'))
  })

  it('explains a stage with several ways in by listing them', () => {
    const text = stageHelp(
      {
        step: 'give-mobile-photos',
        label: 'Photos or Start',
        alternatives: [
          { step: 'give-mobile-photos', label: 'Photos' },
          { step: 'give-mobile', label: 'Start' },
        ],
      },
      'give'
    )
    expect(text).toContain('Photos:')
    expect(text).toContain('Start:')
    expect(text).toContain('/give/mobile/photos')
    expect(text).toContain('/give/mobile')
  })
})
