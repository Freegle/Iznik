import { defineStore } from 'pinia'
import api from '~/api'

export const useAlertStore = defineStore('alert', {
  state: () => ({
    list: {},
    pending: [],
  }),
  actions: {
    init(config) {
      this.config = config
      this.$api = api(config)
    },
    clear() {
      this.list = {}
    },
    async fetch(params) {
      const { alerts, alert } = await api(this.config).alert.fetch(params)
      if (alerts) {
        for (const analert of alerts) {
          this.list[analert.id] = analert
        }
      } else {
        this.list[alert.id] = alert
      }
    },
    async add(params) {
      const { id } = await api(this.config).alert.add(params)
      await this.fetch({ id })
      return id
    },
    async fetchPending() {
      const { alerts } = await api(this.config).alert.pending()
      this.pending = alerts || []
      return this.pending
    },
    async reply(id, message) {
      await api(this.config).alert.reply(id, message)
      this.pending = this.pending.filter((a) => a.id !== id)
    },
    async snooze(id) {
      await api(this.config).alert.snooze(id)
      this.pending = this.pending.filter((a) => a.id !== id)
    },
    async cancel(id) {
      await api(this.config).alert.cancel(id)
      if (this.list[id]) {
        this.list[id].cancelled = new Date().toISOString()
      }
    },
    async record(params) {
      await api(this.config).alert.record({
        trackid: params.id,
        action: 'clicked',
      })
    },
  },
  getters: {
    get: (state) => (id) => {
      id = parseInt(id)
      return state.list[id] ? state.list[id] : null
    },
  },
})
