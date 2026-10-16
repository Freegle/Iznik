<template>
  <span v-if="text" class="ux-exp-badge badge ms-1" :class="tone">{{
    text
  }}</span>
</template>

<script setup>
// Drawn in the tab's nav after the word Experiments: how many are running, or that a cleanup is
// overdue, which matters more. It reads the same list as the section, so it adds no route. A missing
// route or an error leaves it empty: the section says what is wrong, and the nav should stay quiet.
import { computed, onMounted } from 'vue'
import { useUxExperimentsStore } from '~/modtools/stores/uxExperiments'

const store = useUxExperimentsStore()

// The section asks too. A list younger than 30 seconds is not asked for again.
onMounted(() => {
  store.loadIfStale(30000)
})

const overdue = computed(() =>
  (store.experiments.data?.flows || []).some((f) =>
    [...(f.running || []), ...(f.history || [])].some((e) => e.cleanup_overdue)
  )
)
const running = computed(
  () => Number(store.experiments.data?.running_count) || 0
)

const text = computed(() => {
  if (overdue.value) return 'Cleanup overdue'
  return running.value > 0 ? `${running.value} running` : ''
})
const tone = computed(() => (overdue.value ? 'bg-danger' : 'ux-blue'))
</script>

<style scoped lang="scss">
// The ModTools theme makes Bootstrap's primary green. The count of running experiments is blue.
.ux-blue {
  background-color: #0b5ed7;
  color: #fff;
}
</style>
