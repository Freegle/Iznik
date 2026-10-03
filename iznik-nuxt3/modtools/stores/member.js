import { defineStore } from 'pinia'
import api from '~/api'
import { useAuthStore } from '~/stores/auth'
import { useUserStore } from '~/stores/user'

// Self-moderating rework: the per-group membership queue (approve/reject/
// reply/delete/hold/release, Pending/Spam/Edit-review collections) is gone —
// see the "Member tools contract" in briefs/modtools-rework.md. What's kept
// (member search-and-card: ban, posting status, notes, merge) now routes
// through ModMembersAPI.js (api().modmembers), which is national — no
// groupid anywhere in this file any more. Merge (askMerge/ignoreMerge) was
// already national via api().merge and is unchanged.
export const useMemberStore = defineStore('member', {
  state: () => ({
    list: {}, // id: member
    ratings: [],
  }),
  actions: {
    init(config) {
      this.config = config
    },
    clear() {
      this.list = {}
      this.ratings = []
    },
    async fetchMembers(params) {
      const { members, ratings } = await api(this.config).modmembers.fetch(
        params
      )
      members.forEach((member) => {
        this.list[member.id] = member
      })
      if (ratings && ratings.length) {
        this.ratings = ratings
      }
      return members.length
    },
    async fetch(id) {
      const { member } = await api(this.config).modmembers.fetchOne(id)
      this.list[member.id] = member
    },
    async ban(id, reason) {
      await api(this.config).modmembers.ban(id, reason)
    },
    async unban(id) {
      await api(this.config).modmembers.unban(id)
    },
    async setPostingStatus(id, postingstatus) {
      await api(this.config).modmembers.setPostingStatus(id, postingstatus)

      /*
       * No event tells the frontend about the write, so the cached
       * userStore entry for id keeps the pre-change value and any gate on
       * posting status (e.g. ModMessageButtons' :cantpost prop) keeps
       * failing on the next render (Discourse #10008 post 1). Force-refresh
       * the cached entry so the next render picks up the new value.
       */
      const userStore = useUserStore()
      await userStore.fetch(id, true)
    },
    async clearFlag(id) {
      await api(this.config).modmembers.clearFlag(id)
      const key = Object.keys(this.list).find(
        (k) => parseInt(this.list[k].id) === parseInt(id)
      )
      if (key) {
        delete this.list[key]
      }
    },
    async askMerge(id, params) {
      await api(this.config).merge.ask(params)
      delete this.list[id]
      const authStore = useAuthStore()
      if (
        authStore.work &&
        typeof authStore.work.relatedmembers === 'number' &&
        authStore.work.relatedmembers > 0
      ) {
        authStore.work.relatedmembers--
      }
    },
    async ignoreMerge(id, params) {
      await api(this.config).merge.ignore(params)
      delete this.list[id]
      const authStore = useAuthStore()
      if (
        authStore.work &&
        typeof authStore.work.relatedmembers === 'number' &&
        authStore.work.relatedmembers > 0
      ) {
        authStore.work.relatedmembers--
      }
    },
  },
  getters: {
    get: (state) => (id) => {
      return state.list[id]
    },
    ratingById: (state) => (id) => {
      return state.ratings.find((r) => parseInt(r.id) === parseInt(id))
    },
  },
})
