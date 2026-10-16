<template>
  <form class="ux-exp-form border rounded p-3 mb-3" novalidate @submit.prevent>
    <h5 class="mb-3">New experiment</h5>

    <div class="mb-3">
      <label :for="`${id}-flow`" class="form-label fw-semibold">
        Which funnel is this about?
      </label>
      <select
        :id="`${id}-flow`"
        v-model="flow"
        class="f-flow form-select form-select-sm"
      >
        <option v-for="f in FLOW_ORDER" :key="f" :value="f">
          {{ FLOW_TITLES[f] }}
        </option>
      </select>
    </div>

    <div class="mb-3">
      <label :for="`${id}-name`" class="form-label fw-semibold">
        Short name
      </label>
      <input
        :id="`${id}-name`"
        v-model="name"
        type="text"
        class="f-name form-control form-control-sm"
        autocomplete="off"
        spellcheck="false"
      />
      <p v-if="nameBreaksRule" class="name-rule small text-danger mt-1 mb-0">
        {{ NAME_RULE }}
      </p>
      <p v-else-if="nameTrimmed && inCode" class="hint-found small mt-1 mb-0">
        Found in the code
      </p>
      <p v-else-if="nameTrimmed" class="hint-missing small mt-1 mb-0">
        Not found in the code yet. Everyone will see the old version until it
        is.
      </p>
    </div>

    <div class="mb-3">
      <label :for="`${id}-title`" class="form-label fw-semibold">Title</label>
      <input
        :id="`${id}-title`"
        v-model="title"
        type="text"
        class="f-title form-control form-control-sm"
        maxlength="160"
      />
    </div>

    <div class="mb-3">
      <label :for="`${id}-hypothesis`" class="form-label fw-semibold">
        What is wrong now, and why should this help?
      </label>
      <textarea
        :id="`${id}-hypothesis`"
        v-model="hypothesis"
        rows="2"
        class="f-hypothesis form-control form-control-sm"
      />
    </div>

    <div class="mb-3">
      <label :for="`${id}-change`" class="form-label fw-semibold">
        What does the change do?
      </label>
      <textarea
        :id="`${id}-change`"
        v-model="change"
        rows="2"
        class="f-change form-control form-control-sm"
      />
    </div>

    <div class="mb-3">
      <label :for="`${id}-expected`" class="form-label fw-semibold">
        What do we expect to see?
      </label>
      <textarea
        :id="`${id}-expected`"
        v-model="expected"
        rows="2"
        class="f-expected form-control form-control-sm"
      />
    </div>

    <fieldset class="mb-3">
      <legend class="form-label fw-semibold fs-6 mb-1">
        Which other funnels could this change affect?
      </legend>
      <!-- The funnel it is about is always watched. -->
      <div class="touch-own form-check form-check-inline">
        <input
          :id="`${id}-own`"
          type="checkbox"
          class="form-check-input"
          checked
          disabled
        />
        <label :for="`${id}-own`" class="form-check-label fw-normal">
          {{ FLOW_TITLES[flow] }}
        </label>
      </div>
      <div
        v-for="f in others"
        :key="f"
        class="touch-other form-check form-check-inline"
      >
        <input
          :id="`${id}-touch-${f}`"
          v-model="checked"
          type="checkbox"
          class="form-check-input"
          :value="f"
        />
        <label :for="`${id}-touch-${f}`" class="form-check-label fw-normal">{{
          FLOW_TITLES[f]
        }}</label>
      </div>
    </fieldset>

    <div class="mb-3">
      <label :for="`${id}-points`" class="form-label fw-semibold">
        How big a change do we expect, in points of finishing?
      </label>
      <input
        :id="`${id}-points`"
        v-model="points"
        type="number"
        min="0.1"
        max="50"
        step="0.1"
        class="f-points form-control form-control-sm"
      />
    </div>

    <div class="mb-3">
      <label :for="`${id}-pr`" class="form-label fw-semibold">
        Link to the code change (optional)
      </label>
      <input
        :id="`${id}-pr`"
        v-model="pr"
        type="text"
        class="f-pr form-control form-control-sm"
        autocomplete="off"
        spellcheck="false"
      />
    </div>

    <ul v-if="problems.length" class="problems text-danger small mb-2 ps-3">
      <li v-for="p in problems" :key="p">{{ p }}</li>
    </ul>
    <div v-if="serverError" class="server-error alert alert-danger small py-2">
      {{ serverError }}
    </div>

    <div class="d-flex flex-wrap gap-2">
      <button
        type="button"
        class="save-draft btn btn-sm btn-outline-primary"
        :disabled="saving"
        @click="save(false)"
      >
        Save as draft
      </button>
      <button
        type="button"
        class="save-start btn btn-sm btn-primary"
        :disabled="saving"
        @click="save(true)"
      >
        Save and start
      </button>
      <button
        type="button"
        class="cancel btn btn-sm btn-link"
        :disabled="starting"
        @click="cancel"
      >
        Cancel
      </button>
    </div>
  </form>
</template>

<script setup>
// The form that makes an experiment by hand. Save as draft adds a draft. Save and start adds it and
// then asks the batch to start it, which may refuse (the cap, a second change on the funnel, a change
// too small to read in a day). The draft stays then, and its row shows the reason. The server says
// no with a message of its own for a bad body or a name that is taken, and that is shown here.
import { computed, ref, useId } from 'vue'
import { useUxExperimentsStore } from '~/modtools/stores/uxExperiments'
import { serverMessage } from '~/modtools/composables/useUxExperimentActions'
import { FLOW_ORDER, FLOW_TITLES } from '~/modtools/composables/useUxFlows'
import manifest from '~/ux-experiments.manifest.json'

const props = defineProps({
  // The funnel the tab is showing, if any.
  defaultFlow: { type: String, default: '' },
  // The pending requests and refusals (useUxExperimentActions), shared with the list, so the new
  // row says that a start was requested.
  actions: { type: Object, required: true },
})
// saved gives the id of the new draft (null if it did not come back) and its funnel.
const emit = defineEmits(['saved', 'cancel'])

const store = useUxExperimentsStore()
const id = useId()

const NAME_RULE = 'Use letters, digits, - and _ only, up to 64 characters.'
const NAME_RE = /^[A-Za-z0-9_-]{1,64}$/
// The name becomes a key in the object the browser reads, so these would shadow its own lookups.
const RESERVED = ['__proto__', 'constructor', 'prototype']
const TITLE_MAX = 160
const POINTS_MIN = 0.1
const POINTS_MAX = 50

const flow = ref(
  FLOW_ORDER.includes(props.defaultFlow) ? props.defaultFlow : 'give'
)
const name = ref('')
const title = ref('')
const hypothesis = ref('')
const change = ref('')
const expected = ref('')
// The other funnels ticked. Signing in is ticked to begin with: a change to a form most often shows
// up in whether people can still sign in.
const checked = ref(['login'])
const points = ref(5)
const pr = ref('')

const saving = ref(false)
// True while the request to start is on its way. Cancel is not offered then: it can no longer keep
// the start from being asked for.
const starting = ref(false)
// Set when Cancel is pressed. The draft cannot be taken back once it has been sent, but the start is
// the step that reaches members, and it is not asked for after Cancel. The section closes the form
// on Cancel, so a save that comes back later finds nobody to tell.
let cancelled = false
const problems = ref([])
const serverError = ref('')

// The names of the switches the member site has code for.
const inCodeNames = new Set(
  (manifest?.experiments || []).map((e) => e?.name).filter(Boolean)
)

const nameTrimmed = computed(() => name.value.trim())
const nameOk = computed(
  () => NAME_RE.test(nameTrimmed.value) && !RESERVED.includes(nameTrimmed.value)
)
const nameBreaksRule = computed(() => nameTrimmed.value !== '' && !nameOk.value)
const inCode = computed(() => inCodeNames.has(nameTrimmed.value))

const others = computed(() => FLOW_ORDER.filter((f) => f !== flow.value))

function check() {
  const out = []
  if (!nameTrimmed.value) out.push('Add a short name.')
  else if (!nameOk.value) out.push(NAME_RULE)

  const t = title.value.trim()
  if (!t) out.push('Add a title.')
  else if (t.length > TITLE_MAX) {
    out.push(`Keep the title to ${TITLE_MAX} characters or fewer.`)
  }

  if (!hypothesis.value.trim()) {
    out.push('Say what is wrong now, and why this should help.')
  }
  if (!change.value.trim()) out.push('Say what the change does.')
  if (!expected.value.trim()) out.push('Say what we expect to see.')

  const n = points.value === '' ? NaN : Number(points.value)
  if (!Number.isFinite(n) || n < POINTS_MIN || n > POINTS_MAX) {
    out.push(`Use a number from ${POINTS_MIN} to ${POINTS_MAX}.`)
  }

  // Go refuses any other address, and the list draws a link only for https://.
  const link = pr.value.trim()
  if (link && !link.startsWith('https://')) {
    out.push('Use an address that starts with https://.')
  }
  return out
}

function body() {
  const touches = [
    flow.value,
    ...FLOW_ORDER.filter((f) => f !== flow.value && checked.value.includes(f)),
  ]
  const out = {
    flow: flow.value,
    name: nameTrimmed.value,
    title: title.value.trim(),
    hypothesis: hypothesis.value.trim(),
    change: change.value.trim(),
    expected: expected.value.trim(),
    design: { touches, expected_points: Number(points.value) },
  }
  const link = pr.value.trim()
  if (link) out.pr_url = link
  return out
}

function cancel() {
  cancelled = true
  emit('cancel')
}

async function save(start) {
  if (saving.value) return
  serverError.value = ''
  problems.value = check()
  if (problems.value.length) return

  saving.value = true
  cancelled = false
  let made
  try {
    made = await store.createExperiment(body())
  } catch (err) {
    serverError.value = serverMessage(err)
    saving.value = false
    return
  }

  try {
    // The start goes straight after the create. Go reads the new row from the primary database, so
    // a read replica that has not caught up does not answer "No such experiment". A refusal by the
    // batch, or a failed call, is kept on the new row and shown there. Not after Cancel.
    if (start && made?.id && !cancelled) {
      starting.value = true
      await props.actions.act({ id: made.id, status: 'draft' }, 'start')
    }
  } finally {
    starting.value = false
    saving.value = false
  }
  if (!cancelled) emit('saved', made?.id ?? null, flow.value)
}
</script>

<style scoped lang="scss">
.hint-found {
  color: #0f5132;
  font-weight: 600;
}

.hint-missing {
  padding: 0.25rem 0.5rem;
  border-left: 3px solid #ffc107;
  background: #fff8e1;
  color: #4d3b02;
}

.ux-exp-form button {
  // assets/css/buttons.scss keeps every button on one line.
  white-space: normal !important;
}
</style>
