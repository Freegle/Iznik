import BaseAPI from '@/api/BaseAPI'

export default class FirstReplyAPI extends BaseAPI {
  // First-reply effectiveness for sysadmin, per lever (passthrough, match mail,
  // Freegle chat prompts). Support/Admin only. start/end bound the window and
  // default to the last 30 days server-side.
  fetchMetrics(start = '', end = '') {
    const params = {}
    if (start) params.start = start
    if (end) params.end = end
    return this.$getv2('/firstreply/metrics', params)
  }
}
