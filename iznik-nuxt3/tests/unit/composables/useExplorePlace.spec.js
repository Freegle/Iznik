import { describe, it, expect } from 'vitest'
import {
  explorePlaceLocation,
  parseExplorePlace,
} from '~/composables/useExplorePlace'

const place = {
  id: -6747351,
  name: 'HA9 6BH, Wembley Stadium/Park',
  lat: 51.55,
  lng: -0.28,
  bbox: [
    [51.54, -0.29],
    [51.56, -0.27],
  ],
}

describe('explorePlaceLocation', () => {
  it('uses the named route with the place as one param', () => {
    const loc = explorePlaceLocation(place)

    expect(loc.name).toBe('explore-place-place')
    expect(JSON.parse(loc.params.place)).toEqual(place)
  })
})

describe('parseExplorePlace', () => {
  it('reads a place whose name holds a slash', () => {
    expect(parseExplorePlace(JSON.stringify(place))).toEqual(place)
  })

  it('reads a place that was encoded twice on its way to us', () => {
    const twice = encodeURIComponent(encodeURIComponent(JSON.stringify(place)))

    expect(parseExplorePlace(twice)).toEqual(place)
  })

  it.each([undefined, null, '', '%', '{"id":', 'plain text', '42', 'null'])(
    'gives null for %s',
    (param) => {
      expect(parseExplorePlace(param)).toBeNull()
    }
  )
})
