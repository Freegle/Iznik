import { describe, it, expect } from 'vitest'
import { getSendIdempotencyKey } from '~/composables/useChat'

// Discourse #9913: a manual retry of a not-yet-confirmed message (e.g. after a
// failed/ambiguous send) must reuse the SAME idempotency key, so the server's
// unique-key guard can return the already-created row instead of a genuine
// duplicate if the earlier request actually landed. A different message - or a
// first attempt - always gets a fresh key.
describe('getSendIdempotencyKey', () => {
  const UUID_RE =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

  it('generates a valid key when there is no pending attempt', () => {
    const key = getSendIdempotencyKey(null, 'hello')
    expect(key).toMatch(UUID_RE)
  })

  it('generates a fresh key each time when there is no pending attempt', () => {
    const key1 = getSendIdempotencyKey(null, 'hello')
    const key2 = getSendIdempotencyKey(null, 'hello')
    expect(key1).not.toBe(key2)
  })

  it('reuses the pending key when retrying the exact same message text', () => {
    const pending = { message: 'hello', key: 'abc-123' }
    expect(getSendIdempotencyKey(pending, 'hello')).toBe('abc-123')
  })

  it('generates a fresh key when the message text has changed', () => {
    const pending = { message: 'hello', key: 'abc-123' }
    const key = getSendIdempotencyKey(pending, 'hello, edited')
    expect(key).not.toBe('abc-123')
    expect(key).toMatch(UUID_RE)
  })

  it('generates a fresh key when there was no prior message text (first send)', () => {
    const pending = { message: '', key: 'abc-123' }
    const key = getSendIdempotencyKey(pending, 'hello')
    expect(key).not.toBe('abc-123')
  })
})
