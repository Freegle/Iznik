import { defineStore } from 'pinia'
import { nextTick } from 'vue'
import api from '~/api'

export const useGroupStore = defineStore('group', {
  state: () => ({
    list: {},
    summaryList: {},
    messages: {},
    allGroups: {},
    _remember: {},
  }),
  actions: {
    init(config) {
      this.config = config
      this.fetching = {}
    },
    clear() {
      this.list = {}
      this.summaryList = {}
      this.messages = {}
      this.allGroups = {}
      this._remember = {}
    },
    async fetchBatch(ids) {
      // Fetch multiple groups in one API call, chunked to avoid URL length
      // limits and the Go API's 50-ID cap.
      const CHUNK_SIZE = 25
      // Skip ids already cached with settings, or already in flight (from a per-id
      // fetch or a concurrent batch). The in-flight check is what stops duplicate
      // requests when a per-post MessageTag calls fetch(id) at the same moment.
      const needFetch = ids.filter(
        (id) =>
          (!this.list[id] || !this.list[id].settings) && !this.fetching[id]
      )
      if (needFetch.length === 0) return

      const chunks = []
      for (let i = 0; i < needFetch.length; i += CHUNK_SIZE) {
        chunks.push(needFetch.slice(i, i + CHUNK_SIZE))
      }

      await Promise.all(
        chunks.map(async (chunk) => {
          const promise = api(this.config).group.fetchBatch(chunk)
          // Mark every id in the chunk as fetching so a concurrent per-id fetch()
          // awaits this batch instead of firing its own /group/{id} request - the
          // race that otherwise left a heavy-membership feed with per-post group
          // calls even after we batched. fetch() already awaits this.fetching[id].
          chunk.forEach((id) => {
            this.fetching[id] = promise
          })

          try {
            const groups = await promise
            if (Array.isArray(groups)) {
              for (const group of groups) {
                this.list[group.id] = group
              }
            }
          } catch {
            // Batch endpoint failed for this chunk — fall back to individual
            // fetches. Clear the flags first so fetch() actually issues them.
            chunk.forEach((id) => {
              this.fetching[id] = null
            })
            await Promise.all(chunk.map((id) => this.fetch(id)))
            return
          }

          chunk.forEach((id) => {
            this.fetching[id] = null
          })
        })
      )
    },
    async fetch(id, force) {
      if (id) {
        if (isNaN(id)) {
          // Get by name.  Case-insensitive.
          id = id.toLowerCase()

          if (!this.allGroups[id]) {
            // Fetch all the groups.
            const groups = await api(this.config).group.list()
            console.log('Fetching all groups for name lookup:', id, groups)

            if (groups) {
              groups.forEach((g) => {
                this.allGroups[g.nameshort.toLowerCase()] = g
                this.summaryList[g.id] = g
              })
            }
          }

          if (!this.allGroups[id]) {
            return null
          }

          id = this.allGroups[id].id
        }

        id = parseInt(id)

        // If we don't have the settings, then we only have the copy of group data from list(), not the whole thing.
        if (force || !this.list[id] || !this.list[id].settings) {
          if (this.fetching[id]) {
            // Already fetching
            await this.fetching[id]
            await nextTick()
          } else {
            this.fetching[id] = api(this.config).group.fetch(
              id,
              function (data) {
                if (data && data.ret === 10) {
                  // Not hosting a group isn't worth logging.
                  return false
                } else {
                  return true
                }
              }
            )

            const group = await this.fetching[id]
            this.fetching[id] = null

            if (group) {
              this.list[group.id] = group
            }
          }
        }

        return this.list[id]
      } else {
        // Fetching all groups - store in summaryList (separate from the
        // full group cache in list) so summary data never overwrites
        // detailed data fetched via GET /group/{id}.
        const groups = await api(this.config).group.list()

        if (groups) {
          groups.forEach((g) => {
            this.allGroups[g.nameshort.toLowerCase()] = g
            this.summaryList[g.id] = g
          })
        }
      }
    },
    remember(id, val) {
      this._remember[id] = val
    },
    async fetchMessagesForGroup(id) {
      id = parseInt(id)
      const messages = await api(this.config).group.fetchMessagesForGroup(id)

      if (messages) {
        this.messages[id] = messages
      }
    },
    async addgroup(params) {
      const id = await api(this.config).group.add({
        grouptype: 'Freegle',
        action: 'Create',
        name: params.nameshort,
      })

      api(this.config).group.patch({
        id,
        namefull: params.namefull,
        publish: 0,
        polyofficial: params.cga,
        poly: params.dpa,
        lat: params.lat,
        lng: params.lng,
        onyahoo: 0,
        onhere: 1,
        ontn: 1,
        onlovejunk: 1,
        licenserequired: 0,
        showonyahoo: 0,
      })

      return id
    },
  },
  getters: {
    get: (state) => (idOrName) => {
      let ret = null

      if (!isNaN(idOrName)) {
        // Numeric - find by id. Prefer full data from list, fall back to summaryList.
        idOrName = parseInt(idOrName)
        return state.list[idOrName] || state.summaryList[idOrName] || null
      } else {
        // Not numeric - scan for match by name. Check list first, then summaryList.
        const lower = (idOrName + '').toLowerCase()

        for (const key of Object.keys(state.list)) {
          const group = state.list[key]
          if (group && group.nameshort.toLowerCase() === lower) {
            return group
          }
        }

        for (const key of Object.keys(state.summaryList)) {
          const group = state.summaryList[key]
          if (group && group.nameshort.toLowerCase() === lower) {
            ret = group
          }
        }
      }

      return ret
    },
    getMessages: (state) => (id) => {
      if (id in state.messages) {
        return state.messages[id]
      } else {
        return []
      }
    },
    remembered: (state) => (id) => state._remember[id],
  },
})
