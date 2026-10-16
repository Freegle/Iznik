import { defineStore } from 'pinia'
import api from '~/api'
import { idleSlot, loadInto } from '~/modtools/composables/useUxSlot'

// A blank funnel or search is not sent: the server treats a missing key and an empty one alike, and a
// shorter address is easier to read in a log.
const cleaned = (params = {}) =>
  Object.fromEntries(
    Object.entries(params).filter(
      ([, v]) => v !== undefined && v !== null && v !== ''
    )
  )

// State for the What members say section of the SysAdmin UX tab: one { data, loading, error, missing }
// slot (see useUxSlot) for each of the four reads in iznik-server-go/ux/surveys.go. It follows
// modtools/stores/ux.js: no init(), and the API is asked for when it is used.
//
// The problems slot holds the list for one group, under one funnel and one search, and `problemsFor`
// says which (and the limit it was asked with). It is set in the step that stores the answer, so it
// is never ahead of the list it describes.
export const useUxSurveysStore = defineStore('uxSurveys', {
  state: () => ({
    groups: idleSlot(),
    problems: idleSlot(),
    values: idleSlot(),
    questions: idleSlot(),
    problemsFor: null,
  }),
  actions: {
    // The headings with their sizes. params: flow, q.
    loadGroups(params = {}) {
      return loadInto(this.groups, () =>
        api(useRuntimeConfig()).uxsurveys.fetchProblemGroups(cleaned(params))
      )
    },

    // The problems under one group. params: group, flow, q, limit. Opening another group, or
    // changing the funnel or the search, drops the list held at once, so one group's problems are
    // never drawn under another's heading. Asking again for the same group with a higher limit
    // keeps the list on screen until the longer one replaces it.
    loadProblems(params = {}) {
      const asked = {
        group: params.group || '',
        flow: params.flow || '',
        q: params.q || '',
        limit: Number(params.limit) || 0,
      }
      const held = this.problemsFor
      if (
        !held ||
        held.group !== asked.group ||
        held.flow !== asked.flow ||
        held.q !== asked.q
      ) {
        this.problems.data = null
        this.problemsFor = null
      }
      return loadInto(
        this.problems,
        () => api(useRuntimeConfig()).uxsurveys.fetchProblems(cleaned(params)),
        () => {
          this.problemsFor = asked
        }
      )
    },

    // What members said they value.
    loadValues() {
      return loadInto(this.values, () =>
        api(useRuntimeConfig()).uxsurveys.fetchValues()
      )
    },

    // The questions that were asked, with how each did. params: kind, status.
    loadQuestions(params = {}) {
      return loadInto(this.questions, () =>
        api(useRuntimeConfig()).uxsurveys.fetchQuestions(cleaned(params))
      )
    },
  },
})
