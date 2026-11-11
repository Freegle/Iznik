import BaseAPI from '@/api/BaseAPI'

export default class JobAPI extends BaseAPI {
  async fetchv2(lat, lng, category) {
    // No need to log errors about this request - it often times out.
    const params = {
      lat,
      lng,
    }

    if (category) {
      params.category = category
    }
    return await this.$getv2('/job', params, false)
  }

  // tz is the browser's time zone, such as Europe/London. The email job page sends it so that a
  // browser whose clock is in the UK is never asked for an extra tap. It is left out when empty.
  async fetchOnev2(id, logError, tz) {
    return await this.$getv2('/job/' + id, tz ? { tz } : {}, logError)
  }

  async log(params) {
    await this.$postv2('/job', params)
  }
}
