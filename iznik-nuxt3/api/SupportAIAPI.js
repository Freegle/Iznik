import BaseAPI from '@/api/BaseAPI'

// Runs of the AI Support Helper and the volunteers' thumbs up/down on them.
export default class SupportAIAPI extends BaseAPI {
  fetchRuns(params) {
    return this.$getv2('/supportai/runs', params)
  }

  fetchRun(id) {
    return this.$getv2(`/supportai/runs/${id}`)
  }

  rate(id, rating, comment) {
    const data = { id, rating }
    if (comment !== undefined) data.comment = comment
    return this.$patchv2('/supportai/runs', data)
  }
}
