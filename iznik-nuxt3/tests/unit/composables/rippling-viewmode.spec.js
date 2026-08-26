import { describe, it, expect } from 'vitest'
import {
  PANEL_IDS,
  defaultReachMinutes,
  groupsSectionTitle,
  panelVisibility,
  reachSliderLabel,
} from '~/modtools/composables/rippling/viewmode.js'
import { REACH_CEILING_MINUTES } from '~/modtools/composables/rippling/reachmodel.js'

// The explorer merged "who could see my post" and the old digest preview into one tab
// with a direction selector. Nothing here throws when it goes wrong: a control left
// visible in the wrong direction just sits there offering a knob that does nothing, or
// implying a model we don't run. These lock the mapping instead.

describe('panelVisibility', () => {
  it('answers for every panel the toggle governs, in all three views', () => {
    // A row omitted from the map is never hidden — it keeps whatever the previous view
    // left it at, which is how a stale control survives a tab switch.
    for (const view of ['catchment', 'outbound', 'inbound']) {
      const v = panelVisibility(view)
      for (const id of PANEL_IDS) {
        expect(typeof v[id], `${id} in ${view}`).toBe('boolean')
      }
      expect(Object.keys(v).sort()).toEqual([...PANEL_IDS].sort())
    }
  })

  it('offers the direction selector on the reach tab only', () => {
    expect(panelVisibility('outbound')['rippling-direction']).toBe(true)
    expect(panelVisibility('inbound')['rippling-direction']).toBe(true)
    expect(panelVisibility('catchment')['rippling-direction']).toBe(false)
  })

  it('keeps the fairness controls to the outbound direction', () => {
    // Fairness widens a POST's targeting into deprived areas. It does not move what any
    // one member is shown, so an inbound slider would be claiming an effect it has not.
    expect(panelVisibility('outbound')['rippling-fairness-row']).toBe(true)
    expect(panelVisibility('inbound')['rippling-fairness-row']).toBe(false)
    expect(panelVisibility('catchment')['rippling-fairness-row']).toBe(false)
  })

  it('keeps the swingometer with the fairness controls it reads', () => {
    expect(panelVisibility('outbound')['rippling-stats']).toBe(true)
    expect(panelVisibility('inbound')['rippling-stats']).toBe(false)
  })

  it('keeps the ripple animation outbound, because it is a post spreading outwards', () => {
    expect(panelVisibility('outbound')['rippling-ripple-row']).toBe(true)
    expect(panelVisibility('outbound')['rippling-speed-row']).toBe(true)
    expect(panelVisibility('inbound')['rippling-ripple-row']).toBe(false)
    expect(panelVisibility('inbound')['rippling-speed-row']).toBe(false)
  })

  it('shows the layer toggles both ways round but hides the dead deprivation one inbound', () => {
    // The quintile polygons are clipped to what falls OUTSIDE the standard boundary, and
    // inbound runs at fairness 0, so there is nothing outside for the toggle to reveal.
    expect(panelVisibility('inbound')['rippling-layer-toggles']).toBe(true)
    expect(panelVisibility('inbound')['rippling-tog-quintiles-label']).toBe(
      false
    )
    expect(panelVisibility('outbound')['rippling-tog-quintiles-label']).toBe(
      true
    )
  })

  it('offers the place search on the reach tab, and the group picker on catchment', () => {
    expect(panelVisibility('inbound')['rippling-search-wrap']).toBe(true)
    expect(panelVisibility('outbound')['rippling-search-wrap']).toBe(true)
    expect(panelVisibility('catchment')['rippling-search-wrap']).toBe(false)
    expect(panelVisibility('catchment')['rippling-catchment-panel']).toBe(true)
    expect(panelVisibility('outbound')['rippling-catchment-panel']).toBe(false)
  })

  it('shows exactly one intro at a time', () => {
    const intros = [
      'rippling-intro-outbound',
      'rippling-intro-inbound',
      'rippling-intro-catchment',
    ]
    for (const view of ['catchment', 'outbound', 'inbound']) {
      const v = panelVisibility(view)
      expect(intros.filter((id) => v[id])).toHaveLength(1)
    }
  })

  it('drops the slider caption inbound, where it would describe the wrong limit', () => {
    // reachSliderHelp() talks about how far a POST travels. Inbound the band/cap line
    // is the explanation, and the two together contradict each other.
    expect(panelVisibility('inbound')['rippling-time-help']).toBe(false)
    expect(panelVisibility('outbound')['rippling-time-help']).toBe(true)
  })
})

describe('defaultReachMinutes', () => {
  it('opens outbound on the ceiling, whatever the pin measures', () => {
    // A post ripples to the ceiling regardless of the poster's own surroundings.
    expect(defaultReachMinutes('outbound', 20)).toBe(REACH_CEILING_MINUTES)
    expect(defaultReachMinutes('outbound', null)).toBe(REACH_CEILING_MINUTES)
  })

  it('opens catchment on the ceiling too, not on the last pin cap', () => {
    // Catchment shares the slider. Without its own answer here, leaving an inbound pin
    // in a city (cap 20) would redraw a group's catchment at 20 minutes on tab switch.
    expect(defaultReachMinutes('catchment', 20)).toBe(REACH_CEILING_MINUTES)
  })

  it('opens inbound on the measured cap, which IS the answer for that direction', () => {
    expect(defaultReachMinutes('inbound', 20)).toBe(20)
    expect(defaultReachMinutes('inbound', 30)).toBe(30)
  })

  it('rounds a fractional cap to a whole slider step', () => {
    expect(defaultReachMinutes('inbound', 29.4)).toBe(29)
  })

  it('never opens past the ceiling, which is where the slider stops', () => {
    // The slider max is the ceiling, so a larger cap would set a value the control
    // cannot represent and the reading would silently disagree with the position.
    expect(defaultReachMinutes('inbound', 90)).toBe(REACH_CEILING_MINUTES)
  })

  it('falls back to the ceiling while the band is still unmeasured', () => {
    expect(defaultReachMinutes('inbound', null)).toBe(REACH_CEILING_MINUTES)
    expect(defaultReachMinutes('inbound', 0)).toBe(REACH_CEILING_MINUTES)
    expect(defaultReachMinutes('inbound', NaN)).toBe(REACH_CEILING_MINUTES)
  })
})

describe('labels', () => {
  it('names the slider for what it limits in each view', () => {
    expect(reachSliderLabel('inbound')).toBe('How far you see posts from')
    expect(reachSliderLabel('outbound')).toBe('Maximum reach')
    // Catchment drives the same slider and means the outbound thing by it.
    expect(reachSliderLabel('catchment')).toBe('Maximum reach')
  })

  it('says what the group list means in each direction', () => {
    expect(groupsSectionTitle('inbound')).toBe(
      'Groups you would see posts from'
    )
    expect(groupsSectionTitle('outbound')).toBe('Freegle groups')
    expect(groupsSectionTitle('catchment')).toBe('Freegle groups')
  })
})
