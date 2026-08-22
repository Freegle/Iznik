// The overflow RINGS on the per-post reach map: the lanes that admit members the
// committed reach does not cover.
//
// Without them the map under-reports where a post went, and it does so for exactly the
// posts whose moderators are most likely to be asking. A Hawes post's reach outline
// stops in the dale; two cluster wedges carry it to Penrith and Lancaster, the mail
// invites those members, and browse shows them the post. "Did this get to X?" answered
// from the outline alone is wrong whenever X is in a ring.
//
// Drawn as outlines over the reach rather than filled areas: the reach is the subject
// and the rings are additions to it, so they read as annotations on the same map. Takes
// L and the map as arguments (like actualreach.js) so the update is unit-testable with
// stubs.
import { geoJsonToLatLngs } from './polygon.js'

// Each lane gets its own colour and its own plain-English name, because the moderator's
// next question is why a particular patch is included - and the answer differs by lane.
//
// The names are deliberately NOT ours. "Rural ring", "cluster wedge" and "deprivation
// fifth" are how the engine thinks; a moderator wants to know who can see the post and
// why, in the words they would use themselves.
const LANE_STYLE = {
  rural: {
    color: '#c2410c',
    label: 'Countryside — people who travel further',
    why: 'People out here can see this post and reply to it. Their area is thinly populated, so they are allowed a longer journey than this post normally covers.',
  },
  fairness: {
    color: '#7c3aed',
    label: 'Extra area, to even things out',
    why: 'People out here can see this post and reply to it. This area is included so that places which would otherwise see fewer posts get more of them.',
  },
  cluster: {
    color: '#0f766e',
    label: 'Road to the nearest town',
    why: 'People along here can see this post and reply to it. The post could not reach many people nearby, so it was carried along the road to the nearest town with enough freeglers in it.',
  },
}

// A lane key is "family.variant" — rural.sparse, cluster.w1, fairness.2.
export function laneStyle(key) {
  const family = String(key || '').split('.')[0]
  return LANE_STYLE[family] || { color: '#525252', label: 'Ring' }
}

// The plain-English name for a lane. The variant (sparse, w1, the quintile number) is
// deliberately dropped: it tells a moderator nothing they can act on, and three wedges
// labelled w1/w2/w3 read as a system's internals leaking onto a map.
export function laneLabel(key) {
  return laneStyle(key).label
}

// One sentence saying who is in this outline and why, for the tooltip.
export function laneExplanation(key) {
  return laneStyle(key).why || 'People here can see this post and reply to it.'
}

// Replace `existing` layers with ones drawn from `rings` — the { lane: geojson } map
// from /message/{id}/reach. Returns the new layers (always an array, empty when there is
// nothing to draw), so the caller can remove them next time without tracking each lane.
export function updateOverflowRingLayers(L, map, existing, rings) {
  ;(existing || []).forEach((layer) => {
    if (map && map.hasLayer(layer)) map.removeLayer(layer)
  })

  if (!rings || !map) return []

  return Object.keys(rings)
    .sort()
    .map((key) => {
      const latlngs = geoJsonToLatLngs(rings[key])
      if (!latlngs) return null
      const { color } = laneStyle(key)
      return L.polygon(latlngs, {
        color,
        weight: 2,
        dashArray: '5 4',
        fill: true,
        fillColor: color,
        fillOpacity: 0.1,
      })
        .bindTooltip(laneExplanation(key), { sticky: true })
        .addTo(map)
    })
    .filter(Boolean)
}

// Which lane families this post actually carries, for the legend. A post has at most one
// of rural/fairness (they need opposite things of the audience cap) plus any wedges, so
// this is one or two entries, not a fixed key.
export function ringLegend(rings) {
  if (!rings) return []
  const seen = new Map()
  Object.keys(rings).forEach((key) => {
    const { color, label } = laneStyle(key)
    if (!seen.has(label)) seen.set(label, color)
  })
  return [...seen].map(([label, color]) => ({ label, color }))
}
