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

  // The SLOW half of the analytics tab is done in three steps so no single request runs long
  // enough to hit the gateway 504. Step 1: fetch the random SAMPLE of posts to score (fast, no
  // routing) — returns { posts, total }.
  fetchAnalyticsDriveTimes(stratum = 'all', start = '', end = '') {
    const params = { stratum }
    if (start) params.start = start
    if (end) params.end = end
    return this.$getv2('/rippling/analytics/drivetime', params)
  }

  // Step 2: score a chunk of the sample. One routing call per post, serial on the server. The
  // client calls this repeatedly (one chunk after another), showing progress — returns { obs }.
  scoreAnalyticsDriveTimes(posts) {
    return this.$postv2('/rippling/analytics/drivetime/score', { posts })
  }

  // Step 3: aggregate all the accumulated observations into the drive-time stats (reply/rippled
  // mean, per-day trend, reliability bullseye). Pure — no routing.
  aggregateAnalyticsDriveTimes(obs) {
    return this.$postv2('/rippling/analytics/drivetime/aggregate', { obs })
  }
}
