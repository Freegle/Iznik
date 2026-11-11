import BaseAPI from '@/api/BaseAPI'

// ModTools UX telemetry read API (Support/Admin only). Every call takes
// { start, end } as YYYY-MM-DD, plus the extra filters noted below. See
// docs/developers/reference/ux-telemetry.md section 6.
export default class UxAPI extends BaseAPI {
  /** Per flow and step funnel figures. Optional params.flow. */
  async fetchFunnels(params = {}) {
    return await this.$getv2('/modtools/ux/funnels', params)
  }

  /** Per flow started/submitted/completed/abandoned. */
  async fetchOutcomes(params = {}) {
    return await this.$getv2('/modtools/ux/outcomes', params)
  }

  /** Per field revisits, validation failures, choice switches. Optional params.flow. */
  async fetchFields(params = {}) {
    return await this.$getv2('/modtools/ux/fields', params)
  }

  /** Dead and rage clicks per component. */
  async fetchFriction(params = {}) {
    return await this.$getv2('/modtools/ux/friction', params)
  }

  /** Feature adoption: using users over active users. */
  async fetchAdoption(params = {}) {
    return await this.$getv2('/modtools/ux/adoption', params)
  }

  /** Regret signals per day. */
  async fetchRegret(params = {}) {
    return await this.$getv2('/modtools/ux/regret', params)
  }

  /** Search and browse behaviour per day. */
  async fetchSearch(params = {}) {
    return await this.$getv2('/modtools/ux/search', params)
  }

  /** Whether the instrumentation itself is delivering, per metric and day. */
  async fetchHealth(params = {}) {
    return await this.$getv2('/modtools/ux/health', params)
  }

  /** Sampled abandoned sessions. params: date, flow, step. */
  async fetchSamples(params = {}) {
    return await this.$getv2('/modtools/ux/samples', params)
  }
}
