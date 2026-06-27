import BaseAPI from '@/api/BaseAPI'

export default class BrowseAPI extends BaseAPI {
  /**
   * Fetch the browse-feed scroll-depth curve for the sysadmin "Scrolling" tab.
   * Returns { total, positions: [{ position, sessions_reaching, pct }] }.
   * @param {Object} params - { start, end } as YYYY-MM-DD (defaults to last 7 days server-side)
   */
  async fetchScrollDepth(params = {}) {
    return await this.$getv2('/modtools/scroll/depth', params)
  }
}
