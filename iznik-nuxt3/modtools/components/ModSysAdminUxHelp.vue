<template>
  <ModSysAdminUxPanel
    title="Help"
    explanation="Where people stop to look for help while in this funnel. A step with a lot of help-seeking is one people find confusing."
    collapsible
    :open="open"
    :summary="summary"
    :notes="notes"
    :loading="store.funnels.loading"
    :error="store.funnels.error"
    :missing="store.funnels.missing"
    :empty="!rows.length"
    empty-text="No data for this funnel in this period."
    @toggle="emit('toggle')"
  >
    <b-table :items="rows" :fields="fields" small responsive />
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import {
  withCount,
  pct,
  stepsWithPrevious,
} from '~/modtools/composables/useUxFormat'
import { stepName, stepWithMost } from '~/modtools/composables/useUxFunnel'
import {
  minPeople,
  compareFinish,
  finishedCell,
  othersCell,
  verdictCell,
  harmFirst,
  flagSentence,
} from '~/modtools/composables/useUxThenFinished'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

const props = defineProps({
  // The funnel to show. Only its rows are drawn.
  flow: { type: String, required: true },
  // Whether the person opened it. The section keeps this in the address.
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle'])

const store = useUxStore()

// Whether the server sent the finish figures. An older server, or a period the rollup has not covered,
// does not, and the columns are then left out rather than drawn empty.
const hasFinish = computed(() => steps.value.some((s) => s.reached))

const fields = computed(() => [
  { key: 'step', label: 'Step' },
  { key: 'help', label: 'Sought help' },
  { key: 'previous', label: 'Previous period' },
  ...(hasFinish.value
    ? [
        { key: 'finished', label: 'Then finished' },
        { key: 'others', label: 'Others finished' },
        { key: 'verdict', label: 'What it means' },
      ]
    : []),
])

const steps = computed(() =>
  stepsWithPrevious(store.funnels.data).filter((s) => s.flow === props.flow)
)

// A filter thins the data, so each side needs more visits before it is compared.
const min = computed(() => {
  const a = store.answered.funnels
  return minPeople(!!(a?.device || a?.signed_in))
})

// Each step with how its visits that asked for help ended; `c` is null when the server sent no figures.
const compared = computed(() =>
  steps.value.map((s) => ({
    s,
    label: stepName(props.flow, s.step),
    verb: 'asked for help',
    c: compareFinish(s.help_then || { people: 0, finished: 0 }, s.reached, {
      min: min.value,
    }),
  }))
)

const rows = computed(() =>
  harmFirst(compared.value).map(({ s, label, c }) => ({
    step: label,
    help: withCount(s.help_sought, s.visits),
    previous: s.prev ? withCount(s.prev.help_sought, s.prev.visits) : '–',
    ...(c
      ? {
          finished: finishedCell(c),
          others: othersCell(c),
          verdict: verdictCell(c),
          ...(c.state === 'gave-up' ? { _rowVariant: 'warning' } : {}),
        }
      : {}),
  }))
)

// The step where the biggest share of visits asked for help. A step needs enough visits to count, so
// with none that has, there is nothing to say yet.
const summary = computed(() => {
  const most = stepWithMost(steps.value, 'help_sought')
  if (most.state === 'few') return 'Too few visits to say.'
  if (most.state === 'none') {
    return most.elsewhere
      ? 'Nobody asked for help at a step with enough visits.'
      : 'Nobody asked for help.'
  }
  const s = most.step
  const where = `Most asked for help at ${stepName(props.flow, s.step)}: ${pct(s.help_sought, s.visits)} (${s.help_sought.toLocaleString()} of ${s.visits.toLocaleString()}).`
  // Then whether asking for help is followed by giving up.
  const flag = flagSentence(compared.value, 'asked for help')
  return flag ? `${where} ${flag}` : where
})

const notes = computed(() => store.notes('funnels'))
</script>
