import BaseAPI from '@/api/BaseAPI'

export default class RipplingAPI extends BaseAPI {
  // Rippling-out live event counters for sysadmin (§15/§16). Support/Admin only.
  // Optional groupid scopes the reply/reuse KPIs to one origin group (0 = all).
  fetchMetrics(groupid = 0) {
    return this.$getv2('/rippling/metrics', groupid ? { groupid } : {})
  }
}
