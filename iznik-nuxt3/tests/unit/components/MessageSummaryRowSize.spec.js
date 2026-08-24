import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, it, expect } from 'vitest'

/**
 * The lg+ feed card is a horizontal row whose photo is a square, so the square's side is
 * also the card's height. It used to be a fixed 200px, which meant a short screen only
 * fitted two or three posts. It is now derived from the viewport height so that six fit.
 *
 * assets/css/_feed-card.scss is the single source of truth; this reads the constants back
 * out of it and checks the sizing they produce still meets that goal, so that raising, say,
 * the minimum row height fails here rather than quietly costing a card.
 */

const cssDir = resolve(__dirname, '../../../assets/css')
const feedCardScss = readFileSync(resolve(cssDir, '_feed-card.scss'), 'utf-8')
const stickyBannerScss = readFileSync(
  resolve(cssDir, 'sticky-banner.scss'),
  'utf-8'
)

function px(source, name) {
  const match = source.match(new RegExp(`\\$${name}:\\s*(\\d+)px`))
  expect(match, `expected $${name} to be declared in px`).toBeTruthy()
  return parseInt(match[1], 10)
}

function count(source, name) {
  const match = source.match(new RegExp(`\\$${name}:\\s*(\\d+)\\s*;`))
  expect(match, `expected $${name} to be declared`).toBeTruthy()
  return parseInt(match[1], 10)
}

const TARGET = count(feedCardScss, 'feed-cards-target')
const GAP = px(feedCardScss, 'feed-card-gap')
const MIN = px(feedCardScss, 'feed-card-min')
const MAX = px(feedCardScss, 'feed-card-max')
const NAVBAR = px(feedCardScss, 'feed-card-navbar')
const STICKY = px(stickyBannerScss, 'sticky-banner-height-desktop')

/* The chrome the CSS budgets for: the fixed navbar, the sticky bottom ad, and the gaps
   between the target number of cards. */
const CHROME = NAVBAR + STICKY + (TARGET - 1) * GAP

/* What the clamp() in _feed-card.scss evaluates to at a given viewport height. */
function rowSize(viewportHeight) {
  return Math.min(MAX, Math.max(MIN, (viewportHeight - CHROME) / TARGET))
}

/* Feed height left between the fixed navbar and the sticky bottom ad. */
function usableHeight(viewportHeight) {
  return viewportHeight - NAVBAR - STICKY
}

/* Height that n cards and the gaps between them take up. */
function heightFor(n, viewportHeight) {
  return n * rowSize(viewportHeight) + (n - 1) * GAP
}

describe('feed card row sizing', () => {
  it('fits the target number of cards on a short laptop screen', () => {
    /* 720 is the viewport a 1280x720 laptop actually gives you, and the case that prompted
       this change: at a fixed 200px only two or three posts were on screen at once. */
    expect(heightFor(TARGET, 720)).toBeLessThanOrEqual(usableHeight(720) + 0.5)
  })

  it('fits the target number of cards on taller screens too', () => {
    for (const height of [768, 800, 900, 1000, 1080, 1440, 2160]) {
      expect(heightFor(TARGET, height)).toBeLessThanOrEqual(
        usableHeight(height) + 0.5
      )
    }
  })

  it('never grows past the original size, however large the screen', () => {
    for (const height of [1440, 2160, 4320]) {
      expect(rowSize(height)).toBe(MAX)
    }
    expect(MAX).toBe(200)
  })

  it('shows fewer cards rather than an unreadable one on a very short screen', () => {
    /* Below about 710px of viewport the target would need a photo too small for the text
       beside it, so the clamp holds the row at its minimum and we show fewer. */
    expect(rowSize(600)).toBe(MIN)
    expect(heightFor(TARGET, 600)).toBeGreaterThan(usableHeight(600))
  })

  it('grows monotonically with the viewport, so a taller window never shows less', () => {
    let previous = 0
    for (let height = 300; height <= 2000; height += 10) {
      const size = rowSize(height)
      expect(size).toBeGreaterThanOrEqual(previous)
      previous = size
    }
  })

  it('leaves room beside the smallest photo for the text that stays visible', () => {
    /* At the minimum row the description is dropped, but the header (subject + location,
       46px at default font size) and the meta line (distance + age, 17px) must still fit
       within the row's minimum vertical padding of 0.25rem a side. */
    const HEADER = 46
    const META = 17
    const MIN_PADDING = 4
    expect(MIN - 2 * MIN_PADDING).toBeGreaterThanOrEqual(HEADER + META)
  })

  it('drops description lines in the right order as the row shrinks', () => {
    const oneLineRow = px(feedCardScss, 'feed-card-desc-oneline-row')
    const twoLineRow = px(feedCardScss, 'feed-card-desc-twoline-row')

    /* Two lines have to give way before the last one does, and both thresholds must sit
       inside the range the row actually takes, or a tier is unreachable. */
    expect(oneLineRow).toBeLessThan(twoLineRow)
    expect(oneLineRow).toBeGreaterThan(MIN)
    expect(twoLineRow).toBeLessThan(MAX)
  })
})

describe('feed card and skeleton stay the same size', () => {
  const summary = readFileSync(
    resolve(__dirname, '../../../components/MessageSummary.vue'),
    'utf-8'
  )
  const skeleton = readFileSync(
    resolve(__dirname, '../../../components/MessageSkeleton.vue'),
    'utf-8'
  )

  it('sizes the summary photo from the row variable rather than a fixed height', () => {
    const photoArea = summary.slice(
      summary.indexOf('.photo-area {'),
      summary.indexOf('.photo-container {')
    )
    expect(photoArea).toContain('var(--summary-row-size')
    /* The lg+ block used to hard-code 200px for both width and height. */
    expect(photoArea).not.toContain('width: 200px')
    expect(photoArea).not.toContain('height: 200px')
  })

  it('sizes the skeleton photo the same way, so the card does not jump on hydration', () => {
    const photoArea = skeleton.slice(
      skeleton.indexOf('.skeleton-photo-area {'),
      skeleton.indexOf('.skeleton-content {')
    )
    expect(photoArea).toContain('var(--summary-row-size')
    expect(photoArea).not.toContain('width: 200px')
    expect(photoArea).not.toContain('height: 200px')
  })

  it('caps both the card and the skeleton at the same row height', () => {
    expect(summary).toContain('max-height: var(--summary-row-size')
    expect(skeleton).toContain('max-height: var(--summary-row-size')
  })
})
