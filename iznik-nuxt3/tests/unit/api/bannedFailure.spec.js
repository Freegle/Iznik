import { describe, it, expect } from 'vitest'
import { notABannedFailure, isBannedFailure } from '~/api/bannedFailure'

// A banned join/add is refused with 403 "Failed - banned". notABannedFailure keeps it
// out of Sentry; isBannedFailure lets callers react (self-join swallows it, mod-add
// surfaces it).
describe('bannedFailure', () => {
  describe('notABannedFailure (Sentry suppression)', () => {
    it('suppresses (false) a banned message object', () => {
      expect(
        notABannedFailure({ error: 403, message: 'Failed - banned' })
      ).toBe(false)
    })

    it('suppresses (false) a banned message string', () => {
      expect(notABannedFailure('Failed - banned')).toBe(false)
    })

    it('logs (true) other failures and empty data', () => {
      expect(notABannedFailure({ error: 500, message: 'boom' })).toBe(true)
      expect(notABannedFailure(null)).toBe(true)
      expect(notABannedFailure('Not a moderator of this group')).toBe(true)
    })
  })

  describe('isBannedFailure', () => {
    it('is true for a 403 whose message mentions banned', () => {
      expect(
        isBannedFailure({
          response: { status: 403, data: { message: 'Failed - banned' } },
        })
      ).toBe(true)
    })

    it('is false for a 403 that is not about a ban', () => {
      expect(
        isBannedFailure({
          response: { status: 403, data: { message: 'Not a moderator' } },
        })
      ).toBe(false)
    })

    it('is false for non-403 errors even if they mention banned', () => {
      expect(
        isBannedFailure({
          response: { status: 500, data: { message: 'banned' } },
        })
      ).toBe(false)
    })

    it('is false for a malformed/absent error', () => {
      expect(isBannedFailure(undefined)).toBe(false)
      expect(isBannedFailure({})).toBe(false)
    })
  })
})
