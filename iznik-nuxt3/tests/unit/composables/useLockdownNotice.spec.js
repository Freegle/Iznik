/**
 * Shared handling for "changes are paused" refusals (see
 * plans/active/2026-09-27-lockdown-switch.md section 11.3). Support/Admin
 * callers are exempt from every refusal, so this only ever fires for a
 * member or ordinary moderator whose write reached the server while a
 * lockdown surface was held.
 */
import { describe, it, expect, beforeEach } from 'vitest'

import { asLockdownError } from '~/api/lockdownConflict'
import { useLockdownNotice } from '~/composables/useLockdownNotice'

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

describe('useLockdownNotice', () => {
  let notice

  beforeEach(() => {
    notice = useLockdownNotice()
  })

  it('captures the refusal for display instead of throwing', async () => {
    await notice.guardLockdown(() =>
      Promise.reject(asLockdownError(lockdownConflict()))
    )
    expect(notice.lockdownError.value).toMatch(/Changes are paused/)
  })

  it('clears a previous refusal when the action is retried', async () => {
    await notice.guardLockdown(() =>
      Promise.reject(asLockdownError(lockdownConflict()))
    )
    expect(notice.lockdownError.value).toBeTruthy()

    await notice.guardLockdown(() => Promise.resolve('ok'))
    expect(notice.lockdownError.value).toBeNull()
  })

  it('rethrows anything that is not a lockdown refusal', async () => {
    await expect(
      notice.guardLockdown(() => Promise.reject(new Error('Server exploded')))
    ).rejects.toThrow('Server exploded')
  })

  it('can be cleared explicitly', async () => {
    await notice.guardLockdown(() =>
      Promise.reject(asLockdownError(lockdownConflict()))
    )
    expect(notice.lockdownError.value).toBeTruthy()

    notice.clearLockdownError()
    expect(notice.lockdownError.value).toBeNull()
  })
})
