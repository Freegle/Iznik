import { defineStore } from 'pinia'
import api from '~/api'

/**
 * State for the ModTools Partnerships page: the council sponsorship deals, the totals and
 * financial-year split behind the income graph, and the queued authority statistics runs.
 */
export const usePartnershipsStore = defineStore({
  id: 'partnerships',
  state: () => ({
    list: [],
    // Keyed by partnership id: { partnership, groups, years, payments }.
    detail: {},
    summary: null,
    statsJobs: [],
  }),
  actions: {
    init(config) {
      this.config = config
    },
    async fetch() {
      this.list = await api(this.config).partnerships.list()
      return this.list
    },
    async fetchSummary() {
      this.summary = await api(this.config).partnerships.summary()
      return this.summary
    },
    async fetchOne(id) {
      const ret = await api(this.config).partnerships.fetch(id)
      this.detail[id] = ret
      return ret
    },
    async add(params) {
      const id = await api(this.config).partnerships.add(params)
      await this.refresh()
      return id
    },
    async edit(id, params) {
      await api(this.config).partnerships.edit(id, params)
      await this.fetchOne(id)
      await this.refresh()
    },
    async remove(id) {
      await api(this.config).partnerships.remove(id)
      delete this.detail[id]
      await this.refresh()
    },
    async fetchGroups(id) {
      return await api(this.config).partnerships.fetchGroups(id)
    },
    async addGroup(id, groupid) {
      await api(this.config).partnerships.patchGroups(id, {
        action: 'Add',
        groupid,
      })
      await this.fetchOne(id)
    },
    async removeGroup(id, groupid) {
      await api(this.config).partnerships.patchGroups(id, {
        action: 'Remove',
        groupid,
      })
      await this.fetchOne(id)
    },
    async redetectGroups(id) {
      await api(this.config).partnerships.patchGroups(id, {
        action: 'Redetect',
      })
      await this.fetchOne(id)
    },
    async setYears(id, years) {
      await api(this.config).partnerships.setYears(id, years)
      await this.fetchOne(id)
      await this.fetchSummary()
    },
    async addPayment(id, params) {
      const paymentid = await api(this.config).partnerships.addPayment(
        id,
        params
      )
      await this.fetchOne(id)
      await this.refresh()
      return paymentid
    },
    async editPayment(id, paymentid, params) {
      await api(this.config).partnerships.editPayment(id, paymentid, params)
      await this.fetchOne(id)
      await this.refresh()
    },
    async removePayment(id, paymentid) {
      await api(this.config).partnerships.removePayment(id, paymentid)
      await this.fetchOne(id)
      await this.refresh()
    },
    // The list and the headline figures always move together, so refresh them as a pair.
    async refresh() {
      await this.fetch()
      await this.fetchSummary()
    },
    async fetchStatsJobs() {
      this.statsJobs = await api(this.config).partnerships.listStatsJobs()
      return this.statsJobs
    },
    async addStatsJob(params) {
      const id = await api(this.config).partnerships.addStatsJob(params)
      await this.fetchStatsJobs()
      return id
    },
    async removeStatsJob(id) {
      await api(this.config).partnerships.removeStatsJob(id)
      await this.fetchStatsJobs()
    },
  },
  getters: {
    byId: (state) => (id) => state.detail[id] || null,
    // Deals within three months of their end date, soonest first - the renewal worklist.
    expiring: (state) =>
      state.list
        .filter((p) => p.expiring)
        .sort((a, b) => a.enddate.localeCompare(b.enddate)),
    // Anything still generating, so the page knows to keep polling.
    statsRunning: (state) =>
      state.statsJobs.some(
        (j) => j.status === 'Pending' || j.status === 'Running'
      ),
  },
})
