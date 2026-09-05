import BaseAPI from '@/api/BaseAPI'

export default class DrivingAPI extends BaseAPI {
  // Road drive time/distance from the logged-in member's approximate home to a
  // batch of points, answered by the routing server's reach engine in ONE call
  // (never fetch these serially - collect targets and batch). Targets the
  // engine cannot reach within 2 hours, or when the engine is unavailable,
  // come back with null mins/miles: show crow-flies instead.
  distances(targets) {
    return this.$postv2('/drivedistance', { targets })
  }
}
