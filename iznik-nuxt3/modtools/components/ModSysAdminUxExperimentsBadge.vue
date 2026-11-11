<template>
  <span
    v-if="text"
    class="ux-exp-badge badge ms-1"
    :class="tone"
    :title="title || undefined"
    >{{ text }}</span
  >
</template>

<script setup>
// Drawn in the tab's nav after the word Experiments. A red number says how many experiments have a
// verdict that nobody has closed: adopted ones to confirm and take the switch out of, and ended ones
// whose variant code is still in. That matters more than the blue count of running ones, which shows
// only when nothing is waiting. It reads the same list as the section, so it adds no route. A missing
// route or an error leaves it empty: the section says what is wrong, and the nav should stay quiet.
import { computed, onMounted } from 'vue'
import { useUxExperimentsStore } from '~/modtools/stores/uxExperiments'
import {
  verdictCounts,
  verdictTitle,
} from '~/modtools/composables/useUxVerdict'

const store = useUxExperimentsStore()

// The section asks too. A list younger than 30 seconds is not asked for again.
onMounted(() => {
  store.loadIfStale(30000)
})

const counts = computed(() => verdictCounts(store.experiments.data?.flows))
const running = computed(
  () => Number(store.experiments.data?.running_count) || 0
)

const text = computed(() => {
  if (counts.value.total) return String(counts.value.total)
  return running.value > 0 ? `${running.value} running` : ''
})
const title = computed(() => {
  if (!counts.value.total) return ''
  const waiting = verdictTitle(counts.value.confirm, counts.value.remove)
  return running.value > 0 ? `${waiting}. ${running.value} running.` : waiting
})
const tone = computed(() => (counts.value.total ? 'bg-danger' : 'ux-blue'))
</script>

<style scoped lang="scss">
// The ModTools theme makes Bootstrap's primary green. The count of running experiments is blue.
.ux-blue {
  background-color: #0b5ed7;
  color: #fff;
}
</style>
