import { defineStore } from 'pinia'
import { runHoldAware } from '~/api/heldConflict'
import api from '~/api'

export const useAdminsStore = defineStore('admins', {
  state: () => ({
    list: {},
  }),
  actions: {
    init(config) {
      this.config = config
      this.$api = api(config)
    },
    clear() {
      this.list = {}
    },
    clearAdmin(id) {
      delete this.list[id]
    },
    async fetch(params) {
      const data = await api(this.config).admins.fetch(params)
      if (params && params.id) {
        // Single admin fetch — V2 returns the admin object directly.
        this.list[params.id] = data
      } else {
        // List fetch — V2 returns a naked array.
        const admins = Array.isArray(data) ? data : data?.admins || []
        for (const admin of admins) {
          this.list[admin.id] = admin
        }
      }
    },
    async add(params) {
      const id = await api(this.config).admins.add(params)

      if (id && (params.template || params.editprotected)) {
        await api(this.config).admins.patch({
          id,
          template: params.template,
          editprotected: params.editprotected,
        })
      }
    },
    async approve(params) {
      // Go API binds `pending` to *bool; a numeric 0 here yields 400.
      await runHoldAware(
        () =>
          api(this.config).admins.patch({
            id: params.id,
            pending: false,
          }),
        () => this.fetch({ id: params.id })
      )
      await this.fetch({ id: params.id })
    },
    async edit(params) {
      await runHoldAware(
        () => api(this.config).admins.patch(params),
        () => this.fetch({ id: params.id })
      )
      await api(this.config).admins.fetch(params)
    },
    async delete(params) {
      await api(this.config).admins.del(params)
      this.clearAdmin(params.id)
    },
    async hold(params) {
      await runHoldAware(
        () => api(this.config).admins.hold(params.id),
        () => this.fetch({ id: params.id })
      )
      await this.fetch({ id: params.id })
    },
    async release(params) {
      await api(this.config).admins.release(params.id)
      await this.fetch({ id: params.id })
    },
  },
  getters: {
    get: (state) => (id) => {
      id = parseInt(id)
      return state.list[id] ? state.list[id] : null
    },
  },
})
