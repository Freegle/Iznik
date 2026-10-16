import BaseAPI from '@/api/BaseAPI'

// ModTools experiments API (Support/Admin only): the three routes in iznik-server-go/ux/experiments.go.
// See docs/developers/reference/ux-experiments.md.
//
// Every call passes false for logError. The site can go live before the Go routes, and a route that
// is not there yet must not reach Sentry. A 400 or 409 is a refusal the person who asked is shown.
export default class UxExperimentsAPI extends BaseAPI {
  /** Per funnel: the running experiments and the past ones (drafts are with the past ones). */
  async fetchExperiments(params = {}) {
    return await this.$getv2('/modtools/ux/experiments', params, false)
  }

  /** Add a draft experiment. Answers { id, status: 'draft' }. */
  async createExperiment(data) {
    return await this.$postv2('/modtools/ux/experiments', data, false)
  }

  /** Ask the batch to start, stop or close an experiment. data: { action, reason?, cleanup_pr? } */
  async experimentAction(id, data) {
    return await this.$patchv2(`/modtools/ux/experiments/${id}`, data, false)
  }
}
