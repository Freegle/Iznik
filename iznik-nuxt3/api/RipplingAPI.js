import BaseAPI from '@/api/BaseAPI'

export default class RipplingAPI extends BaseAPI {
  // Rippling-out live event counters for sysadmin (§15/§16). Support/Admin only.
  // Optional groupid scopes the reply/reuse KPIs to one origin group (0 = all);
  // start/end bound the headline KPI date range (default last 30 days server-side).
  fetchMetrics(groupid = 0, start = '', end = '') {
    const params = {}
    if (groupid) params.groupid = groupid
    if (start) params.start = start
    if (end) params.end = end
    return this.$getv2('/rippling/metrics', params)
  }

  // On-the-fly rippling analytics KPIs (§ sysadmin analytics tab). stratum =
  // all|rural|suburban|dense. Fast: pure-SQL KPIs only. The slow drive-time metrics are fetched
  // separately via fetchAnalyticsDriveTimes so this returns (and the tab renders) immediately.
  fetchAnalytics(stratum = 'all', start = '', end = '') {
    const params = { stratum }
    if (start) params.start = start
    if (end) params.end = end
    return this.$getv2('/rippling/analytics', params)
  }

  // The SLOW half of the analytics tab: the sampled drive-time routing pass (reply/rippled mean
  // drive-times, the per-day trend and the reliability bullseye). ~250 isochrone calls, so this
  // can take tens of seconds — callers fetch it after the KPIs and fill the drive panels in
  // progressively, with a long timeout.
  fetchAnalyticsDriveTimes(stratum = 'all', start = '', end = '') {
    const params = { stratum }
    if (start) params.start = start
    if (end) params.end = end
    return this.$getv2('/rippling/analytics/drivetime', params)
  }
}
