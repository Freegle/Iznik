<template>
  <div class="ux-experiments">
    <p v-if="store.experiments.missing" class="not-switched-on text-muted">
      {{ NOT_SWITCHED_ON }}
    </p>
    <NoticeMessage
      v-else-if="store.experiments.error"
      variant="danger"
      class="mb-3"
    >
      {{ store.experiments.error }}
    </NoticeMessage>
    <div v-else-if="!data" class="loading text-center py-3">
      <b-spinner small class="me-2" />
      Loading...
    </div>

    <template v-else>
      <div
        class="strip d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2"
      >
        <p class="slots mb-0">{{ slotsText }}</p>
        <button
          type="button"
          class="new btn btn-sm btn-primary"
          @click="emit('navigate', { exp: 'new' })"
        >
          New experiment
        </button>
      </div>

      <div class="filters d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="filter-pair">
          <label :for="`${id}-flow`" class="filter-label">Funnel:</label>
          <select
            :id="`${id}-flow`"
            :value="flowFilter"
            class="flow-select form-select form-select-sm"
            @change="(e) => chooseFunnel(e.target.value)"
          >
            <option value="">All funnels</option>
            <option v-for="f in FLOW_ORDER" :key="f" :value="f">
              {{ FLOW_TITLES[f] }}
            </option>
          </select>
        </span>
        <span
          class="chips d-flex flex-wrap gap-1"
          role="group"
          aria-label="Show"
        >
          <button
            v-for="c in chips"
            :key="c.key"
            type="button"
            class="chip"
            :class="[`chip-${c.key}`, { active: c.key === estate }]"
            :aria-pressed="c.key === estate ? 'true' : 'false'"
            @click="chooseEstate(c.key)"
          >
            {{ c.label }} ({{ c.count }})
          </button>
        </span>
      </div>

      <p v-if="flowFilter" class="funnel-notice small mb-2">
        {{ noticeText }}
        <a :href="hrefFor({ flow: undefined, exp: undefined })" @click="showAll"
          >Show all</a
        >
      </p>

      <ModSysAdminUxExperimentForm
        v-if="creating"
        :default-flow="flowFilter"
        :actions="actions"
        @saved="onSaved"
        @cancel="emit('navigate', { exp: undefined })"
      />

      <p v-if="waitingForNew" class="saved-notice small text-muted mb-2">
        Saved. It will show in the list in a moment.
      </p>
      <p v-else-if="openMissing" class="not-in-list small text-muted mb-2">
        That experiment is not in the list.
      </p>
      <p v-else-if="openOutside" class="open-outside small text-muted mb-2">
        That experiment is outside the choices above. It is shown here because
        it is open.
        <a
          :href="hrefFor({ flow: undefined, estate: undefined })"
          @click="showEverything"
          >Show all</a
        >
      </p>

      <p v-if="all.length === 0" class="none text-muted">
        No experiments yet. Use New experiment to add one.
      </p>
      <template v-else>
        <p
          v-if="flowFilter && !triedHere"
          class="untried small text-muted mb-2"
        >
          Nothing has been tried on this funnel yet.
        </p>
        <p v-if="rows.length === 0" class="none text-muted">
          No experiments here.
        </p>
        <ul class="list-unstyled mb-2">
          <li v-for="e in rows" :key="e.id" class="mb-2">
            <ModSysAdminUxExperimentRow
              :experiment="e"
              :open="e.id === openId"
              :waiting="!!actions.pending(e)"
              :controls="`ux-exp-${e.id}`"
              @toggle="toggle(e)"
            />
            <ModSysAdminUxExperimentDetail
              v-if="e.id === openId"
              :experiment="e"
              :experiments="all"
              :full="full"
              :actions="actions"
            />
          </li>
        </ul>
        <button
          v-if="remaining > 0"
          type="button"
          class="more btn btn-sm btn-outline-secondary"
          @click="limit = shown + PAGE"
        >
          Show {{ Math.min(PAGE, remaining) }} more
        </button>
      </template>
    </template>
  </div>
</template>

<script setup>
// The Experiments section of the SysAdmin UX tab: the experiments in one list, one open at a time,
// with a form to make one by hand and a plain read-out of how a running one is doing. Starting,
// stopping and closing go to the batch through the store, which may refuse. The section needs only
// the experiment routes. It reads nothing of the surveys, ideas or members' reactions.
import {
  computed,
  inject,
  onBeforeUnmount,
  onMounted,
  ref,
  useId,
  watch,
} from 'vue'
import { useUxExperimentsStore } from '~/modtools/stores/uxExperiments'
import { useUxExperimentActions } from '~/modtools/composables/useUxExperimentActions'
import { NOT_SWITCHED_ON } from '~/modtools/composables/useUxSlot'
import {
  FLOW_ORDER,
  FLOW_SHORT,
  FLOW_TITLES,
} from '~/modtools/composables/useUxFlows'
import { isPlainClick } from '~/modtools/composables/useUxFormat'
import {
  estateOf,
  orderExperiments,
} from '~/modtools/composables/useUxExperimentRead'
import ModSysAdminUxExperimentRow from '~/modtools/components/ModSysAdminUxExperimentRow.vue'
import ModSysAdminUxExperimentDetail from '~/modtools/components/ModSysAdminUxExperimentDetail.vue'
import ModSysAdminUxExperimentForm from '~/modtools/components/ModSysAdminUxExperimentForm.vue'

const props = defineProps({
  // The view from useUxView: ux_flow is view.flow, and the section's own keys are view.extra.exp
  // (an experiment's id, or new) and view.extra.estate (running, draft or finished).
  view: { type: Object, required: true },
})
const emit = defineEmits(['navigate'])

// The tab gives every section a way to get the address of a change, for links that can be copied.
const hrefFor = inject('uxHrefFor', () => '#')

const store = useUxExperimentsStore()
const id = useId()

// The most finished experiments drawn at once. Running and not started ones are never paged away.
const PAGE = 25
const limit = ref(PAGE)

onMounted(() => {
  store.loadIfStale(30000)
})

const data = computed(() => store.experiments.data)

// Every experiment, running first. The list holds each experiment once, under its funnel.
const all = computed(() => {
  const seen = new Map()
  for (const f of data.value?.flows || []) {
    for (const e of [...(f.running || []), ...(f.history || [])]) {
      seen.set(e.id, e)
    }
  }
  return orderExperiments([...seen.values()])
})

// The pending requests and refusals are shared by the rows, the details and the form.
const actions = useUxExperimentActions(store, () => all.value)

// ux_flow may hold something that is no funnel (a value another section uses), which is then no
// filter.
const flowFilter = computed(() =>
  FLOW_ORDER.includes(props.view.flow) ? props.view.flow : ''
)
const estate = computed(() =>
  ['running', 'draft', 'finished'].includes(props.view.extra?.estate)
    ? props.view.extra.estate
    : 'all'
)

const runningCount = computed(() => Number(data.value?.running_count) || 0)
const maxRunning = computed(() => Number(data.value?.max_running) || 0)
const full = computed(
  () => maxRunning.value > 0 && runningCount.value >= maxRunning.value
)
const slotsText = computed(
  () =>
    `${runningCount.value} of ${maxRunning.value} experiment slots in use.` +
    (full.value ? ' No more can start until one ends.' : '')
)

// What the Funnel select leaves, before the chips cut it down.
const byFunnel = computed(() =>
  flowFilter.value
    ? all.value.filter((e) => e.flow === flowFilter.value)
    : all.value
)
const elsewhere = computed(
  () =>
    all.value.filter(
      (e) => e.status === 'running' && e.flow !== flowFilter.value
    ).length
)
// "Showing Offering only. 1 running on other funnels." Running ones on other funnels are hidden by
// the choice of funnel, so the notice says how many, and Show all brings them back.
const noticeText = computed(
  () =>
    `Showing ${FLOW_SHORT[flowFilter.value]} only.` +
    (elsewhere.value ? ` ${elsewhere.value} running on other funnels.` : '')
)
const triedHere = computed(() =>
  byFunnel.value.some((e) => estateOf(e) !== 'draft')
)

const chips = computed(() => {
  const count = (key) =>
    key === 'all'
      ? byFunnel.value.length
      : byFunnel.value.filter((e) => estateOf(e) === key).length
  return [
    { key: 'all', label: 'All' },
    { key: 'running', label: 'Running' },
    { key: 'draft', label: 'Not started' },
    { key: 'finished', label: 'Finished' },
  ].map((c) => ({ ...c, count: count(c.key) }))
})

const visible = computed(() =>
  estate.value === 'all'
    ? byFunnel.value
    : byFunnel.value.filter((e) => estateOf(e) === estate.value)
)
const visibleIds = computed(() => new Set(visible.value.map((e) => e.id)))
const finishedVisible = computed(
  () => visible.value.filter((e) => estateOf(e) === 'finished').length
)

const creating = computed(() => props.view.extra?.exp === 'new')
const openId = computed(() => {
  const exp = props.view.extra?.exp
  return /^\d+$/.test(exp || '') ? Number(exp) : null
})
const openMissing = computed(
  () => openId.value !== null && !all.value.some((e) => e.id === openId.value)
)

// The open experiment is always drawn, so a link to it opens it. A finished one that paging would
// leave out is reached by drawing more rows: how many are drawn is the page asked for, or enough to
// reach the open one.
const openPosition = computed(() => {
  if (openId.value === null) return 0
  const finished = visible.value.filter((e) => estateOf(e) === 'finished')
  return finished.findIndex((e) => e.id === openId.value) + 1
})
const shown = computed(() => Math.max(limit.value, openPosition.value))
const remaining = computed(() =>
  Math.max(0, finishedVisible.value - shown.value)
)

// The open one may be outside what the Funnel select and the chips leave, as an address can say.
// It is then drawn in its own place all the same, and the section says why.
const openOutside = computed(
  () =>
    openId.value !== null &&
    !openMissing.value &&
    !visibleIds.value.has(openId.value)
)

// Finished experiments are paged; the rest are always drawn.
const rows = computed(() => {
  let finished = 0
  return all.value.filter((e) => {
    if (!visibleIds.value.has(e.id)) return e.id === openId.value
    if (estateOf(e) !== 'finished') return true
    finished++
    return finished <= shown.value
  })
})

// A new choice of funnel or chip starts the paging again.
watch([flowFilter, estate], () => {
  limit.value = PAGE
})

function toggle(e) {
  emit('navigate', { exp: openId.value === e.id ? undefined : String(e.id) })
}

// A change of filter closes the open experiment, which the filter may be hiding.
function chooseFunnel(value) {
  emit('navigate', { flow: value || undefined, exp: undefined })
}
function chooseEstate(key) {
  emit('navigate', { estate: key === 'all' ? undefined : key, exp: undefined })
}
function showAll(e) {
  if (!isPlainClick(e)) return
  e.preventDefault()
  emit('navigate', { flow: undefined, exp: undefined })
}
// Takes both choices off and keeps the open experiment open.
function showEverything(e) {
  if (!isPlainClick(e)) return
  e.preventDefault()
  emit('navigate', { flow: undefined, estate: undefined })
}

// A draft that was just saved opens, whatever the chips and the Funnel select were showing. The list
// is read from a replica that may not hold the new row yet, so the list is asked for again a few
// times until it does.
const newId = ref(null)
const waitingForNew = computed(
  () => newId.value !== null && !all.value.some((e) => e.id === newId.value)
)
let looks = 0
let timer = null
const LOOK_AFTER_MS = 3000
const LOOKS = 3

function lookForNew() {
  if (timer || !waitingForNew.value || looks >= LOOKS) return
  timer = setTimeout(async () => {
    timer = null
    // The row may have come in while this waited.
    if (!waitingForNew.value) return
    looks++
    try {
      await store.load()
    } catch {
      // The next look will try again.
    }
    lookForNew()
  }, LOOK_AFTER_MS)
}

function onSaved(savedId, flow) {
  newId.value = savedId
  looks = 0
  emit('navigate', {
    exp: savedId ? String(savedId) : undefined,
    estate: undefined,
    // Keep a Funnel choice, but on the funnel of the new draft, so that it shows.
    flow: flowFilter.value ? flow : undefined,
  })
  lookForNew()
}

onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
  timer = null
})
</script>

<style scoped lang="scss">
.filter-pair {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  white-space: nowrap;
}

.filter-label {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 600;
}

.flow-select {
  width: auto;
  max-width: 100%;
  padding-right: 2rem;
}

// A chip that is chosen is dark and plain, so it cannot be taken for one that is switched off.
.chip {
  padding: 0.15rem 0.7rem;
  border: 1px solid #6c757d;
  border-radius: 1rem;
  background: #fff;
  color: #343a40;
  font-size: 0.85rem;
  // assets/css/buttons.scss keeps every button on one line.
  white-space: normal !important;

  &:hover:not(.active) {
    background: #e9ecef;
  }

  &.active {
    border-color: #343a40;
    background: #343a40;
    color: #fff;
  }
}

.funnel-notice {
  padding: 0.35rem 0.5rem;
  border-left: 3px solid #0d6efd;
  background: #eef5ff;
}
</style>
