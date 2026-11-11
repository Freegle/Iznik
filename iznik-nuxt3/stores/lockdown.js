import { defineStore } from 'pinia'
import api from '~/api'

// Central state for the site-wide lockdown switch (see
// plans/active/2026-09-27-lockdown-switch.md section 11). Used by both the
// member site (just `notice`, via fetch()) and ModTools (the full state, via
// fetchMod()/fetchStats()/fetchHistory()/fetchHeld()/patch()).
export const useLockdownStore = defineStore('lockdown', {
  state: () => ({
    // The member site holds the public notice here as {text}, or null.
    // ModTools holds the notice text itself (fetchMod), or null.
    notice: null,

    // Moderator-only state, from GET /modtools/lockdown.
    active: false,
    incidentid: null,
    surfaces: {},
    reason: null,
    startedat: null,
    startedby: null,
    startedbyname: null,

    // Support/Admin only.
    stats: null,
    history: [],
  }),
  actions: {
    init(config) {
      this.config = config
    },

    async fetch() {
      const ret = await api(this.config).lockdown.fetch()
      this.notice = ret?.notice ?? null
      return ret
    },

    async fetchMod() {
      const ret = await api(this.config).lockdown.fetchMod()
      this.active = !!ret?.active
      this.incidentid = ret?.incidentid ?? null
      this.surfaces = ret?.surfaces ?? {}
      this.reason = ret?.reason ?? null
      this.notice = ret?.notice ?? null
      this.startedat = ret?.startedat ?? null
      this.startedby = ret?.startedby ?? null
      this.startedbyname = ret?.startedbyname ?? null
      return ret
    },

    async fetchStats() {
      const ret = await api(this.config).lockdown.fetchStats()
      this.stats = ret
      return ret
    },

    async fetchHistory() {
      // GET /modtools/lockdown/history returns a bare JSON array (newest
      // first, max 50), not an object wrapping one.
      const ret = await api(this.config).lockdown.fetchHistory()
      this.history = ret ?? []
      return ret
    },

    // One page of what is held: {items, next}. Not kept in the store - the
    // "What is held" subtab owns its own list, so it is only fetched when
    // somebody opens it.
    async fetchHeld(params) {
      const ret = await api(this.config).lockdown.fetchHeld(params)
      return { items: ret?.items ?? [], next: ret?.next ?? null }
    },

    // data carries an `action` field (press, surfaces, notice, liftall,
    // close) plus that action's own fields. Refreshes the full moderator
    // state afterwards so callers don't have to.
    async patch(data) {
      const ret = await api(this.config).lockdown.patch(data)
      await this.fetchMod()
      return ret
    },
  },
  getters: {
    // held(surface) = active AND surfaces[surface] - the same rule the Go API
    // uses server-side, so the client can decide what to hide without
    // waiting for a 409.
    held: (state) => (surface) => !!(state.active && state.surfaces?.[surface]),

    // The one surface every write path needs to check: is moderator action
    // itself held.
    modsHeld: (state) => !!(state.active && state.surfaces?.mods),
  },
})
