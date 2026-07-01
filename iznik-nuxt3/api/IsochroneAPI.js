import BaseAPI from '@/api/BaseAPI'

export default class IsochroneAPI extends BaseAPI {
  // Backs the Nearby feed (and the mygroups feed) via stores/nearby.js. This is the
  // only isochrone endpoint the client still calls: the isochrone-polygon CRUD methods
  // (add/list/patch/delete) were removed when the isochrone editor was deleted in the
  // rippling-out reach flip (PR #921). The server /isochrone CRUD routes remain (marked
  // deprecated) for backward compatibility with older cached clients.
  fetchMessages(params) {
    return this.$getv2('/isochrone/message', params)
  }
}
