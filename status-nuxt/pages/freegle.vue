<script setup lang="ts">
import { services } from '~/server/utils/services'
import { useStatusStore } from '~/stores/status'
import { api } from '~/api'

const statusStore = useStatusStore()

// Filter services for Freegle category
const freegleServices = computed(() => {
  return services.filter(s => s.category === 'freegle')
})

const isRecreatingUsers = ref(false)

const recreateTestUsers = async () => {
  isRecreatingUsers.value = true
  try {
    await api().status.recreateTestUsers()
    alert('Test users recreated successfully')
  }
  catch (err) {
    console.error('Failed to recreate test users:', err)
    alert('Failed to recreate test users')
  }
  finally {
    isRecreatingUsers.value = false
  }
}

// Fetch status on mount
onMounted(() => {
  statusStore.refreshStatus()
})
</script>

<template>
  <div>
    <h2 class="h5 mb-3">Freegle (User Site)</h2>
    <button
      class="action-button mb-3"
      :disabled="isRecreatingUsers"
      @click="recreateTestUsers"
    >
      {{ isRecreatingUsers ? 'Recreating...' : 'Recreate Test Users' }}
    </button>
    <ServiceGrid :services="freegleServices" />
  </div>
</template>
