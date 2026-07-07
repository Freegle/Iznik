import { describe, it, expect } from 'vitest'

import { replySurfaceForRoute } from '~/composables/useReplySurface'

// The committing-surface derivation for reply provenance. Pure function over the
// route, so these are exhaustive table tests of the mapping the server will see
// in rippling_reply_attribution.client_source.
describe('replySurfaceForRoute', () => {
  it('keeps an email src param verbatim on the message page', () => {
    expect(
      replySurfaceForRoute({
        path: '/message/123',
        query: { src: 'digest', reply: '1' },
      })
    ).toBe('digest')
  })

  it('maps a bare ?reply=1 message deep link to email (post-card click)', () => {
    expect(
      replySurfaceForRoute({ path: '/message/123', query: { reply: '1' } })
    ).toBe('email')
  })

  it('maps a plain message deep link to message_page', () => {
    expect(replySurfaceForRoute({ path: '/message/123', query: {} })).toBe(
      'message_page'
    )
  })

  it('maps browse without a term to browse and with a term to search', () => {
    expect(
      replySurfaceForRoute({ path: '/browse', query: {}, params: {} })
    ).toBe('browse')
    expect(
      replySurfaceForRoute({
        path: '/browse/bike',
        query: {},
        params: { term: 'bike' },
      })
    ).toBe('search')
  })

  it('falls back to the first path segment elsewhere', () => {
    expect(replySurfaceForRoute({ path: '/myposts', query: {} })).toBe(
      'myposts'
    )
    expect(replySurfaceForRoute({ path: '/', query: {} })).toBe('home')
  })

  it('is defensive about a missing route', () => {
    expect(replySurfaceForRoute(null)).toBe('unknown')
  })
})
