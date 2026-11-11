import { describe, it, expect } from 'vitest'
import {
  isOutcomeAlreadyRecorded,
  notOutcomeAlreadyRecorded,
} from '~/api/outcomeConflict'

describe('outcomeConflict', () => {
  const body = { error: 409, message: 'Outcome already recorded' }

  it('recognises the 409 the API sends for a second outcome', () => {
    expect(
      isOutcomeAlreadyRecorded({ response: { status: 409, data: body } })
    ).toBe(true)
  })

  it('does not mistake another 409 or another status for it', () => {
    expect(
      isOutcomeAlreadyRecorded({
        response: { status: 409, data: { heldby: 5 } },
      })
    ).toBe(false)
    expect(
      isOutcomeAlreadyRecorded({ response: { status: 400, data: body } })
    ).toBe(false)
    expect(isOutcomeAlreadyRecorded(new Error('network'))).toBe(false)
  })

  it('keeps only that response out of Sentry', () => {
    expect(notOutcomeAlreadyRecorded(body)).toBe(false)
    expect(notOutcomeAlreadyRecorded({ message: 'Invalid outcome' })).toBe(true)
    expect(notOutcomeAlreadyRecorded(null)).toBe(true)
  })
})
