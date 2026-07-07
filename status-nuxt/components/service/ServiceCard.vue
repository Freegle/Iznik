<script setup lang="ts">
import type { ServiceConfig, ServiceState } from '~/types/service'
import { useStatusStore } from '~/stores/status'

const props = defineProps<{
  service: ServiceConfig
}>()

const statusStore = useStatusStore()

const state = computed((): ServiceState => {
  return statusStore.getServiceState(props.service.id) || {
    id: props.service.id,
    status: 'unknown',
    lastChecked: new Date(),
  }
})

const cpu = computed(() => {
  return statusStore.getCpuUsage(props.service.id)
})

const statusClass = computed(() => {
  switch (state.value.status) {
    case 'online': return 'online'
    case 'offline': return 'offline'
    case 'loading': return 'loading'
    default: return 'unknown'
  }
})

const cpuLevel = computed(() => {
  const usage = cpu.value
  if (usage === undefined) return null
  if (usage > 80) return 'high'
  if (usage > 50) return 'medium'
  return ''
})

const isRestarting = ref(false)
const isRebuilding = ref(false)

const handleRestart = async () => {
  isRestarting.value = true
  try {
    await statusStore.restartContainer(props.service.container || props.service.id)
  }
  catch (err) {
    console.error('Restart failed:', err)
  }
  finally {
    isRestarting.value = false
  }
}

const handleRebuild = async () => {
  isRebuilding.value = true
  try {
    await statusStore.rebuildContainer(props.service.container || props.service.id)
  }
  catch (err) {
    console.error('Rebuild failed:', err)
  }
  finally {
    isRebuilding.value = false
  }
}

const handleLogs = () => {
  navigateTo(`/logs/${props.service.container || props.service.id}`)
}

// Compute a short name for the Visit button
const visitLabel = computed(() => {
  // Extract a short name from the service name
  const name = props.service.name
  // Remove common prefixes/suffixes for cleaner button text
  return `Visit ${name.replace(/\s*\(Local\)|\s*\(Live\)/gi, '').trim()}`
})
</script>

<template>
  <div :class="['service', statusClass]">
    <!-- CPU indicator in top right -->
    <div v-if="cpu !== undefined" class="cpu-indicator">
      <div :class="['cpu-bar', cpuLevel]" :style="{ width: cpu + '%' }" />
      <span class="cpu-text">{{ cpu.toFixed(0) }}% CPU</span>
    </div>

    <div class="service-info">
      <div class="service-title">{{ service.name }}</div>
      <div v-if="service.description" class="service-description">
        {{ service.description }}
      </div>
      <div v-if="service.url" class="service-url">
        <a :href="service.url" target="_blank">{{ service.url }}</a>
      </div>
      <div v-if="service.credentials" class="service-credentials">
        User: {{ service.credentials.username }} / Pass: {{ service.credentials.password }}
      </div>
      <div v-if="service.container" class="service-container">
        Container: {{ service.container }}
        <a href="#" class="logs-link" @click.prevent="handleLogs">[logs]</a>
      </div>
      <div class="service-status">
        {{ state.status === 'online' ? 'Online' : state.status === 'offline' ? 'Offline' : state.status === 'loading' ? 'Loading...' : 'Unknown' }}
      </div>
      <div v-if="state.uptime" class="service-uptime">
        Uptime: {{ state.uptime }}
      </div>
    </div>

    <div class="service-actions">
      <a v-if="service.url" :href="service.url" target="_blank" class="visit-button">
        {{ visitLabel }}
      </a>
      <div v-if="service.container" class="container-actions">
        <button
          class="action-button"
          :disabled="isRestarting"
          @click="handleRestart"
        >
          {{ isRestarting ? 'Restarting...' : 'Restart' }}
        </button>
        <button
          class="action-button danger"
          :disabled="isRebuilding"
          @click="handleRebuild"
        >
          {{ isRebuilding ? 'Rebuilding...' : 'Rebuild' }}
        </button>
      </div>
    </div>

    <slot name="footer" />
  </div>
</template>
