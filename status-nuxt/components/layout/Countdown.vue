<script setup lang="ts">
import { useStatusStore } from '~/stores/status'

const statusStore = useStatusStore()

const secondsUntilRefresh = ref(30)
let intervalId: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  intervalId = setInterval(() => {
    secondsUntilRefresh.value--
    if (secondsUntilRefresh.value <= 0) {
      statusStore.refreshStatus()
      secondsUntilRefresh.value = 30
    }
  }, 1000)
})

onUnmounted(() => {
  if (intervalId) {
    clearInterval(intervalId)
  }
})

const refreshNow = () => {
  statusStore.refreshStatus()
  secondsUntilRefresh.value = 30
}
</script>

<template>
  <div class="text-muted">
    Next refresh in {{ secondsUntilRefresh }} seconds
    <a href="#" @click.prevent="refreshNow">[refresh now]</a>
  </div>
</template>
