import { defineStore } from 'pinia'
import { nextTick } from 'vue'
import api from '~/api'
import { useAuthStore } from '~/stores/auth'

// Rippling-out (nearby-reach flip): this store used to hold the member's own
// isochrone polygons for the "Nearby" browse view. There's no client-side
// polygon any more - the server works out reach itself and just returns the
// nearby posts - so this is now purely a store of those posts (messageList)
// plus a bounding box derived from them, so the map can frame itself around
// what's actually being shown.
export const useNearbyStore = defineStore({
  id: 'nearby',
  state: () => ({
    fetchingMessages: null,
    messageList: [],
  }),
  actions: {
    init(config) {
      this.config = config
    },
    async fetchMessages(force) {
      if (force || !this.messageList?.length) {
        if (this.fetchingMessages) {
          await this.fetchingMessages
          await nextTick()
        } else {
          // Pass the user's browse view so the list matches the count: 'mygroups' returns
          // member-group posts, anything else the nearby (reach-based) feed. The endpoint
          // is still /isochrone/message - that name is legacy, but the URL is kept.
          const browseView = useAuthStore().user?.settings?.browseView
          this.fetchingMessages = api(this.config).isochrone.fetchMessages(
            browseView ? { browseView } : undefined
          )
          this.messageList = await this.fetchingMessages
          this.fetchingMessages = null
        }
      }

      return this.messageList
    },
    markSeen(ids) {
      if (this.messageList?.length) {
        const idSet = new Set(ids)
        this.messageList = this.messageList.map((m) => {
          if (idSet.has(m.id)) {
            return { ...m, unseen: false }
          }
          return m
        })
      }
    },
  },
  getters: {
    // Bounding box of the nearby messages we've fetched, so PostMap can fit itself
    // around what's actually being shown rather than around a polygon. Null when we
    // have no messages (with coordinates) - the caller falls back to the member's
    // postcode in that case.
    bounds: (state) => {
      if (!state.messageList?.length) {
        return null
      }

      let swlat = null
      let swlng = null
      let nelat = null
      let nelng = null

      state.messageList.forEach((m) => {
        if (m.lat == null || m.lng == null) {
          return
        }

        swlat = swlat === null ? m.lat : Math.min(swlat, m.lat)
        swlng = swlng === null ? m.lng : Math.min(swlng, m.lng)
        nelat = nelat === null ? m.lat : Math.max(nelat, m.lat)
        nelng = nelng === null ? m.lng : Math.max(nelng, m.lng)
      })

      if (swlat === null) {
        return null
      }

      return [
        [swlat, swlng],
        [nelat, nelng],
      ]
    },

    // Map of message id -> server-computed distance (blurred great-circle miles from the
    // viewer) for the posts in the feed. The message-display composable looks distance up
    // by id so a card's badge shows the SAME distance the feed sorts and filters on,
    // instead of re-deriving it client-side from a different reference point. Rebuilt only
    // when messageList changes (Pinia getter caching); each lookup is then O(1).
    distanceById: (state) => {
      const map = new Map()
      for (const m of state.messageList || []) {
        if (m && m.id != null && Number.isFinite(m.distance)) {
          map.set(Number(m.id), m.distance)
        }
      }
      return map
    },

    // Set of ids of pinned posts (paid bulk-offer clearances the server floats to the
    // top). `pinned` is a feed-only flag (like distance, it isn't on the full message
    // record fetched by the card), so the display composable reads it back by id here to
    // decide whether to show the "Pinned" label.
    pinnedIds: (state) => {
      const ids = new Set()
      for (const m of state.messageList || []) {
        if (m && m.id != null && m.pinned) {
          ids.add(Number(m.id))
        }
      }
      return ids
    },
  },
})
