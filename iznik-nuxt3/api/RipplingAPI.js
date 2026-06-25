import BaseAPI from '@/api/BaseAPI'

export default class RipplingAPI extends BaseAPI {
  // Rippling-out live event counters for sysadmin (§15/§16). Support/Admin only.
  // Optional groupid scopes the reply/reuse KPIs to one origin group (0 = all);
  // start/end bound the headline KPI date range (default last 30 days server-side).
  // trialOnly scopes every KPI to the RIPPLE_WITHIN_GROUPS trial set so the trial
  // signal isn't buried by the majority of groups that aren't rippling yet.
  fetchMetrics(groupid = 0, start = '', end = '', trialOnly = false) {
    const params = {}
    if (groupid) params.groupid = groupid
    if (start) params.start = start
    if (end) params.end = end
    if (trialOnly) params.trialOnly = 1
    return this.$getv2('/rippling/metrics', params)
  }
}
