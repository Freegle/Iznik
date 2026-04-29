import { describe, it, expect } from 'vitest'
import { uid } from '~/composables/useId'

describe('useId', () => {
  it('prefixes with the supplied type', () => {
    const result = uid('btn-')
    expect(result.startsWith('btn-')).toBe(true)
  })

  it('returns a different id on each call', () => {
    const a = uid('x')
    const b = uid('x')
    expect(a).not.toBe(b)
  })

  it('monotonically increases the numeric suffix', () => {
    const a = uid('n')
    const b = uid('n')
    const aNum = Number(a.slice(1))
    const bNum = Number(b.slice(1))
    expect(bNum).toBe(aNum + 1)
  })

  it('does not share a counter between prefixes (counter is global)', () => {
    // Documents existing behaviour — a single module-level counter is shared
    const a = uid('foo-')
    const b = uid('bar-')
    expect(Number(b.slice(4))).toBe(Number(a.slice(4)) + 1)
  })
})
