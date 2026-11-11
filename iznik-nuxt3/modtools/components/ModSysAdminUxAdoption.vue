<template>
  <ModSysAdminUxPanel
    title="Adoption"
    explanation="How many of the people who were active used each feature. A feature almost nobody uses may be hidden, or not wanted."
    collapsible
    :open="open"
    :summary="summary"
    :notes="notes"
    :loading="store.adoption.loading"
    :error="store.adoption.error"
    :missing="store.adoption.missing"
    :empty="!rows.length"
    empty-text="No adoption data for this period."
    @toggle="emit('toggle')"
  >
    <b-table :items="rows" :fields="fields" small responsive />
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import { withCount, signed } from '~/modtools/composables/useUxFormat'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

defineProps({
  // Whether the person opened it. The section keeps this in the address.
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle'])

const store = useUxStore()

const fields = [
  { key: 'feature', label: 'Feature' },
  { key: 'using', label: 'Users of it / active users' },
  { key: 'sessions', label: 'Sessions using it' },
  { key: 'previous', label: 'Previous period' },
  { key: 'change', label: 'Change' },
]

// share and previous_share are fractions (0 to 1).
const asPct = (x) => `${(x * 100).toFixed(1)}%`

const features = computed(() => store.adoption.data?.features || [])

const rows = computed(() =>
  features.value.map((f) => ({
    feature: f.feature,
    using: withCount(f.using_users, f.active_users),
    sessions: (f.sessions_using || 0).toLocaleString(),
    previous: asPct(f.previous_share || 0),
    change: `${signed((f.share - (f.previous_share || 0)) * 100)} points`,
  }))
)

// The least and the most used. On a tie the first one the server sent is named.
const summary = computed(() => {
  const list = features.value
  if (!list.length) return ''
  if (list.length === 1) {
    return `1 feature: ${list[0].feature} (${asPct(list[0].share || 0)}).`
  }
  let least = list[0]
  let most = list[0]
  for (const f of list) {
    if ((f.share || 0) < (least.share || 0)) least = f
    if ((f.share || 0) > (most.share || 0)) most = f
  }
  return `${list.length} features. Least used: ${least.feature} (${asPct(least.share || 0)}). Most used: ${most.feature} (${asPct(most.share || 0)}).`
})

const notes = computed(() => store.notes('adoption'))
</script>
