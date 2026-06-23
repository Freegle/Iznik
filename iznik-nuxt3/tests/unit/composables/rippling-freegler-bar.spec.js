import { describe, it, expect } from 'vitest'
import { buildFreeglerBarHTML } from '~/modtools/composables/rippling/freeglerBar.js'

describe('rippling/freeglerBar', () => {
  it('returns null when estimate is zero', () => {
    expect(buildFreeglerBarHTML(0, 5000, 0.35)).toBeNull()
  })

  it('returns null when total located is zero', () => {
    expect(buildFreeglerBarHTML(100, 0, 0.35)).toBeNull()
  })

  it('shows "would be notified" headline', () => {
    const html = buildFreeglerBarHTML(1000, 5000, 0.35)
    expect(html).toContain('would be notified')
  })

  it('shows located count with "with known location"', () => {
    const html = buildFreeglerBarHTML(1000, 5000, 0.35)
    expect(html).toContain('1,000 with known location')
  })

  it('shows estimated unlocated addend when total > located', () => {
    const html = buildFreeglerBarHTML(1000, 5000, 0.35)
    expect(html).toContain('estimated unlocated')
  })

  it('omits the unlocated addend when all members have known locations', () => {
    const html = buildFreeglerBarHTML(1000, 5000, 0)
    expect(html).not.toContain('estimated unlocated')
  })

  it('does not mention TrashNothing or a separate algorithm (misleading tooltip fixed)', () => {
    const html = buildFreeglerBarHTML(1000, 5000, 0.35)
    expect(html).not.toContain('TrashNothing')
    expect(html).not.toContain('separate algorithm')
  })
})
