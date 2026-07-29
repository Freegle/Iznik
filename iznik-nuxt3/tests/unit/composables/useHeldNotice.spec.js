/**
 * Shared handling for "another moderator is holding this" refusals.
 *
 * The server enforces holds, so a moderator acting from a stale screen gets a 409
 * rather than the action going through (Discourse 9946). The mod still clicked a
 * button, so they have to be told it did not happen and by whom - previously they
 * got a generic failure.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

import {
  asHeldByOtherError,
  runHoldAware,
  notAHeldConflict,
} from '~/api/heldConflict'
import { useHeldNotice } from '~/composables/useHeldNotice'

function heldConflict(name = 'Jos') {
  const e = new Error('API Error')
  e.response = {
    status: 409,
    data: {
      ret: 1,
      status: 'Held by another moderator',
      heldby: 25789138,
      heldbyname: name,
    },
  }
  return e
}

describe('asHeldByOtherError', () => {
  it('names the holder', () => {
    const held = asHeldByOtherError(heldConflict())
    expect(held.message).toMatch(/Jos is holding this/)
    expect(held.heldByOtherMod).toBe(true)
    expect(held.heldby).toBe(25789138)
  })

  it('falls back when the server sends no name', () => {
    const e = heldConflict()
    delete e.response.data.heldbyname
    expect(asHeldByOtherError(e).message).toMatch(
      /Another moderator is holding/
    )
  })

  it('ignores a 409 that is not a hold conflict', () => {
    const e = new Error('Conflict')
    e.response = { status: 409, data: {} }
    expect(asHeldByOtherError(e)).toBeNull()
  })

  it('ignores other statuses', () => {
    const e = new Error('Boom')
    e.response = { status: 500, data: {} }
    expect(asHeldByOtherError(e)).toBeNull()
  })
})

describe('runHoldAware', () => {
  it('refreshes so the Held state reaches the screen, then reports it', async () => {
    const refresh = vi.fn().mockResolvedValue()
    const action = vi.fn().mockRejectedValue(heldConflict())

    await expect(runHoldAware(action, refresh)).rejects.toThrow(
      /Jos is holding this/
    )
    expect(refresh).toHaveBeenCalled()
  })

  it('still reports the refusal when the refresh itself fails', async () => {
    const refresh = vi.fn().mockRejectedValue(new Error('network'))
    const action = vi.fn().mockRejectedValue(heldConflict())

    await expect(runHoldAware(action, refresh)).rejects.toThrow(
      /Jos is holding this/
    )
  })

  it('passes other failures through untouched and does not refresh', async () => {
    const refresh = vi.fn()
    const boom = new Error('Server exploded')
    boom.response = { status: 500, data: {} }

    await expect(
      runHoldAware(() => Promise.reject(boom), refresh)
    ).rejects.toThrow('Server exploded')
    expect(refresh).not.toHaveBeenCalled()
  })

  it('returns the result untouched on success', async () => {
    await expect(runHoldAware(() => Promise.resolve('done'))).resolves.toBe(
      'done'
    )
  })
})

describe('useHeldNotice', () => {
  let notice

  beforeEach(() => {
    notice = useHeldNotice()
  })

  it('captures the refusal for display instead of throwing', async () => {
    await notice.guardHold(() =>
      Promise.reject(asHeldByOtherError(heldConflict()))
    )
    expect(notice.heldError.value).toMatch(/Jos is holding this/)
  })

  it('clears a previous refusal when the action is retried', async () => {
    await notice.guardHold(() =>
      Promise.reject(asHeldByOtherError(heldConflict()))
    )
    expect(notice.heldError.value).toBeTruthy()

    await notice.guardHold(() => Promise.resolve('ok'))
    expect(notice.heldError.value).toBeNull()
  })

  it('rethrows anything that is not a hold refusal', async () => {
    await expect(
      notice.guardHold(() => Promise.reject(new Error('Server exploded')))
    ).rejects.toThrow('Server exploded')
  })
})

describe('notAHeldConflict', () => {
  it('keeps hold refusals out of Sentry but logs real faults', () => {
    expect(notAHeldConflict({ heldby: 123 })).toBe(false)
    expect(notAHeldConflict({})).toBe(true)
    expect(notAHeldConflict(null)).toBe(true)
  })
})
