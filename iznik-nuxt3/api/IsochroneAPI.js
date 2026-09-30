import BaseAPI from '@/api/BaseAPI'

export default class IsochroneAPI extends BaseAPI {
  // Backs the Nearby feed (and the mygroups feed) via stores/nearby.js. This is the
  // only isochrone endpoint; the per-member isochrone CRUD routes are gone.
  fetchMessages(params) {
    return this.$getv2('/isochrone/message', params)
  }
}
