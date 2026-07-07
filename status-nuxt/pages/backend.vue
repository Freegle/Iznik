<script setup lang="ts">
import { services } from '~/server/utils/services'
import { useStatusStore } from '~/stores/status'

const statusStore = useStatusStore()

const backendServices = computed(() => {
  return services.filter(s => s.category === 'backend')
})

const mcpServices = computed(() => {
  return services.filter(s => s.category === 'mcp')
})

onMounted(() => {
  statusStore.refreshStatus()
})
</script>

<template>
  <div>
    <ServiceGrid :services="backendServices" title="Backend Services" />

    <div v-if="mcpServices.length > 0" class="mt-4">
      <ServiceGrid :services="mcpServices" title="MCP Tools (Privacy-Preserving Analysis)" />
    </div>
  </div>
</template>
