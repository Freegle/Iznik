<template>
  <span :class="`badge bg-${badgeClass}`">
    {{ state }}
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  state: string
}

const props = defineProps<Props>()

const badgeClass = computed(() => {
  switch (props.state) {
    case 'open':
      return 'secondary'
    case 'investigating':
      return 'warning'
    case 'fix-queued':
      return 'info'
    case 'deferred':
      return 'warning'
    case 'fixed':
    case 'confirmed':
      return 'success'
    default:
      return 'secondary'
  }
})
</script>
