import { describe, it, expect } from 'vitest'
import {
  sendAfterToInput,
  inputToSendAfter,
} from '~/modtools/composables/useAdminSendAfter'

describe('sendAfterToInput', () => {
  it.each([
    ['null', null],
    ['undefined', undefined],
    ['empty string', ''],
    ['zero-ish falsy 0', 0],
    ['unparseable string', 'not a date'],
    ['invalid ISO month', '2026-13-45T00:00:00Z'],
  ])('returns an empty string for %s', (_label, value) => {
    expect(sendAfterToInput(value)).toBe('')
  })

  it.each([
    ['2026-01-02T03:04:00', '2026-01-02T03:04'],
    ['2026-12-31T23:59:00', '2026-12-31T23:59'],
    ['2026-09-05T00:00:00', '2026-09-05T00:00'],
    ['2024-02-29T12:30:00', '2024-02-29T12:30'],
  ])('formats local time %s as %s, zero padded', (local, expected) => {
    // A string without an offset is parsed as local time, so it round trips.
    expect(sendAfterToInput(local)).toBe(expected)
  })

  it('formats an ISO instant in the local timezone', () => {
    const iso = '2026-06-15T10:20:30.000Z'
    const d = new Date(iso)
    const pad = (n) => String(n).padStart(2, '0')
    expect(sendAfterToInput(iso)).toBe(
      `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(
        d.getHours()
      )}:${pad(d.getMinutes())}`
    )
  })
})

describe('inputToSendAfter', () => {
  it.each([
    ['null', null],
    ['undefined', undefined],
    ['empty string', ''],
    ['unparseable string', 'garbage'],
    ['invalid date', '2026-99-99T99:99'],
  ])('returns null for %s', (_label, value) => {
    expect(inputToSendAfter(value)).toBeNull()
  })

  it('returns an ISO string for a datetime-local value', () => {
    const out = inputToSendAfter('2026-01-02T03:04')
    expect(out).toBe(new Date('2026-01-02T03:04').toISOString())
    expect(out).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/)
  })

  it('round trips through sendAfterToInput', () => {
    expect(sendAfterToInput(inputToSendAfter('2026-07-08T09:10'))).toBe(
      '2026-07-08T09:10'
    )
  })
})
