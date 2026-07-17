import BaseAPI from '@/api/BaseAPI'

export default class RecommendationsAPI extends BaseAPI {
  /**
   * Fetch the recommendation funnel (impressions → clicks → attributed replies)
   * per source plus the holdout comparison, for the sysadmin "Recommendations"
   * tab. Support/Admin only.
   * Returns { sources: [{ source, impressions, clicks, ctr, attributedReplies,
   * daily: [...] }], holdout: {...} }.
   * @param {number} days - window size in days (default 30 server-side)
   */
  async fetchStats(days) {
    return await this.$getv2(
      '/modtools/recommendations/stats',
      days ? { days } : {}
    )
  }
}
