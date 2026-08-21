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

// Each lane gets its own colour, because which lane admitted somebody is the question a
// moderator asks next: a band ring means the poster's own cap bound and rural members
// were let back in, a wedge means the post was too small to bind the cap and was aimed
// at a town instead. Same hue family per lane so the map reads as one thing.
const LANE_STYLE = {
  rural: { color: '#c2410c', label: 'Rural ring' },
  fairness: { color: '#7c3aed', label: 'Deprivation ring' },
  cluster: { color: '#0f766e', label: 'Cluster wedge' },
}

// A lane key is "family.variant" — rural.sparse, cluster.w1, fairness.2.
export function laneStyle(key) {
  const family = String(key || '').split('.')[0]
  return LANE_STYLE[family] || { color: '#525252', label: 'Ring' }
}

// A human name for one lane, for the tooltip: "Rural ring (sparse)".
export function laneLabel(key) {
  const [, variant] = String(key || '').split('.')
  const { label } = laneStyle(key)
  return variant ? `${label} (${variant})` : label
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
        .bindTooltip(`${laneLabel(key)} — reaches members beyond the reach`, {
          sticky: true,
        })
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
