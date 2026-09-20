// Which parts of the explorer's panel belong to which view, and what the reach slider
// means in each direction.
//
// Split out of setupRipplingExplorer because that file owns a Leaflet map and cannot be
// unit tested, while these are the decisions most likely to go wrong when a control
// moves: a row left visible in the wrong view doesn't throw, it just quietly offers a
// knob that does nothing (or worse, one that implies a model we don't run).
//
// The explorer has two TABS - the group catchment, and one "reach from a place" tab that
// answers both directions for a single pin - but three VIEWS, because the drawing code
// branches on direction. viewMode carries the three; the tab is derived from it.

import { REACH_CEILING_MINUTES } from './reachmodel.js'

/** Every panel element the view toggle governs, so a caller can't miss one. */
export const PANEL_IDS = Object.freeze([
  'rippling-direction',
  'rippling-fairness-row',
  'rippling-stats',
  'rippling-ripple-row',
  'rippling-speed-row',
  'rippling-layer-toggles',
  'rippling-tog-quintiles-label',
  'rippling-search-wrap',
  'rippling-intro-outbound',
  'rippling-intro-inbound',
  'rippling-intro-catchment',
  'rippling-catchment-panel',
  'rippling-time-help',
])

/**
 * Show/hide map for the panel, keyed by element id.
 *
 * @param {string} viewMode 'catchment' | 'outbound' | 'inbound'
 * @returns {Object<string, boolean>} true = shown
 */
export function panelVisibility(viewMode) {
  const catchment = viewMode === 'catchment'
  const inbound = viewMode === 'inbound'
  const outbound = viewMode === 'outbound'
  const reach = !catchment

  return {
    'rippling-direction': reach,
    // Fairness widens a POST's targeting into deprived areas. Inbound is bounded by the
    // member's own cap, which fairness does not move, so offering the slider there would
    // imply a model we don't run.
    'rippling-fairness-row': outbound,
    // The swingometer reads that same targeting against the area's natural mix.
    'rippling-stats': outbound,
    // The ripple animation is a post spreading outwards over time.
    'rippling-ripple-row': outbound,
    'rippling-speed-row': outbound,
    'rippling-layer-toggles': reach,
    // The quintile polygons are clipped to what lies OUTSIDE the standard boundary -
    // they exist to show the fairness bonus - and inbound runs at fairness 0, so this
    // toggle would switch nothing on and off.
    'rippling-tog-quintiles-label': outbound,
    // Catchment is picked by group, not by point.
    'rippling-search-wrap': reach,
    'rippling-intro-outbound': outbound,
    'rippling-intro-inbound': inbound,
    'rippling-intro-catchment': catchment,
    'rippling-catchment-panel': catchment,
    // The slider caption talks about how far a POST travels; inbound the band/cap line
    // below it is the explanation instead.
    'rippling-time-help': outbound,
  }
}

/**
 * The reach to open a direction on.
 *
 * Inbound defaults to the pin's measured cap, because that IS the answer to "whose posts
 * can I see"; the ceiling only stands in while the band is still unknown. Outbound is
 * always the ceiling - that is how far a post ripples, whatever the poster's own area.
 *
 * @param {string} direction 'outbound' | 'inbound'
 * @param {number|null} capMinutes cap_minutes from /town/near, or null if unmeasured
 */
export function defaultReachMinutes(direction, capMinutes) {
  if (direction !== 'inbound') return REACH_CEILING_MINUTES
  return Number.isFinite(capMinutes) && capMinutes > 0
    ? Math.min(Math.round(capMinutes), REACH_CEILING_MINUTES)
    : REACH_CEILING_MINUTES
}

/**
 * What the reach slider is called in each view.
 *
 * Only inbound renames it: there the slider is the member's own cap, not how far a post
 * travels, and calling that "maximum reach" invites the reading that a post from here
 * stops at 20 minutes because the person standing here happens to be in a city.
 */
export function reachSliderLabel(viewMode) {
  return viewMode === 'inbound' ? 'How far you see posts from' : 'Maximum reach'
}

/** Heading for the group list, which means a different thing in each direction. */
export function groupsSectionTitle(direction) {
  return direction === 'inbound'
    ? 'Groups you would see posts from'
    : 'Freegle groups'
}
