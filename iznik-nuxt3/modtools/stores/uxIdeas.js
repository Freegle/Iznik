import { defineStore } from 'pinia'
import api from '~/api'
import { idleSlot, loadInto } from '~/modtools/composables/useUxSlot'

// State for the Ideas section of the SysAdmin UX tab. `ideas` is the list the section draws, in one
// { data, loading, error, missing } slot (see useUxSlot), and `asked` is what the answer in it was
// asked for, set in the same step that stores the answer, so the section can tell an answer to the
// choice on screen from one still held for the choice before. `forProblem` holds a slot for each
// member problem whose ideas a hosted piece has asked for, kept by the problem's id.
// It follows modtools/stores/ux.js: no init(), and the API is asked for when it is used.
export const useUxIdeasStore = defineStore('uxIdeas', {
  state: () => ({ ideas: idleSlot(), asked: '', forProblem: {} }),
  actions: {
    // Load the list with these params (flow, status, type and limit). The newest request wins (see
    // useUxSlot), so a slow earlier answer never replaces a later one.
    load(params = {}) {
      const asked = JSON.stringify(params)
      return loadInto(
        this.ideas,
        () => api(useRuntimeConfig()).uxideas.fetchIdeas(params),
        () => {
          this.asked = asked
        }
      )
    },

    // Load the ideas that answer one member problem, into that problem's own slot.
    loadForProblem(id) {
      if (!this.forProblem[id]) this.forProblem[id] = idleSlot()
      return loadInto(this.forProblem[id], () =>
        api(useRuntimeConfig()).uxideas.fetchIdeas({ problem_id: id })
      )
    },
  },
})
