<script setup lang="ts">
import { services } from '~/server/utils/services'
import { useStatusStore } from '~/stores/status'

const statusStore = useStatusStore()

const infraServices = computed(() => {
  return services.filter(s => s.category === 'infrastructure')
})

onMounted(() => {
  statusStore.refreshStatus()
})
</script>

<template>
  <div>
    <ServiceGrid :services="infraServices" title="Infrastructure Services" />
  </div>
</template>
