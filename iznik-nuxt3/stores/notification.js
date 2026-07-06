import { defineStore } from 'pinia'
import api from '~/api'
import { APIError } from '~/api/APIErrors'

export const useNotificationStore = defineStore({
  id: 'notification',
  state: () => ({
    list: [],
    listById: {},
    count: 0,
  }),
  actions: {
    init(config) {
      this.config = config
    },
    async fetchCount() {
      try {
        const ret = await api(this.config).notification.count()
        this.count = ret?.count
        return this.count
      } catch (e) {
        console.log('Notification fetch failed', e)
        if (e instanceof APIError) {
          console.log('API error, status', e?.response?.status)

          if (e?.response?.status === 401) {
            // 401 can happen because of timing windows.  Don't want to log it to Sentry.
            console.log('Ignore unauthorised for notification count')
          } else {
            throw e
          }
        }
      }
    },
    async fetchList() {
      try {
        this.list = await api(this.config).notification.list()

        this.list.forEach((item) => {
          const existing = this.listById[item.id]
          if (existing) {
            // A notification's content is immutable, so we keep the same object
            // reference to avoid needless re-renders. But `seen` flips
            // false->true once the user reviews what the notification points at
            // (e.g. a microvolunteering "post to check"). Refresh it in place so
            // components rendering via byId() reflect the latest seen status
            // instead of the frozen unread state from the first fetch
            // (Discourse 9856).
            existing.seen = item.seen
          } else {
            this.listById[item.id] = item
          }
        })

        return this.list
      } catch (e) {
        // Mirror fetchCount error handling. Network failures (e.g. "Too many retries")
        // produce APIError with status null/"undefined" — swallow these since the
        // notification list is non-critical UI. Re-throw real API errors.
        if (e instanceof APIError) {
          if (e?.response?.status === 401) {
            console.log('Ignore unauthorised for notification list')
          } else if (e?.response?.status !== null) {
            throw e
          }
        }
      }
    },
    async seen(id) {
      await api(this.config).notification.seen(id)
      await this.fetchCount()
    },
    async allSeen(id) {
      await api(this.config).notification.allSeen(id)
      await this.fetchCount()
    },
  },
  getters: {
    byId: (state) => (id) => {
      return state.listById[id]
    },
  },
})
