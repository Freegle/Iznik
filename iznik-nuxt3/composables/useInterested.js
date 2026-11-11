import { reactive, computed, onMounted, toValue } from 'vue'
import api from '~/api'
import { action } from '~/composables/useClientLog'

// The "I'm interested" mark. The server decides per member whether the button is offered (the
// arm), so there is no useExperiment here and nothing in the experiments manifest. The state is
// kept per post for the life of the page, so the two reply areas of MessageExpanded, and My Posts,
// share one request and agree with each other.
const states = reactive({})

function stateFor(id) {
  if (!states[id]) {
    states[id] = {
      arm: '',
      interested: false,
      eligible: false,
      loaded: false,
      loading: false,
      justMarked: false,
    }
  }

  return states[id]
}

function messageApi() {
  return api(useRuntimeConfig()).message
}

// Any failure means no button: an API without the routes answers 404 and the page must not notice.
async function load(id) {
  const s = stateFor(id)

  if (s.loaded || s.loading) {
    return
  }

  s.loading = true

  try {
    const ret = await messageApi().interestedState(id)
    s.arm = ret?.arm === 'button' ? 'button' : ''
    s.interested = ret?.interested === true
    s.eligible = ret?.eligible === true
  } catch (e) {
    s.arm = ''
    s.interested = false
    s.eligible = false
  }

  s.loading = false
  s.loaded = true
}

async function set(id, interested) {
  const s = stateFor(id)

  try {
    const ret = await messageApi().setInterested(id, interested)
    s.interested = ret?.interested === true
    s.justMarked = interested && s.interested
    action(interested ? 'interested_mark' : 'interested_undo', { msgid: id })
    return true
  } catch (e) {
    return false
  }
}

// The posts the member has marked, newest first. An old API gives an empty list.
export async function listInterested() {
  try {
    const ret = await messageApi().interestedList()
    return Array.isArray(ret) ? ret : []
  } catch (e) {
    return []
  }
}

// Remove a mark from outside the post (My Posts), keeping the shared state in step.
export function removeInterested(id) {
  return set(id, false)
}

export function useInterested(id) {
  onMounted(() => load(toValue(id)))

  const state = computed(() => stateFor(toValue(id)))

  return {
    // The button is offered only in the arm that has it, and only to a member who may mark.
    showButton: computed(
      () =>
        state.value.loaded &&
        state.value.arm === 'button' &&
        state.value.eligible &&
        !state.value.interested
    ),
    // The marked state shows whatever the arm: a member who marked keeps seeing it.
    interested: computed(() => state.value.interested),
    // True only straight after pressing, for the one-line note.
    justMarked: computed(() => state.value.justMarked),
    mark: () => set(toValue(id), true),
    undo: () => set(toValue(id), false),
    load: () => load(toValue(id)),
  }
}
