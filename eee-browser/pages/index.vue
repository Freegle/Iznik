<template>
  <div class="min-h-screen bg-gray-950 text-gray-100">
    <header class="bg-gray-900 border-b border-gray-800 px-6 py-5">
      <div class="max-w-6xl mx-auto">
        <h1 class="text-2xl font-bold text-white">EEE Classification Browser</h1>
        <p class="text-gray-500 text-sm mt-1">
          Comparing Claude vs Gemini component extraction across {{ items.length }} item types
        </p>
      </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-8">
      <div v-if="loading" class="text-center py-20 text-gray-500">Loading…</div>
      <div v-else-if="error" class="bg-red-900/30 border border-red-700 rounded-lg p-4 text-red-300">{{ error }}</div>

      <template v-else>
        <!-- Summary stats -->
        <div class="grid grid-cols-3 gap-4 mb-8">
          <div class="bg-gray-900 rounded-xl border border-gray-800 p-4 text-center">
            <div class="text-3xl font-bold text-white">{{ items.length }}</div>
            <div class="text-gray-500 text-sm mt-1">Item types</div>
          </div>
          <div class="bg-gray-900 rounded-xl border border-gray-800 p-4 text-center">
            <div class="text-3xl font-bold text-white">{{ totalSamples }}</div>
            <div class="text-gray-500 text-sm mt-1">Sample images</div>
          </div>
          <div class="bg-gray-900 rounded-xl border border-gray-800 p-4 text-center">
            <div class="text-3xl font-bold" :class="avgAgreement >= 80 ? 'text-green-400' : avgAgreement >= 60 ? 'text-amber-400' : 'text-red-400'">
              {{ avgAgreement }}%
            </div>
            <div class="text-gray-500 text-sm mt-1">Avg EEE agreement</div>
          </div>
        </div>

        <!-- Item types table -->
        <div class="bg-gray-900 rounded-2xl border border-gray-800 overflow-hidden">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-800 text-xs text-gray-500 uppercase tracking-wider">
                <th class="text-left px-5 py-3 font-medium">Item type</th>
                <th class="text-left px-4 py-3 font-medium">WEEE category</th>
                <th class="text-center px-4 py-3 font-medium text-blue-400">Claude EEE</th>
                <th class="text-center px-4 py-3 font-medium text-violet-400">Gemini EEE</th>
                <th class="text-center px-4 py-3 font-medium">Agreement</th>
                <th class="text-center px-4 py-3 font-medium">Samples</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in items"
                :key="item.itemName"
                class="border-t border-gray-800/60 hover:bg-gray-800/40 cursor-pointer transition"
                @click="navigateTo(`/item/${encodeURIComponent(item.itemName)}`)"
              >
                <td class="px-5 py-3 font-medium text-blue-400 hover:text-blue-300">
                  {{ item.itemName }}
                </td>
                <td class="px-4 py-3 text-gray-400 text-xs">{{ item.weeCategory || '—' }}</td>
                <td class="px-4 py-3 text-center">
                  <span class="text-xs font-mono text-gray-200">
                    {{ item.claudeEeeCount }}<span class="text-gray-600">/{{ item.claudeTotal }}</span>
                  </span>
                </td>
                <td class="px-4 py-3 text-center">
                  <span class="text-xs font-mono text-gray-200">
                    {{ item.geminiEeeCount }}<span class="text-gray-600">/{{ item.geminiTotal }}</span>
                  </span>
                </td>
                <td class="px-4 py-3 text-center">
                  <span
                    class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium"
                    :class="agreeClass(item.agreeRate)"
                  >
                    {{ formatPct(item.agreeRate) }}
                  </span>
                </td>
                <td class="px-4 py-3 text-center text-gray-500 text-xs">{{ item.sampleSize }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Footer links -->
        <div class="mt-6 flex gap-4">
          <NuxtLink
            to="/components"
            class="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-800 hover:bg-gray-700 text-gray-200 rounded-lg text-sm font-medium transition border border-gray-700"
          >
            Component index →
          </NuxtLink>
        </div>
      </template>
    </main>
  </div>
</template>

<script setup lang="ts">
const items = ref<any[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const totalSamples = computed(() => items.value.reduce((sum, i) => sum + (i.sampleSize || 0), 0))
const avgAgreement = computed(() => {
  const rated = items.value.filter((i) => i.agreeRate !== null && i.agreeRate !== undefined)
  if (!rated.length) return 0
  return Math.round((rated.reduce((s, i) => s + i.agreeRate, 0) / rated.length) * 100)
})

const formatPct = (rate: number | null) => {
  if (rate === null || rate === undefined) return '—'
  return `${Math.round(rate * 100)}%`
}

const agreeClass = (rate: number | null) => {
  if (rate === null) return 'bg-gray-800 text-gray-500'
  if (rate >= 0.8) return 'bg-green-900/60 text-green-300'
  if (rate >= 0.5) return 'bg-amber-900/60 text-amber-300'
  return 'bg-red-900/60 text-red-400'
}

onMounted(async () => {
  try {
    const response = await fetch('/api/item-types')
    if (!response.ok) throw new Error(`API error: ${response.statusText}`)
    const data = await response.json()
    items.value = data.items || []
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    loading.value = false
  }
})
</script>
