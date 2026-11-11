import { defineStore } from 'pinia'
import api from '~/api'
import { idleSlot, loadInto } from '~/modtools/composables/useUxSlot'

// The load in flight for each store, so the nav badge and the section, which both ask when they
// mount, share one request. Kept apart from the state: a promise is never serialised or reactive.
// It is keyed by the slot, so one store's load is never taken for another's.
const inflight = new WeakMap()

// State for the Experiments section of the SysAdmin UX tab: the list of experiments in one
// { data, loading, error, missing } slot (see useUxSlot), and when the newest answer landed.
// It follows modtools/stores/ux.js: no init(), and the API is asked for when it is used.
export const useUxExperimentsStore = defineStore('uxExperiments', {
  state: () => ({ experiments: idleSlot(), loadedAt: 0 }),
  actions: {
    // Load the list. The newest request wins (see useUxSlot).
    load() {
      const slot = this.experiments
      const mine = loadInto(
        slot,
        () => api(useRuntimeConfig()).uxexperiments.fetchExperiments(),
        () => {
          this.loadedAt = Date.now()
        }
      )
      inflight.set(slot, mine)
      mine.then(() => {
        if (inflight.get(slot) === mine) inflight.delete(slot)
      })
      return mine
    },

    // Load the list unless a load is already on its way or the answer held is younger than
    // maxAgeMs. A failed or missing route leaves nothing to show, so it is asked for again.
    loadIfStale(maxAgeMs) {
      const waiting = inflight.get(this.experiments)
      if (waiting) return waiting
      const fresh =
        this.loadedAt > 0 &&
        Date.now() - this.loadedAt < maxAgeMs &&
        this.experiments.data !== null &&
        !this.experiments.error
      return fresh ? Promise.resolve() : this.load()
    },

    // Add a draft, then load the list so it appears. Gives back { id, status }. A refusal (400, 409)
    // is thrown to the caller, which shows the server's message, and the list is not asked for again.
    async createExperiment(data) {
      const made =
        await api(useRuntimeConfig()).uxexperiments.createExperiment(data)
      await this.load()
      return made
    },

    // Ask the batch to start, stop or close an experiment, then load the list. The batch applies the
    // request within a few minutes, so the list may not show it yet.
    async experimentAction(id, data) {
      const out = await api(useRuntimeConfig()).uxexperiments.experimentAction(
        id,
        data
      )
      await this.load()
      return out
    },
  },
})
