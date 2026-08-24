import BaseAPI from '@/api/BaseAPI'

/**
 * The council sponsorship deals behind the ModTools Partnerships page: the deals themselves,
 * the groups each one covers, the money, and the queued generation of authority statistics
 * spreadsheets.
 */
export default class PartnershipsAPI extends BaseAPI {
  async list() {
    const { partnerships } = await this.$getv2('/partnership')
    return partnerships
  }

  async fetch(id) {
    return await this.$getv2('/partnership/' + id)
  }

  async summary() {
    const { summary } = await this.$getv2('/partnership/summary')
    return summary
  }

  async add(params) {
    const { id } = await this.$postv2('/partnership', params)
    return id
  }

  async edit(id, params) {
    await this.$patchv2('/partnership/' + id, params)
  }

  async remove(id) {
    await this.$delv2('/partnership/' + id)
  }

  async fetchGroups(id) {
    return await this.$getv2('/partnership/' + id + '/group')
  }

  async patchGroups(id, params) {
    return await this.$patchv2('/partnership/' + id + '/group', params)
  }

  async setYears(id, years) {
    await this.$putv2('/partnership/' + id + '/year', { years })
  }

  async addPayment(id, params) {
    const { id: paymentid } = await this.$postv2(
      '/partnership/' + id + '/payment',
      params
    )
    return paymentid
  }

  async editPayment(id, paymentid, params) {
    await this.$patchv2('/partnership/' + id + '/payment/' + paymentid, params)
  }

  async removePayment(id, paymentid) {
    await this.$delv2('/partnership/' + id + '/payment/' + paymentid)
  }

  async listStatsJobs() {
    const { jobs } = await this.$getv2('/partnership/statsjob')
    return jobs
  }

  async addStatsJob(params) {
    const { id } = await this.$postv2('/partnership/statsjob', params)
    return id
  }

  async removeStatsJob(id) {
    await this.$delv2('/partnership/statsjob/' + id)
  }
}
