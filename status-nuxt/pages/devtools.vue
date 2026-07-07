<script setup lang="ts">
import { services } from '~/server/utils/services'
import { useStatusStore } from '~/stores/status'

const statusStore = useStatusStore()

const devtoolsServices = computed(() => {
  return services.filter(s => s.category === 'devtools')
})

onMounted(() => {
  statusStore.refreshStatus()
})
</script>

<template>
  <div>
    <ServiceGrid :services="devtoolsServices" title="Development Tools" />
  </div>
</template>
