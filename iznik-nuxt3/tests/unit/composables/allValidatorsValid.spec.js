import { describe, it, expect, vi } from 'vitest'
import { allValidatorsValid } from '~/composables/allValidatorsValid'

describe('allValidatorsValid', () => {
  it('returns false when there are no validators', async () => {
    expect(await allValidatorsValid([])).toBe(false)
    expect(await allValidatorsValid([null, undefined])).toBe(false)
    expect(await allValidatorsValid(undefined)).toBe(false)
  })

  it('calls validate() on every present validator', async () => {
    const a = { validate: vi.fn().mockResolvedValue(true) }
    const b = { validate: vi.fn().mockResolvedValue(true) }

    await allValidatorsValid([a, null, b])

    expect(a.validate).toHaveBeenCalledTimes(1)
    expect(b.validate).toHaveBeenCalledTimes(1)
  })

  it('returns true when the only present validator passes', async () => {
    const ok = { validate: vi.fn().mockResolvedValue(true) }
    expect(await allValidatorsValid([ok])).toBe(true)
  })

  it('returns false if any validator fails', async () => {
    const ok = { validate: vi.fn().mockResolvedValue(true) }
    const bad = { validate: vi.fn().mockResolvedValue(false) }
    expect(await allValidatorsValid([ok, bad])).toBe(false)
  })

  it('ignores null/undefined entries when the present ones pass', async () => {
    const ok = { validate: vi.fn().mockResolvedValue(true) }
    expect(await allValidatorsValid([null, ok, undefined])).toBe(true)
  })
})
