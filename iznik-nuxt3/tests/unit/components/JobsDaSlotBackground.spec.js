import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'
import { describe, it, expect } from 'vitest'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

// The mobile nav bar is pure white (NavbarMobile.vue `background: white`) and the
// job-ads slot sits directly beneath it. When the slot is also white the two run
// together and the nav bar stops reading as a separate bar.
//
// This was fixed once before by tinting `.jobs-slot`, but JobOne paints each
// `.job-summary` row white, which covered the tint and left the block looking
// white again. Both halves are needed, so both are asserted here.
describe('Jobs ad slot background', () => {
  const source = readFileSync(
    resolve(__dirname, '../../../components/JobsDaSlot.vue'),
    'utf-8'
  )

  // Comments inside these rules explain the colour choice and name the old one,
  // so strip them: we are asserting on what the CSS DOES, not what it says.
  function block(selector) {
    const idx = source.indexOf(selector)
    expect(idx, `${selector} should exist in JobsDaSlot`).toBeGreaterThan(-1)
    return source
      .substring(idx, source.indexOf('}', idx))
      .replace(/\/\*[\s\S]*?\*\//g, '')
  }

  function backgroundOf(selector) {
    const declaration = block(selector).match(/background:\s*([^;]+);/)
    expect(declaration, `${selector} must set a background`).not.toBeNull()
    return declaration[1].trim()
  }

  it('tints the slot so it is not white like the nav bar above it', () => {
    const background = backgroundOf('.jobs-slot {')

    expect(background, '.jobs-slot must not be white').not.toMatch(
      /^(\$white|#fff|#ffffff|white)$/i
    )
    // #F5F5F5 is only 4% off white and did not separate from the bar in practice.
    expect(
      background,
      'the tint must be stronger than $color-gray--lighter'
    ).not.toBe('$color-gray--lighter')
  })

  it('stops the job rows painting white over that tint', () => {
    const rows = block('.jobs-slot :deep(.job-summary) {')

    expect(
      rows,
      'rows inside the slot must let the slot background show through'
    ).toContain('background: transparent')
  })
})
