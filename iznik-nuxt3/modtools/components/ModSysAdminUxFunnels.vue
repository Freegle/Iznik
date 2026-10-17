<template>
  <div class="ux-funnels">
    <ModSysAdminUxFunnelPicker
      :flows="pickerFlows"
      :current="openFlow"
      :href-for="hrefFor"
      @navigate="(patch) => emit('navigate', patch)"
    />

    <ModSysAdminUxPanel
      :title="flowTitle(openFlow)"
      explanation="Each bar is the share of people who reached the step. The red end is the share of visits to that step that ended there. The dashed outline is the same step in the period before."
      :loading="waiting"
      :error="store.funnels.error || store.outcomes.error"
      :missing="store.funnels.missing || store.outcomes.missing"
      :notes="notes"
    >
      <p v-if="nobody" class="nobody text-muted mb-0">
        {{ view.filtersActive ? NOBODY_FILTERED : NOBODY }}
        <button
          v-if="view.filtersActive"
          type="button"
          class="btn btn-link btn-sm p-0 align-baseline"
          @click="emit('navigate', { device: undefined, signed: undefined })"
        >
          Show everyone and all devices
        </button>
      </p>
      <template v-else>
        <p class="headline mb-1">{{ headlineText }}</p>
        <p v-if="outcomeText" class="outcome-line small mb-1">
          {{ outcomeText }}
        </p>
        <p v-if="comparedText" class="compared small text-muted mb-1">
          {{ comparedText }}
        </p>
        <details class="small mb-2 how-to-read">
          <summary>How to read this</summary>
          <p class="mb-0">{{ HOW_TO_READ }}</p>
        </details>

        <div v-for="(c, i) in charts" :key="c.fn.family" class="mb-2">
          <ModSysAdminUxFunnelChart
            :title="familyLabel(c.fn.family)"
            :steps="c.fn.steps"
            :exits="c.fn.exits"
            :previous-reach="c.reach"
            :previous-done="c.done"
            :selected="view.step"
            :worst="worstStep"
            :show-key="i === charts.length - 1"
            @select="select"
          />
        </div>

        <div v-if="detail" class="step-detail border rounded p-3 mb-3">
          <h6>{{ stepName(openFlow, detail.step) }}</h6>
          <p class="small mb-2">
            {{ reachedText }} Of the
            {{ detail.visits.toLocaleString() }} visits,
            {{ withCount(detail.completed, detail.visits) }} finished the step,
            {{ withCount(detail.backed, detail.visits) }} went back and
            {{ withCount(detail.abandoned, detail.visits) }} left without
            finishing it. {{ timeText }} {{ previousSentence }}
          </p>
          <div v-for="dim in dims" :key="dim.key" class="mb-2">
            <h6 class="mb-1">{{ dim.label }}</h6>
            <b-table
              :items="dim.items"
              :fields="breakdownFields"
              small
              responsive
            />
          </div>
          <b-button
            class="sample-btn"
            size="sm"
            variant="secondary"
            @click="open = true"
          >
            Sample sessions
          </b-button>
          <b-button
            class="close-btn ms-2"
            size="sm"
            variant="link"
            @click="emit('navigate', { step: undefined })"
          >
            Close
          </b-button>
        </div>
      </template>

      <!-- Drawn even when nobody started: they are this funnel's own rows, and say so when empty. -->
      <p v-if="funnelLinks.length" class="also small mb-2">
        Also for this funnel:
        <template v-for="(l, i) in funnelLinks" :key="l.key">
          <span v-if="i">, </span>
          <a
            :href="hrefFor({ section: l.key, flow: openFlow })"
            @click="(e) => also(e, l.key)"
          >
            {{ l.label }}
          </a>
        </template>
      </p>

      <ModSysAdminUxSecondThoughts
        :flow="openFlow"
        :open="view.open === 'thoughts'"
        @toggle="toggleOpen('thoughts')"
      />
      <ModSysAdminUxHelp
        :flow="openFlow"
        :open="view.open === 'help'"
        @toggle="toggleOpen('help')"
      />
    </ModSysAdminUxPanel>

    <ModSysAdminUxSamples
      v-model="open"
      :flow="openFlow"
      :step="view.step"
      :start="store.funnels.data?.start || ''"
      :end="store.funnels.data?.end || ''"
    />
  </div>
</template>

<script setup>
import { computed, inject, ref, watch } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import { settled } from '~/modtools/composables/useUxSlot'
import {
  FLOW_ORDER,
  flowShort,
  flowTitle,
} from '~/modtools/composables/useUxFlows'
import {
  comparedWith,
  isPlainClick,
  ms,
  withCount,
} from '~/modtools/composables/useUxFormat'
import {
  deviceLabel,
  familyLabel,
  funnelsFor,
  headline,
  outcomeLine,
  stepName,
  summariseFlow,
} from '~/modtools/composables/useUxFunnel'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'
import ModSysAdminUxFunnelPicker from '~/modtools/components/ModSysAdminUxFunnelPicker.vue'
import ModSysAdminUxFunnelChart from '~/modtools/components/ModSysAdminUxFunnelChart.vue'
import ModSysAdminUxSamples from '~/modtools/components/ModSysAdminUxSamples.vue'
import ModSysAdminUxSecondThoughts from '~/modtools/components/ModSysAdminUxSecondThoughts.vue'
import ModSysAdminUxHelp from '~/modtools/components/ModSysAdminUxHelp.vue'

const props = defineProps({
  // The view from useUxView.
  view: { type: Object, required: true },
})
const emit = defineEmits(['navigate'])

// The tab gives every section a way to get the address of a change, for links that can be copied,
// and the links other sections want under "Also for this funnel".
const hrefFor = inject('uxHrefFor', () => '#')
const funnelLinks = inject('uxFunnelLinks', [])

const store = useUxStore()
const open = ref(false)

const NOBODY = 'Nobody started this funnel in this period.'
const NOBODY_FILTERED = 'Nobody started this funnel with these filters.'
const HOW_TO_READ =
  'Bar width is how many reached the step. The hatched red end is how many of its visits ended there. The dashed outline is the same step in the period before. Steps that are different ways into the same stage are grouped. Click a bar to see who left and how long they spent.'
const SIGNED_WARNING =
  'Signed in is the state at each step, not at the start of the visit. Someone who signs in part way counts as signed out on the earlier steps. Treat these figures as rough.'
// The funnels where a person can sign in part way through.
const SIGNED_ROUGH = ['give', 'ask', 'reply', 'login']
const THIN_DATA = 100

const flowsData = computed(() => store.funnels.data?.flows || [])
const outcomeFlows = computed(() => store.outcomes.data?.flows || [])
const outcomeOf = (flow) => outcomeFlows.value.find((o) => o.flow === flow)

// Both reads have given their first answer, whether it was data or a fault.
const ready = computed(() => settled(store.funnels) && settled(store.outcomes))
const failed = computed(
  () =>
    !!store.funnels.error ||
    store.funnels.missing ||
    !!store.outcomes.error ||
    store.outcomes.missing
)
// Waiting for the first answers. A read that has failed is shown at once, without waiting for the
// other.
const waiting = computed(
  () =>
    !failed.value &&
    (!ready.value || store.funnels.loading || store.outcomes.loading)
)

// Which funnel is open, once both reads have an answer. It is ux_flow when that is a funnel,
// otherwise the first funnel with starts, otherwise give. A ux_flow that is no funnel (another
// section may leave one in the address) is ignored, and nothing here ever writes the default into
// the address.
const resolved = computed(() => {
  const want = props.view.flow
  const known =
    FLOW_ORDER.includes(want) ||
    flowsData.value.some((f) => f.flow === want) ||
    outcomeFlows.value.some((o) => o.flow === want)
  if (want && known) return want
  return FLOW_ORDER.find((f) => (outcomeOf(f)?.started || 0) > 0) || 'give'
})

// The funnel the last full pair of answers chose. A reload (a changed filter, a period, Update) takes
// both reads out of the settled state until the new answers arrive; the funnel on screen must not
// flick to another and back while it waits.
const lastResolved = ref('')
watch(
  () => (ready.value && !failed.value ? resolved.value : ''),
  (flow) => {
    if (flow) lastResolved.value = flow
  },
  { immediate: true }
)

// Until both reads have settled only the seven funnels are known, so a ux_flow outside them is
// treated as unknown. A funnel the address names is open at once. With none named, a reload keeps
// the funnel it had; before any answer, or when a read failed, it is give.
const openFlow = computed(() => {
  if (ready.value && !failed.value) return resolved.value
  const want = props.view.flow
  if (FLOW_ORDER.includes(want)) return want
  return failed.value ? 'give' : lastResolved.value || 'give'
})

// The seven funnels, then any other flow the API returned, so a new flow is never hidden.
const pickerFlows = computed(() => {
  const keys = [...FLOW_ORDER]
  for (const f of [...flowsData.value, ...outcomeFlows.value]) {
    if (!keys.includes(f.flow)) keys.push(f.flow)
  }
  return keys.map((flow) => ({
    flow,
    short: flowShort(flow),
    started: store.outcomes.data ? outcomeOf(flow)?.started || 0 : null,
  }))
})

const steps = computed(
  () => flowsData.value.find((f) => f.flow === openFlow.value)?.steps || []
)
const outcome = computed(() => outcomeOf(openFlow.value))
const previousOutcome = computed(() =>
  (store.outcomes.data?.previous?.flows || []).find(
    (o) => o.flow === openFlow.value
  )
)

const summary = computed(() =>
  summariseFlow(
    openFlow.value,
    outcome.value,
    steps.value,
    previousOutcome.value,
    { filtered: props.view.filtersActive }
  )
)
const nobody = computed(
  () => ready.value && !failed.value && summary.value.loss.state === 'nobody'
)
const worstStep = computed(() =>
  summary.value.loss.state === 'step' ? summary.value.loss.step : ''
)
const headlineText = computed(() =>
  headline(openFlow.value, outcome.value, steps.value, previousOutcome.value, {
    filtered: props.view.filtersActive,
  })
)
const outcomeText = computed(() => outcomeLine(outcome.value))
const comparedText = computed(() => comparedWith(store.funnels.data))

const notes = computed(() => {
  const out = []
  if (props.view.signed && SIGNED_ROUGH.includes(openFlow.value)) {
    out.push(SIGNED_WARNING)
  }
  const started = summary.value.started
  if (started > 0 && started < THIN_DATA) {
    out.push(
      `Only ${started.toLocaleString()} ${started === 1 ? 'person' : 'people'} started this funnel. Percentages from so few people can change a lot.`
    )
  }
  out.push(...new Set([...store.notes('funnels'), ...store.notes('outcomes')]))
  return out
})

// step -> the share that reached it, and step -> its completed figures, in the period before. The
// reach is a share of that period's own first step, not a count, so the chart draws it as an outline
// of the same kind of width and says so.
function previousFigures(flow, fn) {
  const reach = {}
  const done = {}
  const before = store.funnels.data?.previous?.flows?.find(
    (p) => p.flow === flow
  )
  if (!before) return { reach, done }
  const prev = funnelsFor(flow, before.steps).find(
    (p) => p.family === fn.family
  )
  ;(prev?.steps || []).forEach((s, i) => {
    // The heading of a stage with ways in is the sum of them, each way in is its own bar, and they
    // must not share a key or the heading is outlined at one way in's share. The stage is keyed by
    // its position, because which way in is commonest can differ between periods.
    reach['stage:' + i] = s.reach
    done['stage:' + i] = { completed: s.completed, visits: s.visits }
    reach[s.step] = s.reach
    done[s.step] = { completed: s.completed, visits: s.visits }
    for (const a of s.alternatives || []) {
      reach[a.step] = a.reach
      done[a.step] = { completed: a.completed, visits: a.visits }
    }
  })
  return { reach, done }
}

const charts = computed(() =>
  funnelsFor(openFlow.value, steps.value).map((fn) => ({
    fn,
    ...previousFigures(openFlow.value, fn),
  }))
)

const detail = computed(() =>
  props.view.step
    ? steps.value.find((s) => s.step === props.view.step) || null
    : null
)

// How many reached the step, and how many came back to it. These are first and later entries to
// the step, and their sum is the visits the rates below are over.
const reachedText = computed(() => {
  const d = detail.value
  if (!d) return ''
  const n = d.entered || 0
  const reached = `${n.toLocaleString()} ${n === 1 ? 'person' : 'people'} reached this step`
  return d.reentered
    ? `${reached} and ${d.reentered.toLocaleString()} came back to it.`
    : `${reached}.`
})

// How long they spent. A time the rollup does not have is left out, not printed as a dash.
const timeText = computed(() => {
  const d = detail.value
  if (!d) return ''
  const has = (v) => v !== null && v !== undefined
  const out = []
  if (has(d.dwell_p50_ms)) out.push(`Typical time here: ${ms(d.dwell_p50_ms)}.`)
  if (has(d.dwell_p90_ms)) {
    out.push(`The slowest tenth took ${ms(d.dwell_p90_ms)} or more.`)
  }
  return out.join(' ')
})

const previousSentence = computed(() => {
  const before = (
    store.funnels.data?.previous?.flows?.find((p) => p.flow === openFlow.value)
      ?.steps || []
  ).find((s) => s.step === props.view.step)
  return before
    ? `Previous period: ${withCount(before.completed, before.visits)} finished the step.`
    : 'This step has no figures in the period before.'
})

// What a breakdown row is called. The keys are what the rollup stores; a reader wants words.
const NO_EXPERIMENT = 'Not in an experiment'
function groupLabel(dim, key) {
  if (dim === 'device_class')
    return key ? deviceLabel(key) : 'No device recorded'
  if (dim === 'logged_in') {
    return String(key) === 'true' ? 'Signed in' : 'Not signed in'
  }
  if (!key) return NO_EXPERIMENT
  return String(key)
    .split(',')
    .map((part) => part.replace(':', ': '))
    .join('; ')
}

const breakdownFields = [
  { key: 'key', label: 'Group' },
  { key: 'visits', label: 'Visits' },
  { key: 'completed', label: 'Finished' },
  { key: 'abandoned', label: 'Left without finishing' },
]

// The three tables of the chosen step. The last one is left out when nobody was in an experiment,
// since a table of one row that says so tells nobody anything.
const dims = computed(() => {
  const by = detail.value?.by || {}
  return [
    { key: 'device_class', label: 'By device' },
    { key: 'logged_in', label: 'By signed in or not' },
    { key: 'variant', label: 'By experiment version' },
  ]
    .map((dim) => ({
      ...dim,
      items: (by[dim.key] || []).map((r) => ({
        key: groupLabel(dim.key, r.key),
        visits: r.visits.toLocaleString(),
        completed: withCount(r.completed, r.visits),
        abandoned: withCount(r.abandoned, r.visits),
      })),
    }))
    .filter(
      (dim) =>
        !(
          dim.key === 'variant' &&
          dim.items.length === 1 &&
          dim.items[0].key === NO_EXPERIMENT
        )
    )
})

// Choosing the open bar again closes it.
function select(step) {
  emit('navigate', { step: props.view.step === step ? undefined : step })
}

function toggleOpen(name) {
  emit('navigate', { open: props.view.open === name ? undefined : name })
}

function also(e, section) {
  if (!isPlainClick(e)) return
  e.preventDefault()
  emit('navigate', { section, flow: openFlow.value })
}

// The field figures are for one funnel, so they are asked for once we know which is open. That is
// after the funnels and the outcomes have settled, because the first funnel with starts is not
// always give, and never for a ux_flow that is no funnel. They carry the dates and filters of the
// funnels, and are asked again when the funnel, the dates or the filters change.
watch(
  () => [
    ready.value,
    openFlow.value,
    JSON.stringify(store.requested('funnels')),
  ],
  () => {
    if (!ready.value) return
    store.loadSlots(['fields'], {
      ...store.requested('funnels'),
      flow: openFlow.value,
    })
  },
  { immediate: true }
)

// A step in the address that the open funnel does not have is taken out of it. Nothing is taken out
// while the funnels are loading, or after they failed.
watch(
  () => [
    ready.value,
    openFlow.value,
    props.view.step,
    steps.value.map((s) => s.step).join('|'),
  ],
  () => {
    if (!ready.value || !store.funnels.data || !props.view.step) return
    if (!steps.value.some((s) => s.step === props.view.step)) {
      emit('navigate', { step: undefined })
    }
  },
  { immediate: true }
)
</script>

<style scoped lang="scss">
.headline {
  font-weight: 500;
}

.how-to-read summary {
  cursor: pointer;
  color: #495057;
}
</style>
