/**
 * The server refuses some actions on purpose with a 403 and a message written for the
 * person (a moderator leaving their own community, Discourse 10242). The API layer turns
 * that into an APIError carrying response.data.message. asRefusalMessage pulls that out
 * so the screen can show it instead of the error page or nothing at all.
 */
import { describe, it, expect } from 'vitest'

import { asRefusalMessage } from '~/api/refusal'

function apiError(status, data) {
  const e = new Error('API Error DELETE /memberships -> status: ' + status)
  e.response = { status, data }
  return e
}

describe('asRefusalMessage', () => {
  it('returns the server message for a 403', () => {
    const msg =
      'You are a moderator of this community, so leaving would drop that role.'
    expect(asRefusalMessage(apiError(403, { error: 403, message: msg }))).toBe(
      msg
    )
  })

  it('ignores a 403 with no message', () => {
    expect(asRefusalMessage(apiError(403, {}))).toBeNull()
    expect(asRefusalMessage(apiError(403, null))).toBeNull()
    expect(asRefusalMessage(apiError(403, { message: '   ' }))).toBeNull()
  })

  it('ignores other statuses even if they carry a message', () => {
    expect(asRefusalMessage(apiError(500, { message: 'boom' }))).toBeNull()
    expect(asRefusalMessage(apiError(409, { message: 'clash' }))).toBeNull()
  })

  it('ignores errors with no response', () => {
    expect(asRefusalMessage(new Error('network'))).toBeNull()
    expect(asRefusalMessage(null)).toBeNull()
    expect(asRefusalMessage(undefined)).toBeNull()
  })
})
