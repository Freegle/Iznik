/**
 * Shared handling for "changes are paused" refusals (see
 * plans/active/2026-09-27-lockdown-switch.md section 11.3).
 *
 * The server refuses a write with a 409 while a lockdown surface is held,
 * carrying `lockdown: true` and a ready-to-show `status` message. That is an
 * expected, handled outcome - the whole point of the lockdown - not a fault,
 * so it should stay out of Sentry and show the paused message rather than an
 * error modal.
 */
import { describe, it, expect, vi } from 'vitest'

import {
  asLockdownError,
  runLockdownAware,
  notALockdownConflict,
} from '~/api/lockdownConflict'

function lockdownConflict(
  message = 'Changes are paused for a few hours while we deal with a security incident.'
) {
  const e = new Error('API Error')
  e.response = {
    status: 409,
    data: {
      ret: 409,
      status: message,
      lockdown: true,
    },
  }
  return e
}

describe('asLockdownError', () => {
  it('carries the paused message from the server', () => {
    const lockdown = asLockdownError(lockdownConflict())
    expect(lockdown.message).toMatch(/Changes are paused/)
    expect(lockdown.lockdownRefused).toBe(true)
  })

  it('falls back to a default message when the server sends none', () => {
    const e = lockdownConflict()
    delete e.response.data.status
    expect(asLockdownError(e).message).toMatch(/Changes are paused/)
  })

  it('ignores a 409 that is not a lockdown conflict', () => {
    const e = new Error('Conflict')
    e.response = { status: 409, data: {} }
    expect(asLockdownError(e)).toBeNull()
  })

  it('ignores other statuses', () => {
    const e = new Error('Boom')
    e.response = { status: 500, data: {} }
    expect(asLockdownError(e)).toBeNull()
  })
})

describe('runLockdownAware', () => {
  it('refreshes so the paused state reaches the screen, then reports it', async () => {
    const refresh = vi.fn().mockResolvedValue()
    const action = vi.fn().mockRejectedValue(lockdownConflict())

    await expect(runLockdownAware(action, refresh)).rejects.toThrow(
      /Changes are paused/
    )
    expect(refresh).toHaveBeenCalled()
  })

  it('still reports the refusal when the refresh itself fails', async () => {
    const refresh = vi.fn().mockRejectedValue(new Error('network'))
    const action = vi.fn().mockRejectedValue(lockdownConflict())

    await expect(runLockdownAware(action, refresh)).rejects.toThrow(
      /Changes are paused/
    )
  })

  it('passes other failures through untouched and does not refresh', async () => {
    const refresh = vi.fn()
    const boom = new Error('Server exploded')
    boom.response = { status: 500, data: {} }

    await expect(
      runLockdownAware(() => Promise.reject(boom), refresh)
    ).rejects.toThrow('Server exploded')
    expect(refresh).not.toHaveBeenCalled()
  })

  it('returns the result untouched on success', async () => {
    await expect(
      runLockdownAware(() => Promise.resolve('done'))
    ).resolves.toBe('done')
  })
})

describe('notALockdownConflict', () => {
  it('keeps lockdown refusals out of Sentry but logs real faults', () => {
    expect(notALockdownConflict({ lockdown: true })).toBe(false)
    expect(notALockdownConflict({})).toBe(true)
    expect(notALockdownConflict(null)).toBe(true)
  })
})
