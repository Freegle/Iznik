<template>
  <span :class="`badge bg-${badgeClass}`">
    {{ displayText }}
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  outcome: string | null
  isRunning?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  isRunning: false
})

const badgeClass = computed(() => {
  if (props.isRunning) return 'info'

  switch (props.outcome) {
    case 'completed':
      return 'success'
    case 'timeout':
      return 'warning'
    case 'errored':
      return 'danger'
    default:
      return 'info'
  }
})

const displayText = computed(() => {
  if (props.isRunning) return 'running'
  return props.outcome || 'unknown'
})
</script>
