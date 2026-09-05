import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'
import { describe, it, expect } from 'vitest'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

// The sticky bottom banner reserves a FIXED height (sticky-banner.scss: 113px,
// or 273px on a tall desktop) but the ad, or the WhatJobs block that stands in
// for it, only takes its natural height. With few jobs the remainder is empty,
// and because `.sticky` is position:fixed over the page that remainder showed
// the page content straight through it: a transparent hole under the job ads.
//
// `.sticky-ad-zone` was added to tint that zone, but it was declared BEFORE
// `.sticky`'s `background-color: transparent` at lg+. Same specificity, later
// wins, so on desktop the tint never applied at all. Verified on production:
// the element carried class `sticky-ad-zone` and still computed
// `rgba(0, 0, 0, 0)` at a 1280px viewport.
//
// These assertions are on the cascade, not just on the colour, because a
// colour-only test passed happily throughout the whole period the tint was dead.
describe('Sticky ad zone background', () => {
  const layout = readFileSync(
    resolve(__dirname, '../../../components/LayoutCommon.vue'),
    'utf-8'
  )
  const jobs = readFileSync(
    resolve(__dirname, '../../../components/JobsDaSlot.vue'),
    'utf-8'
  )

  const stripComments = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '')

  it('tints the ad zone the same colour as the jobs block it surrounds', () => {
    const idx = layout.indexOf('.sticky-ad-zone {')
    expect(idx, 'LayoutCommon must style .sticky-ad-zone').toBeGreaterThan(-1)
    const rule = stripComments(layout.substring(idx, layout.indexOf('}', idx)))
    const tint = rule.match(/background-color:\s*([^;]+);/)?.[1]?.trim()
    expect(tint, '.sticky-ad-zone must set a background-color').toBeTruthy()

    const jobsIdx = jobs.indexOf('.jobs-slot {')
    const jobsRule = stripComments(
      jobs.substring(jobsIdx, jobs.indexOf('}', jobsIdx))
    )
    const jobsBg = jobsRule.match(/background:\s*([^;]+);/)?.[1]?.trim()

    // Same surface, so the leftover space reads as part of the block rather
    // than a gap. $gray-200 is #e9ecef; accept either spelling on either side.
    const norm = (c) => (c || '').toLowerCase().replace('$gray-200', '#e9ecef')
    expect(norm(tint), 'ad zone tint must match the jobs slot background').toBe(
      norm(jobsBg)
    )
  })

  it('tints the zone in a way the lg+ transparent rule cannot override', () => {
    // `.sticky` turns its background transparent from lg up. That is correct
    // when no ad rendered (no .sticky-ad-zone class), but it must not win over
    // the ad-zone tint — which it does whenever the tint is a plain
    // `.sticky-ad-zone` rule sitting earlier in the file.
    const transparentIdx = layout.search(/background-color:\s*transparent/)
    if (transparentIdx === -1) return // rule gone entirely: nothing to override

    const nestedIdx = layout.indexOf('&.sticky-ad-zone {')
    if (nestedIdx > -1) {
      // Nested inside `.sticky` → specificity (0,2,0) beats (0,1,0) whatever
      // the source order. This is the robust form.
      expect(nestedIdx).toBeGreaterThan(-1)
      return
    }

    const flatIdx = layout.indexOf('.sticky-ad-zone {')
    expect(
      flatIdx,
      'a flat .sticky-ad-zone rule must come AFTER the lg transparent rule, ' +
        'or be nested as &.sticky-ad-zone so specificity decides'
    ).toBeGreaterThan(transparentIdx)
  })
})
