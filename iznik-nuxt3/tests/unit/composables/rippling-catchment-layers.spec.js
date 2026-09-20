import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, it, expect } from 'vitest'

/*
 * setupRipplingExplorer.js drives Leaflet through raw DOM ids, so it is not
 * mountable in jsdom and has no behavioural spec. These are source-level
 * ratchets for the two guards that keep the catchment tab showing the
 * catchment, reported from the Rippling Out thread as "the group's own area is
 * not outlined in blue, and there is no heat-shading": both were true, because
 * the outbound layers had been painted over the catchment view.
 */

const SRC = resolve(
  __dirname,
  '../../../modtools/composables/rippling/setupRipplingExplorer.js'
)
const CSS = resolve(
  __dirname,
  '../../../modtools/components/RipplingExplorer.css'
)

const src = readFileSync(SRC, 'utf8')
const css = readFileSync(CSS, 'utf8')

// Body of a top-level `function <name>(...) { ... }`, matched by brace balance
// so nested blocks don't end it early.
function functionBody(text, name) {
  const start = text.indexOf(`function ${name}(`)
  if (start === -1) return null
  const open = text.indexOf('{', start)
  if (open === -1) return null
  let depth = 0
  for (let i = open; i < text.length; i++) {
    if (text[i] === '{') depth++
    else if (text[i] === '}') {
      depth--
      if (depth === 0) return text.slice(open, i + 1)
    }
  }
  return null
}

describe('rippling explorer keeps outbound layers off the catchment tab', () => {
  it('setLocation returns on the catchment tab before drawing outbound layers', () => {
    const body = functionBody(src, 'setLocation')
    expect(body, 'setLocation not found').toBeTruthy()

    const guard = body.indexOf("viewMode === 'catchment'")
    expect(
      guard,
      'setLocation must special-case catchment the way it does inbound'
    ).toBeGreaterThan(-1)

    // The outbound draw calls must all sit after the guard, or the guard is
    // decorative: these are what put the red boundary, the blue-stroked
    // quintiles and the green group outlines on the map.
    for (const call of [
      'updateIsochrone()',
      'fetchAndDrawGroups(',
      'fetchLocalBaseline(',
    ]) {
      const at = body.indexOf(call)
      expect(at, `${call} missing from setLocation`).toBeGreaterThan(-1)
      expect(
        at,
        `${call} runs before the catchment guard, so it still paints over catchment`
      ).toBeGreaterThan(guard)
    }
  })

  it('updateIsochrone refuses to draw while the catchment tab is showing', () => {
    const body = functionBody(src, 'updateIsochrone')
    expect(body, 'updateIsochrone not found').toBeTruthy()

    expect(
      body.includes("viewMode === 'catchment'"),
      'updateIsochrone is reachable from the fairness slider, the Proportionate ' +
        'button and ensureDriveMode, none of which check the tab'
    ).toBe(true)

    // The guard has to precede the fetch, not merely exist.
    expect(body.indexOf("viewMode === 'catchment'")).toBeLessThan(
      body.indexOf('apiUrl(')
    )
  })
})

describe('rippling explorer panel can be scrolled', () => {
  function rule(selector) {
    const at = css.indexOf(selector + ' {')
    if (at === -1) return null
    return css.slice(at, css.indexOf('}', at))
  }

  it('bounds the panel to the map so a short viewport cannot strand content', () => {
    const panel = rule('#rippling-panel')
    expect(panel, '#rippling-panel rule not found').toBeTruthy()
    // Absolutely positioned in a height:100% root, so without a bound the
    // document never overflows and the window has nothing to scroll.
    expect(panel).toMatch(/max-height:/)
    expect(panel).toMatch(/flex-direction:\s*column/)
  })

  it('scrolls the body rather than clipping it', () => {
    const body = rule('#rippling-panel-body')
    expect(body, '#rippling-panel-body rule not found').toBeTruthy()
    expect(body).toMatch(/overflow-y:\s*auto/)
    // A flex child will not shrink below its content height without this, so
    // overflow-y alone would still clip.
    expect(body).toMatch(/min-height:\s*0/)
  })
})

/*
 * The explorer is a description of production, not a sandbox for models we are
 * considering. reachmodel.js already says so for the numbers; these keep the
 * controls honest too, because a "possible alternative" toggle is read by
 * moderators as something we do.
 */
describe('rippling explorer offers no speculative models', () => {
  const vue = readFileSync(
    resolve(__dirname, '../../../modtools/components/RipplingExplorer.vue'),
    'utf8'
  )

  it.each([
    ['Possible alternative', 'the audience-based catchment reach model'],
    ['Proposed:', 'the amber audience-based outbound boundary'],
    ['audience-based', 'either audience-based control'],
    ['rippling-catchment-reach', 'the catchment reach-model radios'],
    ['rippling-tog-audience', 'the outbound audience layer toggle'],
  ])('does not offer %s in the panel (%s)', (needle) => {
    expect(vue).not.toContain(needle)
  })

  it('leaves no orphaned handling for the removed controls', () => {
    for (const sym of [
      'catchmentReachBasis',
      'showAudienceReach',
      'updateAudienceBoundary',
      'clearAudienceBoundary',
      'driveMinForAudience',
    ]) {
      expect(
        src,
        `${sym} is dead code once the controls are gone`
      ).not.toContain(sym)
    }
  })

  it('stops the reach slider at the ceiling a post actually gets', () => {
    // The markup carries the pre-hydration default; setup overrides both from
    // REACH_CEILING_MINUTES. Dragging past the ceiling drew a reach no post gets.
    expect(vue).toMatch(/id="rippling-time-slider"[\s\S]{0,120}max="45"/)
    expect(src).toMatch(/timeSlider\.max\s*=\s*String\(REACH_CEILING_MINUTES\)/)
    // And nothing falls back to the old 60-minute top stop.
    expect(src).not.toMatch(/Number\(timeSlider\.max\)\s*\|\|\s*60/)
  })
})
