import { defineStore } from 'pinia'
import api from '~/api'

// Central state for the site-wide lockdown switch (see
// plans/active/2026-09-27-lockdown-switch.md section 11). Used by both the
// member site (just `notice`, via fetch()) and ModTools (the full state, via
// fetchMod()/fetchStats()/fetchHistory()/patch()).
export const useLockdownStore = defineStore('lockdown', {
  state: () => ({
    // Public: the notice to show visitors, or null. Never surfaces/active -
    // those are only ever populated for a logged-in moderator.
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
    phrases: [],
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
      this.phrases = ret?.phrases ?? []
      return ret
    },

    async fetchStats() {
      const ret = await api(this.config).lockdown.fetchStats()
      this.stats = ret
      return ret
    },

    async fetchHistory() {
      const ret = await api(this.config).lockdown.fetchHistory()
      this.history = ret?.history ?? []
      return ret
    },

    // data carries an `action` field (press, surfaces, notice, phrases,
    // markspam, releaseclass, liftall, close) plus that action's own fields.
    // Refreshes the full moderator state afterwards so callers don't have to.
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
