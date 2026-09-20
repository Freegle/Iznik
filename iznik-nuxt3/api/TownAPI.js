import BaseAPI from '@/api/BaseAPI'

export default class TownAPI extends BaseAPI {
  // Up to 5 town names reachable within the slider's travel time (minutes) from (lat,lng), by travel
  // (drive-time), furthest-selected and ordered biggest-first. Names only - no distance/time units.
  // Also returns reach_radius_miles (the crow-flies radius that travel time reaches) for the client
  // to store as settings.browseMaxDistance.
  //
  // `polygon` additionally returns reach_polygon: the outline of that same travel time, for the
  // browse map to shade. Off by default because it costs the routing server a boundary trace, and
  // callers that only want the radius (Feed settings) never draw it.
  fetchNear(lat, lng, minutes, polygon = false) {
    const params = { lat, lng, minutes }
    if (polygon) {
      params.polygon = 1
    }
    return this.$getv2('/town/near', params)
  }
}
