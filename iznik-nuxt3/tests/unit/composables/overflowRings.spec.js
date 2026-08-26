import { describe, it, expect, vi } from 'vitest'
import {
  updateOverflowRingLayers,
  ringLegend,
  laneLabel,
  laneExplanation,
  laneStyle,
} from '~/modtools/composables/rippling/overflowrings.js'

// A Leaflet stub: records what was drawn without needing a DOM or a map.
function stubs() {
  const layers = []
  const L = {
    polygon: vi.fn((latlngs, opts) => {
      const layer = {
        latlngs,
        opts,
        tooltip: null,
        bindTooltip(t) {
          this.tooltip = t
          return this
        },
        addTo(m) {
          m.added.push(this)
          return this
        },
      }
      layers.push(layer)
      return layer
    }),
  }
  const map = {
    added: [],
    removed: [],
    hasLayer: (l) => map.added.includes(l) && !map.removed.includes(l),
    removeLayer: (l) => map.removed.push(l),
  }
  return { L, map, layers }
}

const RING = JSON.stringify({
  type: 'Polygon',
  coordinates: [
    [
      [-2.8, 54.5],
      [-2.6, 54.5],
      [-2.6, 54.7],
      [-2.8, 54.7],
      [-2.8, 54.5],
    ],
  ],
})

describe('overflow ring layers', () => {
  it('draws one layer per lane the post carries', () => {
    const { L, map } = stubs()

    const drawn = updateOverflowRingLayers(L, map, [], {
      'rural.sparse': RING,
      'cluster.w1': RING,
    })

    expect(drawn).toHaveLength(2)
    expect(map.added).toHaveLength(2)
  })

  it('gives each lane family its own colour, because which lane admitted someone is the next question', () => {
    const { L, map } = stubs()

    const drawn = updateOverflowRingLayers(L, map, [], {
      'rural.sparse': RING,
      'cluster.w1': RING,
    })

    const colours = drawn.map((l) => l.opts.color)
    expect(new Set(colours).size).toBe(2)
    expect(colours).toContain(laneStyle('rural.sparse').color)
    expect(colours).toContain(laneStyle('cluster.w1').color)
  })

  // A moderator hovering an outline wants to know who is in it and why it is there,
  // in words they would use themselves - not the lane's name in our vocabulary.
  it('explains on hover who is in the outline and why', () => {
    const { L, map } = stubs()

    const [layer] = updateOverflowRingLayers(L, map, [], {
      'rural.sparse': RING,
    })

    expect(layer.tooltip).toContain('can see this post and reply to it')
    expect(layer.tooltip).toMatch(/thinly populated|longer journey/)
    expect(layer.tooltip).not.toMatch(/sparse|lane|ring\b/i)
  })

  // The reach is the subject and the rings are additions to it, so they must not paint
  // over it - a filled ring would hide the very outline a moderator came to look at.
  it('draws rings as light outlines, not solid fills', () => {
    const { L, map } = stubs()

    const [layer] = updateOverflowRingLayers(L, map, [], { 'cluster.w1': RING })

    expect(layer.opts.fillOpacity).toBeLessThanOrEqual(0.15)
    expect(layer.opts.dashArray).toBeTruthy()
  })

  it('removes the previous layers before drawing again, so a redraw does not stack', () => {
    const { L, map } = stubs()

    const first = updateOverflowRingLayers(L, map, [], { 'rural.sparse': RING })
    updateOverflowRingLayers(L, map, first, { 'cluster.w1': RING })

    expect(map.removed).toEqual(first)
  })

  // Most posts carry no rings at all, and the map must be untouched for them.
  it('draws nothing when the post has no rings', () => {
    const { L, map } = stubs()

    expect(updateOverflowRingLayers(L, map, [], null)).toEqual([])
    expect(L.polygon).not.toHaveBeenCalled()
  })

  it('skips a lane whose geometry will not parse rather than losing the others', () => {
    const { L, map } = stubs()

    const drawn = updateOverflowRingLayers(L, map, [], {
      'rural.sparse': 'not geojson',
      'cluster.w1': RING,
    })

    expect(drawn).toHaveLength(1)
    expect(drawn[0].opts.color).toBe(laneStyle('cluster.w1').color)
  })
})

describe('ring legend', () => {
  // Three wedges are one idea to a moderator - the post was carried toward town -
  // so the legend says it once rather than listing w1, w2 and w3.
  it('names one entry per lane family, not per lane', () => {
    const legend = ringLegend({
      'cluster.w1': RING,
      'cluster.w2': RING,
      'cluster.w3': RING,
    })

    expect(legend).toHaveLength(1)
    expect(legend[0].label).toBe('Road to the nearest town')
  })

  it('is empty for a post with no rings, so the caption stays as it was', () => {
    expect(ringLegend(null)).toEqual([])
    expect(ringLegend({})).toEqual([])
  })

  // The labels are what a moderator reads on the map, so they say who is included
  // rather than which mechanism included them - and they never leak the variant, which
  // is meaningless outside the engine ("w1", "sparse", a quintile number).
  it('names lanes in words a moderator would use', () => {
    expect(laneLabel('rural.medium')).toBe(
      'Countryside — people who travel further'
    )
    expect(laneLabel('cluster.w1')).toBe('Road to the nearest town')
    expect(laneLabel('fairness.2')).toBe('Extra area, to even things out')
  })

  it('keeps our own vocabulary off the map', () => {
    const shown = [
      laneLabel('rural.sparse'),
      laneLabel('cluster.w2'),
      laneLabel('fairness.1'),
      laneExplanation('rural.sparse'),
      laneExplanation('cluster.w1'),
      laneExplanation('fairness.1'),
    ].join(' ')

    expect(shown).not.toMatch(
      /wedge|overflow|lane|deprivation|quintile|cluster|w1|w2/i
    )
  })
})
