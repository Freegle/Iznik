import BaseAPI from '@/api/BaseAPI'

// ModTools member-survey read API (Support/Admin only): the four routes in iznik-server-go/ux/surveys.go.
// See docs/developers/reference/ux-member-surveys.md.
//
// Every call passes false for logError. The site can go live before the Go routes, and a route that is
// not there yet must not reach Sentry: the section says that this part is not switched on.
export default class UxSurveysAPI extends BaseAPI {
  /** The headings problems are filed under, each with its size and biggest problem. params: flow, q. */
  async fetchProblemGroups(params = {}) {
    return await this.$getv2('/modtools/ux/problem-groups', params, false)
  }

  /** The problems, with the quotes and suggestions. params: group, flow, q, limit. */
  async fetchProblems(params = {}) {
    return await this.$getv2('/modtools/ux/problems', params, false)
  }

  /** What members said they value about Freegle. */
  async fetchValues() {
    return await this.$getv2('/modtools/ux/values', {}, false)
  }

  /** Each question that was asked, with how it did. params: kind, status. */
  async fetchQuestions(params = {}) {
    return await this.$getv2('/modtools/ux/questions', params, false)
  }
}
