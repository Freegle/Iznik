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
  // all|rural|suburban|dense. Drive-time metrics are sampled server-side, so this can take
  // ~10-20s — callers should show a spinner and allow a long timeout.
  fetchAnalytics(stratum = 'all', start = '', end = '') {
    const params = { stratum }
    if (start) params.start = start
    if (end) params.end = end
    return this.$getv2('/rippling/analytics', params)
  }
}
