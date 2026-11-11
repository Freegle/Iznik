// The explore page for a searched place carries the place as JSON in the URL, in one path segment.
// A place name can hold a slash ("Wembley Stadium/Park"), which would split the path and match no
// route, so the place goes in as a route param and the router encodes it.

export function explorePlaceLocation(place) {
  return {
    name: 'explore-place-place',
    params: { place: JSON.stringify(place) },
  }
}

// The place from a route param, or null when it cannot be read. The router has already decoded the
// param once, so a link that was encoded twice on its way to us (some mail clients and chat apps do)
// arrives as "%7B..."; one that was encoded three times needs a second decode. Up to two more
// decodes are tried.
export function parseExplorePlace(param) {
  if (!param || typeof param !== 'string') {
    return null
  }

  let text = param

  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      const place = JSON.parse(text)
      return place && typeof place === 'object' ? place : null
    } catch (e) {
      try {
        text = decodeURIComponent(text)
      } catch (e2) {
        return null
      }
    }
  }

  return null
}
