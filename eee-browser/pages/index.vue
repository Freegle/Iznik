<template>
  <div class="min-h-screen bg-gray-100 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-7xl mx-auto">
      <div class="mb-8">
        <h1 class="text-4xl font-bold text-gray-900 mb-2">EEE Classification Browser</h1>
        <p class="text-gray-600">Browse item types and their model classification results</p>
      </div>

      <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Item Type
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  WEEE Category
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Claude EEE
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Gemini EEE
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Agreement
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Samples
                </th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr
                v-for="item in items"
                :key="item.itemName"
                class="hover:bg-gray-50 cursor-pointer transition"
                @click="navigateTo(`/item/${encodeURIComponent(item.itemName)}`)"
              >
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                  {{ item.itemName }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ item.weeCategory || 'N/A' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ item.claudeEeeCount }} / {{ item.claudeTotal }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ item.geminiEeeCount }} / {{ item.geminiTotal }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <span
                    :class="
                      item.agreeRate > 0.8
                        ? 'agreement'
                        : item.agreeRate > 0.5
                          ? 'bg-yellow-50'
                          : 'disagreement'
                    "
                    class="inline-block px-3 py-1 rounded text-sm font-medium"
                  >
                    {{ (item.agreeRate * 100).toFixed(0) }}%
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ item.sampleSize }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="mt-8 flex gap-4">
        <NuxtLink
          to="/components"
          class="inline-block px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium"
        >
          View Component Index
        </NuxtLink>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const items = ref<any[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    const response = await fetch('/api/item-types')
    if (!response.ok) {
      throw new Error(`API error: ${response.statusText}`)
    }
    const data = await response.json()
    items.value = data.items || []
  } catch (e) {
    error.value = (e as Error).message
    console.error('Failed to load item types:', e)
  } finally {
    loading.value = false
  }
})
</script>
