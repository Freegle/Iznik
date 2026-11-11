import { describe, it, expect, beforeEach } from 'vitest'
import { spinButtonContext } from '~/composables/useSpinButtonDiagnostics'
import {
  trackApiCall,
  _resetApiCallsInFlightForTests,
} from '~/composables/useApiCallsInFlight'

describe('spinButtonContext', () => {
  beforeEach(() => _resetApiCallsInFlightForTests())

  it('says who owns the button, how long it waited and what was in flight', () => {
    trackApiCall('POST', '/chatmessages').fetching()

    const context = spinButtonContext({
      componentName: 'ChatFooter',
      label: 'Send',
      clickedAt: 1000,
      now: 21000,
      online: true,
      connection: { effectiveType: '3g', saveData: false },
    })

    expect(context).toMatchObject({
      component: 'ChatFooter',
      label: 'Send',
      waitedMs: 20000,
      online: true,
      effectiveType: '3g',
      saveData: false,
    })
    expect(context.apiCallsInFlight).toEqual([
      expect.objectContaining({ path: '/chatmessages', stage: 'fetching' }),
    ])
  })

  it('copes with a button that was never pressed and a browser with no connection info', () => {
    expect(
      spinButtonContext({ label: '', online: true, clickedAt: null })
    ).toMatchObject({
      component: null,
      waitedMs: null,
      effectiveType: null,
      saveData: null,
    })
  })
})
