import BaseAPI from '@/api/BaseAPI'

export default class TownAPI extends BaseAPI {
  // Up to 5 town names the browse/feed distance slider reaches from (lat,lng), by travel
  // (drive-time), furthest-selected and ordered biggest-first. Names only - no distance/time units.
  fetchNear(lat, lng, miles) {
    return this.$getv2('/town/near', { lat, lng, miles })
  }
}
