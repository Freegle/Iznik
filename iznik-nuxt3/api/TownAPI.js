import BaseAPI from '@/api/BaseAPI'

export default class TownAPI extends BaseAPI {
  // Up to 5 town names reachable within the slider's travel time (minutes) from (lat,lng), by travel
  // (drive-time), furthest-selected and ordered biggest-first. Names only - no distance/time units.
  // Also returns reach_radius_miles (the crow-flies radius that travel time reaches) for the client
  // to store as settings.browseMaxDistance.
  fetchNear(lat, lng, minutes) {
    return this.$getv2('/town/near', { lat, lng, minutes })
  }
}
