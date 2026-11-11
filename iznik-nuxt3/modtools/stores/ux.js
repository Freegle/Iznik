import { defineStore } from 'pinia'
import api from '~/api'
import { idleSlot, loadInto } from '~/modtools/composables/useUxSlot'
import { filterNotes } from '~/modtools/composables/useUxFormat'

const PANELS = {
  health: 'fetchHealth',
  funnels: 'fetchFunnels',
  outcomes: 'fetchOutcomes',
  fields: 'fetchFields',
  friction: 'fetchFriction',
  adoption: 'fetchAdoption',
  regret: 'fetchRegret',
  search: 'fetchSearch',
  samples: 'fetchSamples',
}

// Two sets of params are the same when they hold the same keys and values, whatever the order.
const sameParams = (a, b) =>
  JSON.stringify(Object.entries(a || {}).sort()) ===
  JSON.stringify(Object.entries(b || {}).sort())

// State for the SysAdmin UX tab. One { data, loading, error, missing } slot per read endpoint so each
// panel handles its own loading and failure, and two records of the params a slot was asked with:
//
//   held      the params of the newest request, from the moment it is sent. A section skips a slot
//             that already holds what it wants, and the field figures take their dates from it.
//   answered  the params of the request whose answer is in the slot now. While a changed filter
//             loads, the slot still holds the answer to the one before, and the notes about what the
//             server did with the filters must describe that answer, not the request on its way.
export const useUxStore = defineStore('ux', {
  state: () => {
    const s = { held: {}, answered: {} }
    for (const k of Object.keys(PANELS)) s[k] = idleSlot()
    return s
  },
  actions: {
    // Load one slot. The newest request for a slot wins (see useUxSlot).
    async load(name, params = {}) {
      const asked = { ...params }
      this.held[name] = asked
      await loadInto(
        this[name],
        () => api(useRuntimeConfig()).ux[PANELS[name]]({ ...asked }),
        () => {
          this.answered[name] = { ...asked }
        }
      )
    },

    // Load the named slots with the same params, leaving out samples (opened on demand). A slot that
    // already holds these params is left alone, unless its read failed: asking again may work, and a
    // fault would otherwise stay on screen in every section until the person pressed Update. A route
    // the server does not have ("missing") is not a fault, and asking again cannot fix it. One
    // failure does not stop the others.
    async loadSlots(names, params = {}) {
      await Promise.all(
        names
          .filter((name) => name !== 'samples' && name in PANELS)
          .filter(
            (name) => !sameParams(this.held[name], params) || !!this[name].error
          )
          .map((name) => this.load(name, params))
      )
    },

    // Forget what each slot holds, so the next loadSlots asks the server again. Called when the tab
    // opens and when the person asks for a refresh. The answers stay where they are until the new
    // ones arrive, and they are still described by what they were asked with.
    forget() {
      this.held = {}
    },

    // The params a slot was last asked with.
    requested(name) {
      return this.held[name] || {}
    },

    // Plain sentences about what the server did with the filters, for the answer in the slot.
    notes(name) {
      return filterNotes(name, this[name].data, this.answered[name])
    },
  },
})
