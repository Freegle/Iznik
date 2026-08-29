import BaseAPI from '@/api/BaseAPI'

export default class ElectricalsAPI extends BaseAPI {
  // Rolling statistics for electrical reuse, generated on a schedule by the
  // Laravel command electricals:stats. Returns 404 before the first
  // generation - that is expected, not an error worth a Sentry alert, so the
  // logError callback stays quiet for it and loud for anything else.
  async stats() {
    return await this.$getv2(
      '/electricals/stats',
      {},
      (data) => !data?.message?.includes('generated yet')
    )
  }
}
