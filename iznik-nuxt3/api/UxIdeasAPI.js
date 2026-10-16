import BaseAPI from '@/api/BaseAPI'

// ModTools ideas API (Support/Admin only): the one route in iznik-server-go/ux/ideas.go. See
// docs/developers/reference/ux-ideas.md.
//
// The call passes false for logError. The site can go live before the Go route, and a route that is
// not there yet must not reach Sentry.
export default class UxIdeasAPI extends BaseAPI {
  /**
   * The ideas, best score first, with the totals by status and a summary for each funnel. The params
   * are optional: flow, status, type, problem_id and limit.
   */
  async fetchIdeas(params = {}) {
    return await this.$getv2('/modtools/ux/ideas', params, false)
  }
}
