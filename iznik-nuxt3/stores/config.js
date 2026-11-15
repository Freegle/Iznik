import { defineStore } from 'pinia'
import { nextTick } from 'vue'
import api from '~/api'

export const useConfigStore = defineStore('config', {
  state: () => ({
    list: {},
  }),
  actions: {
    init(config) {
      this.config = config
      this.fetching = {}
    },
    async fetch(key, force) {
      const now = Math.round(Date.now() / 1000)
      const expired = this.list[key]?.addedToCache
        ? now - this.list[key].addedToCache > 600
        : false

      if (force || expired || !this.list[key]) {
        if (this.fetching[key]) {
          await this.fetching[key]
          await nextTick()
        } else {
          this.fetching[key] = api(this.config).config.fetchv2(key, false)
          let ret

          try {
            ret = await this.fetching[key]
          } catch (e) {
            // Forget the failed request so the next fetch tries again, rather than
            // re-awaiting the same rejected promise for the rest of the session.
            this.fetching[key] = null
            throw e
          }

          this.list[key] = {
            values: ret,
            addedToCache: Math.round(Date.now() / 1000),
          }

          this.fetching[key] = null
        }
      }

      return this.list[key].values
    },
  },
  getters: {
    byKey: (state) => {
      return (key) => state.list[key].values
    },
  },
})
