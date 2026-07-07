<script setup lang="ts">
import { services } from '~/server/utils/services'
import { useStatusStore } from '~/stores/status'

const statusStore = useStatusStore()

const modtoolsServices = computed(() => {
  return services.filter(s => s.category === 'modtools')
})

onMounted(() => {
  statusStore.refreshStatus()
})
</script>

<template>
  <div>
    <ServiceGrid :services="modtoolsServices" title="ModTools Containers" />
  </div>
</template>
