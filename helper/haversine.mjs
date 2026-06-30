#!/usr/bin/env node
// haversine.mjs — compute great-circle distance (miles) from the offer to each
// replier, from API-provided lat/lng ONLY. This exists so the LLM never guesses
// distance from place names (the cardinal "no LLM geography" rule).
//
// Usage: node haversine.mjs <offerLat> <offerLng>  < people.json  > distances.json
//   people.json: [{ "userid": 123, "lat": 51.5, "lng": -0.1 }, ...]
//   output:      { "123": 4.27, ... }   (miles, 2 dp; omitted if lat/lng missing)

function miles(aLat, aLng, bLat, bLng) {
  const R = 3958.7613 // Earth radius in miles
  const toRad = (d) => (d * Math.PI) / 180
  const dLat = toRad(bLat - aLat)
  const dLng = toRad(bLng - aLng)
  const s =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toRad(aLat)) * Math.cos(toRad(bLat)) * Math.sin(dLng / 2) ** 2
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(s)))
}

const offerLat = parseFloat(process.argv[2])
const offerLng = parseFloat(process.argv[3])

let raw = ''
process.stdin.setEncoding('utf8')
process.stdin.on('data', (d) => (raw += d))
process.stdin.on('end', () => {
  let people = []
  try {
    people = JSON.parse(raw || '[]')
  } catch {
    people = []
  }
  const out = {}
  if (Number.isFinite(offerLat) && Number.isFinite(offerLng)) {
    for (const p of people) {
      const lat = parseFloat(p.lat)
      const lng = parseFloat(p.lng)
      // (0,0) is Freegle's "location unknown" sentinel — skip it.
      if (!Number.isFinite(lat) || !Number.isFinite(lng) || (lat === 0 && lng === 0)) continue
      out[String(p.userid)] = Math.round(miles(offerLat, offerLng, lat, lng) * 100) / 100
    }
  }
  process.stdout.write(JSON.stringify(out))
})
