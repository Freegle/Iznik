<template>
  <ModSysAdminUxPanel
    title="Second thoughts"
    explanation="Where people go back a step, return to a field they already filled in, hit a validation error or change their choice. These are signs the step was unclear."
    collapsible
    :open="open"
    :summary="summary"
    :notes="notes"
    :loading="store.funnels.loading || store.fields.loading"
    :error="store.funnels.error || store.fields.error"
    :missing="store.funnels.missing || store.fields.missing"
    :empty="!stepRows.length && !fieldRows.length"
    empty-text="No data for this funnel in this period."
    @toggle="emit('toggle')"
  >
    <h6>Going back, by step</h6>
    <b-table :items="stepRows" :fields="stepFields" small responsive />
    <h6>Fields</h6>
    <b-table :items="fieldRows" :fields="fieldFields" small responsive />
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
const hasFinish = computed(
  () =>
    steps.value.some((s) => s.reached) ||
    (store.fields.data?.fields || []).some((f) => f.reached)
)

// A filter thins the data, so each side needs more visits before it is compared.
const min = computed(() => {
  const a = store.answered.funnels
  return minPeople(!!(a?.device || a?.signed_in))
})

const finishFields = [
  { key: 'finished', label: 'Then finished' },
  { key: 'others', label: 'Others finished' },
  { key: 'verdict', label: 'What it means' },
]
const stepFields = computed(() => [
  { key: 'step', label: 'Step' },
  { key: 'backed', label: 'Went back' },
  { key: 'previous', label: 'Previous period' },
  ...(hasFinish.value ? finishFields : []),
])
const fieldFields = computed(() => [
  { key: 'step', label: 'Step' },
  { key: 'field', label: 'Field' },
  { key: 'revisits', label: 'Revisits' },
  { key: 'validation_failures', label: 'Validation failures' },
  { key: 'choice_switches', label: 'Choice switches' },
  ...(hasFinish.value ? finishFields : []),
])

// A row that shows how the visits with the sign ended. The ones that gave up are shaded.
const finishColumns = (c) =>
  c
    ? {
        finished: finishedCell(c),
        others: othersCell(c),
        verdict: verdictCell(c),
        ...(c.state === 'gave-up' ? { _rowVariant: 'warning' } : {}),
      }
    : {}

const steps = computed(() =>
  stepsWithPrevious(store.funnels.data).filter((s) => s.flow === props.flow)
)

// Each step with how its visits that went back ended; `c` is null when the server sent no figures.
const comparedSteps = computed(() =>
  steps.value.map((s) => ({
    s,
    label: stepName(props.flow, s.step),
    verb: 'went back',
    c: compareFinish(s.backed_then || { people: 0, finished: 0 }, s.reached, {
      min: min.value,
    }),
  }))
)

const stepRows = computed(() =>
  harmFirst(comparedSteps.value).map(({ s, label, c }) => ({
    step: label,
    backed: withCount(s.backed, s.visits),
    previous: s.prev ? withCount(s.prev.backed, s.prev.visits) : '–',
    ...finishColumns(c),
  }))
)

const comparedFields = computed(() =>
  (store.fields.data?.fields || [])
    .filter((f) => f.flow === props.flow)
    .map((f) => {
      const step = stepName(props.flow, f.step)
      return {
        f,
        label: `the ${f.field} field at ${step}`,
        verb: 'changed a field',
        step,
        c: compareFinish(f.then || { people: 0, finished: 0 }, f.reached, {
          min: min.value,
        }),
      }
    })
)

const fieldRows = computed(() =>
  harmFirst(comparedFields.value).map(({ f, step, c }) => ({
    ...f,
    step,
    ...finishColumns(c),
  }))
)

// The step where the biggest share of visits went back. A step needs enough visits to count, so with
// none that has, there is nothing to say yet.
const summary = computed(() => {
  const most = stepWithMost(steps.value, 'backed')
  if (most.state === 'few') return 'Too few visits to say.'
  if (most.state === 'none') {
    return most.elsewhere
      ? 'Nobody went back at a step with enough visits.'
      : 'Nobody went back.'
  }
  const s = most.step
  const where = `Most went back at ${stepName(props.flow, s.step)}: ${pct(s.backed, s.visits)} (${s.backed.toLocaleString()} of ${s.visits.toLocaleString()}).`
  // Then whether going back, or changing a field, is followed by giving up.
  const flag = flagSentence(
    [...comparedSteps.value, ...comparedFields.value],
    'went back or changed a field'
  )
  return flag ? `${where} ${flag}` : where
})

// What the server did with the filters, for the two answers this panel draws, without repeats.
const notes = computed(() => [
  ...new Set([...store.notes('funnels'), ...store.notes('fields')]),
])
</script>
