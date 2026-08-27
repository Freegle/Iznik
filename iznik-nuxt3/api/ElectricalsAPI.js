import BaseAPI from '@/api/BaseAPI'

export default class ElectricalsAPI extends BaseAPI {
  // Rolling twelve-month statistics for electrical reuse, generated on a schedule by
  // the Laravel command electricals:stats. Returns 404 before the first generation.
  async stats() {
    return await this.$getv2('/electricals/stats')
  }
}
