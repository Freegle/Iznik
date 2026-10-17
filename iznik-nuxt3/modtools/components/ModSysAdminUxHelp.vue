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
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

const props = defineProps({
  // The funnel to show. Only its rows are drawn.
  flow: { type: String, required: true },
  // Whether the person opened it. The section keeps this in the address.
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle'])

const store = useUxStore()

const fields = [
  { key: 'step', label: 'Step' },
  { key: 'help', label: 'Sought help' },
  { key: 'previous', label: 'Previous period' },
]

const steps = computed(() =>
  stepsWithPrevious(store.funnels.data).filter((s) => s.flow === props.flow)
)

const rows = computed(() =>
  steps.value.map((s) => ({
    step: stepName(props.flow, s.step),
    help: withCount(s.help_sought, s.visits),
    previous: s.prev ? withCount(s.prev.help_sought, s.prev.visits) : '–',
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
  return `Most asked for help at ${stepName(props.flow, s.step)}: ${pct(s.help_sought, s.visits)} (${s.help_sought.toLocaleString()} of ${s.visits.toLocaleString()}).`
})

const notes = computed(() => store.notes('funnels'))
</script>
