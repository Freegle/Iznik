import BaseAPI from '@/api/BaseAPI'

export default class RipplingAPI extends BaseAPI {
  // Rippling-out live event counters for sysadmin (§15/§16). Support/Admin only.
  fetchMetrics() {
    return this.$getv2('/rippling/metrics')
  }
}
