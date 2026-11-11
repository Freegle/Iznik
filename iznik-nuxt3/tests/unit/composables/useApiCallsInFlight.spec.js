import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'
import {
  apiCallsInFlight,
  trackApiCall,
  _resetApiCallsInFlightForTests,
} from '~/composables/useApiCallsInFlight'

describe('useApiCallsInFlight', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    _resetApiCallsInFlightForTests()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('lists a call without its query string until it is done', () => {
    const call = trackApiCall('GET', '/chat?requestid=4&loggedInAs=1')

    expect(apiCallsInFlight()).toEqual([
      { method: 'GET', path: '/chat', stage: 'waiting_for_online', ageMs: 0 },
    ])

    call.done()

    expect(apiCallsInFlight()).toEqual([])
  })

  it('moves to fetching once the online check has passed', () => {
    const call = trackApiCall('POST', '/message')
    vi.advanceTimersByTime(1500)
    call.fetching()

    expect(apiCallsInFlight()).toEqual([
      { method: 'POST', path: '/message', stage: 'fetching', ageMs: 1500 },
    ])
  })

  it('forgets a call that never finished after five minutes', () => {
    trackApiCall('GET', '/old')
    vi.advanceTimersByTime(6 * 60 * 1000)
    trackApiCall('GET', '/new')

    expect(apiCallsInFlight().map((c) => c.path)).toEqual(['/new'])
  })

  it('keeps no more than twenty', () => {
    for (let i = 0; i < 30; i++) trackApiCall('GET', '/p' + i)

    expect(apiCallsInFlight()).toHaveLength(20)
    expect(apiCallsInFlight()[19].path).toBe('/p29')
  })
})
