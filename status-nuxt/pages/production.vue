<script setup lang="ts">
import { services } from '~/server/utils/services'
import { useStatusStore } from '~/stores/status'

const statusStore = useStatusStore()

const getService = (id: string) => services.find(s => s.id === id)

const freegleDevLive = getService('freegle-dev-live')!
const modtoolsDevLive = getService('modtools-dev-live')!
const apiv2Live = getService('apiv2-live')!

const isTogglingFreegle = ref(false)
const isTogglingModtools = ref(false)
const toggleError = ref('')

const handleToggle = async (target: 'freegle' | 'modtools') => {
  const isToggling = target === 'freegle' ? isTogglingFreegle : isTogglingModtools
  isToggling.value = true
  toggleError.value = ''
  try {
    const currentState = target === 'freegle' ? statusStore.liveV2.freegle : statusStore.liveV2.modtools
    await statusStore.toggleLiveV2(target, !currentState)
  } catch (err: any) {
    toggleError.value = `Failed to toggle ${target}: ${err.message || 'Unknown error'}`
  } finally {
    isToggling.value = false
  }
}

onMounted(() => {
  statusStore.refreshStatus()
  statusStore.refreshLiveV2Status()
})
</script>

<template>
  <div>
    <div class="alert alert-warning mb-3">
      <strong>Warning:</strong> These services connect to PRODUCTION APIs or the production database.
      Changes here affect real users.
    </div>

    <div class="alert alert-info mb-3">
      <p class="mb-2">
        <strong>Local V2 API:</strong> The API v2 (Live DB) container connects the Go API server
        to the <strong>production database</strong> via an SSH tunnel, allowing you to test local
        code changes against real data. The dev-live containers can be toggled to use it instead
        of the live V2 API.
      </p>
      <p class="mb-0">
        <strong>Configuration:</strong> The tunnel port is configurable via <code>LIVE_DB_PORT</code>
        in <code>.env</code> (currently <strong>{{ statusStore.liveV2.liveDbPort }}</strong>).
        Set <code>LIVE_DB_PASSWORD</code> to the production MySQL root password.
      </p>
    </div>

    <div v-if="toggleError" class="alert alert-danger mb-3">
      {{ toggleError }}
    </div>

    <!-- Freegle Dev Live -->
    <h2 class="section-heading h5 mb-3">Freegle</h2>
    <div class="services-list">
      <ServiceCard :service="freegleDevLive">
        <template #footer>
          <div :class="['v2-toggle', statusStore.liveV2.freegle ? 'local' : '']">
            <span class="v2-label">
              V2 API:
              <strong>{{ statusStore.liveV2.freegle ? 'Local V2 API' : 'Live V2 API' }}</strong>
            </span>
            <button
              :class="['toggle-button', statusStore.liveV2.freegle ? 'active' : '']"
              :disabled="isTogglingFreegle"
              @click="handleToggle('freegle')"
            >
              {{ isTogglingFreegle ? 'Switching...' : statusStore.liveV2.freegle ? 'Switch to Live' : 'Switch to Local' }}
            </button>
          </div>
        </template>
      </ServiceCard>
    </div>

    <!-- ModTools Dev Live -->
    <h2 class="section-heading h5 mb-3 mt-4">ModTools</h2>
    <div class="services-list">
      <ServiceCard :service="modtoolsDevLive">
        <template #footer>
          <div :class="['v2-toggle', statusStore.liveV2.modtools ? 'local' : '']">
            <span class="v2-label">
              V2 API:
              <strong>{{ statusStore.liveV2.modtools ? 'Local V2 API' : 'Live V2 API' }}</strong>
            </span>
            <button
              :class="['toggle-button', statusStore.liveV2.modtools ? 'active' : '']"
              :disabled="isTogglingModtools"
              @click="handleToggle('modtools')"
            >
              {{ isTogglingModtools ? 'Switching...' : statusStore.liveV2.modtools ? 'Switch to Live' : 'Switch to Local' }}
            </button>
          </div>
        </template>
      </ServiceCard>
    </div>

    <!-- API v2 Live DB container -->
    <h2 class="section-heading h5 mb-3 mt-4">Local V2 API Container</h2>
    <div class="services-list">
      <ServiceCard :service="apiv2Live" />
    </div>
  </div>
</template>

<style scoped>
.v2-toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  margin-top: 0.75rem;
  padding-top: 0.6rem;
  border-top: 1px solid rgba(0, 0, 0, 0.15);
  font-size: 0.85rem;
  color: #555;
}

.v2-toggle.local {
  color: #155724;
}

.v2-label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.toggle-button {
  padding: 0.25rem 0.75rem;
  border: 1px solid #6c757d;
  border-radius: 4px;
  background: white;
  color: #333;
  cursor: pointer;
  font-size: 0.8rem;
  white-space: nowrap;
  transition: all 0.2s;
}

.toggle-button:hover:not(:disabled) {
  border-color: #495057;
  background: #f8f9fa;
}

.toggle-button.active {
  background: #28a745;
  border-color: #28a745;
  color: white;
}

.toggle-button.active:hover:not(:disabled) {
  background: #218838;
}

.toggle-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
