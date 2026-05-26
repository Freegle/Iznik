<template>
  <div class="min-h-screen bg-gray-100 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-7xl mx-auto">
      <div class="mb-8">
        <NuxtLink to="/" class="text-blue-600 hover:text-blue-800 mb-4 inline-block">
          ← Back to Items
        </NuxtLink>
        <h1 class="text-4xl font-bold text-gray-900">EEE Component Index</h1>
        <p class="text-gray-600 mt-2">All recognized electrical components and their usage counts</p>
      </div>

      <div v-if="loading" class="text-center py-12">
        <p class="text-gray-600">Loading component data...</p>
      </div>

      <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-8">
        <p class="text-red-800">{{ error }}</p>
      </div>

      <div v-else class="space-y-8">
        <!-- Primary EEE Components -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
          <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4">
            <h2 class="text-2xl font-bold text-white">Primary EEE Components</h2>
            <p class="text-blue-100 text-sm">Main electrical equipment categories</p>
          </div>
          <div class="overflow-x-auto">
            <ComponentTable :components="components.primary_eee" />
          </div>
        </div>

        <!-- Supplementary EEE Components -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
          <div class="bg-gradient-to-r from-amber-600 to-amber-700 px-6 py-4">
            <h2 class="text-2xl font-bold text-white">Supplementary EEE Components</h2>
            <p class="text-amber-100 text-sm">Supporting or ancillary electrical components</p>
          </div>
          <div class="overflow-x-auto">
            <ComponentTable :components="components.supplementary_eee" />
          </div>
        </div>

        <!-- Non-Electrical Components -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
          <div class="bg-gradient-to-r from-gray-600 to-gray-700 px-6 py-4">
            <h2 class="text-2xl font-bold text-white">Non-Electrical Components</h2>
            <p class="text-gray-100 text-sm">Materials and parts without electrical function</p>
          </div>
          <div class="overflow-x-auto">
            <ComponentTable :components="components.non_electrical" />
          </div>
        </div>

        <!-- Unknown Category -->
        <div v-if="components.unknown && components.unknown.length > 0" class="bg-white rounded-lg shadow overflow-hidden">
          <div class="bg-gradient-to-r from-gray-400 to-gray-500 px-6 py-4">
            <h2 class="text-2xl font-bold text-white">Uncategorized Components</h2>
            <p class="text-gray-100 text-sm">Components pending categorization</p>
          </div>
          <div class="overflow-x-auto">
            <ComponentTable :components="components.unknown" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const components = ref<any>({
  primary_eee: [],
  supplementary_eee: [],
  non_electrical: [],
  unknown: [],
})
const loading = ref(true)
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    const response = await fetch('/api/components')
    if (!response.ok) {
      throw new Error(`API error: ${response.statusText}`)
    }
    const data = await response.json()
    components.value = data
  } catch (e) {
    error.value = (e as Error).message
    console.error('Failed to load components:', e)
  } finally {
    loading.value = false
  }
})
</script>
