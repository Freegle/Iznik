<template>
  <div class="ux-overview">
    <ModSysAdminUxHealth
      :open="view.open === 'health'"
      @toggle="toggle('health')"
    />
    <ModSysAdminUxPanel
      title="All funnels"
      explanation="One row for each funnel. Open one to see where people left."
      :loading="waiting"
      :error="store.funnels.error || store.outcomes.error"
      :missing="store.funnels.missing || store.outcomes.missing"
      :notes="notes"
    >
      <ModSysAdminUxFunnelTable
        :rows="rows"
        :href-for="hrefFor"
        @navigate="(patch) => emit('navigate', patch)"
      />
    </ModSysAdminUxPanel>
  </div>
</template>

<script setup>
import { computed, inject } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import { settled } from '~/modtools/composables/useUxSlot'
import { FLOW_ORDER } from '~/modtools/composables/useUxFlows'
import { summariseFlow } from '~/modtools/composables/useUxFunnel'
import ModSysAdminUxHealth from '~/modtools/components/ModSysAdminUxHealth.vue'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'
import ModSysAdminUxFunnelTable from '~/modtools/components/ModSysAdminUxFunnelTable.vue'

const props = defineProps({
  // The view from useUxView.
  view: { type: Object, required: true },
})
const emit = defineEmits(['navigate'])

// The tab gives every section a way to get the address of a change, for links that can be copied.
const hrefFor = inject('uxHrefFor', () => '#')

const store = useUxStore()

// Draw the table only once both answers are in: before that an empty table reads as "nobody started".
// A read that has failed is shown at once, without waiting for the other.
const ready = computed(() => settled(store.funnels) && settled(store.outcomes))
const waiting = computed(
  () =>
    !(
      store.funnels.error ||
      store.outcomes.error ||
      store.funnels.missing ||
      store.outcomes.missing
    ) &&
    (!ready.value || store.funnels.loading || store.outcomes.loading)
)

// One row for each funnel, always in the same order, so rows never move. A flow the API returns that
// is not one of the seven is added at the end rather than hidden.
const rows = computed(() => {
  const funnels = store.funnels.data?.flows || []
  const outcomes = store.outcomes.data?.flows || []
  const before = store.outcomes.data?.previous?.flows || []
  const keys = [...FLOW_ORDER]
  for (const f of [...funnels, ...outcomes]) {
    if (!keys.includes(f.flow)) keys.push(f.flow)
  }
  return keys.map((flow) =>
    summariseFlow(
      flow,
      outcomes.find((o) => o.flow === flow),
      funnels.find((f) => f.flow === flow)?.steps || [],
      before.find((o) => o.flow === flow),
      { filtered: props.view.filtersActive }
    )
  )
})

const notes = computed(() => [
  ...new Set([...store.notes('funnels'), ...store.notes('outcomes')]),
])

function toggle(name) {
  emit('navigate', { open: props.view.open === name ? undefined : name })
}
</script>
