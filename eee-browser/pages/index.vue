<template>
  <div class="min-h-screen bg-gray-950 text-gray-100">
    <header class="bg-gray-900 border-b border-gray-800 px-6 py-5">
      <div class="max-w-6xl mx-auto">
        <h1 class="text-2xl font-bold text-white">EEE Classification Browser</h1>
        <p class="text-gray-500 text-sm mt-1">Multi-model WEEE identification research</p>
      </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-8">
      <div v-if="loading" class="text-center py-20 text-gray-500">Loading…</div>
      <div v-else-if="error" class="bg-red-900/30 border border-red-700 rounded-lg p-4 text-red-300">{{ error }}</div>

      <template v-else>
        <!-- Research cost summary -->
        <div class="bg-gray-900 rounded-2xl border border-gray-800 p-5 mb-8">
          <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4">Research cost</h2>
          <div class="grid grid-cols-3 gap-6">
            <!-- What we've processed -->
            <div>
              <div class="text-xs text-gray-500 mb-2">Processed</div>
              <div class="space-y-1">
                <div class="flex justify-between text-sm">
                  <span class="text-gray-400">Item types</span>
                  <span class="text-white font-medium">{{ items.length }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-gray-400">Sample images</span>
                  <span class="text-white font-medium">{{ totalSamples.toLocaleString() }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-gray-400">Models tested</span>
                  <span class="text-white font-medium">{{ modelScores ? modelScores.models.length : '—' }}</span>
                </div>
              </div>
            </div>

            <!-- Actual spend -->
            <div>
              <div class="text-xs text-gray-500 mb-2">Actual spend</div>
              <div class="space-y-1">
                <div v-for="m in costByMonth" :key="m.month">
                  <div class="flex justify-between text-sm">
                    <span class="text-gray-400">{{ formatMonth(m.month) }}</span>
                    <span class="text-amber-400 font-medium">${{ m.total.toFixed(2) }}</span>
                  </div>
                  <div v-for="mdl in m.models" :key="mdl.model" class="flex justify-between text-xs pl-3 text-gray-600">
                    <span>{{ formatModelName(mdl.model) }}</span>
                    <span>${{ mdl.cost.toFixed(2) }}</span>
                  </div>
                </div>
                <div class="flex justify-between text-sm pt-1 border-t border-gray-800 mt-1">
                  <span class="text-gray-400">Total</span>
                  <span class="text-amber-400 font-bold">${{ totalCost }}</span>
                </div>
              </div>
            </div>

            <!-- Annual cost projections -->
            <div>
              <div class="text-xs text-gray-500 mb-2">Est. annual cost at Freegle scale ({{ annualOffers.toLocaleString() }} offers/yr)</div>
              <div class="space-y-2">
                <div v-for="ac in annualCosts" :key="ac.model" class="flex justify-between items-baseline text-sm">
                  <span class="text-gray-300">{{ formatModelName(ac.model) }}</span>
                  <div class="text-right">
                    <span class="font-medium text-amber-400">${{ Math.round(ac.annualUsd).toLocaleString() }}/yr</span>
                    <span class="text-gray-600 text-xs ml-1">({{ (ac.perImageUsd * 100).toFixed(3) }}¢/img)</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Model ranking -->
        <div v-if="modelScores" class="mb-10">
          <h2 class="text-base font-semibold text-gray-300 uppercase tracking-wider mb-4">Model ranking</h2>

          <div class="bg-gray-900 rounded-2xl border border-gray-800 overflow-hidden mb-6">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-gray-800 text-xs text-gray-500 uppercase tracking-wider">
                  <th class="text-left px-5 py-3 font-medium">Model</th>
                  <th class="text-center px-4 py-3 font-medium text-green-400">Accuracy</th>
                  <th class="text-center px-4 py-3 font-medium">Data completeness</th>
                  <th class="text-center px-4 py-3 font-medium">Cost / image</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(m, i) in modelScores.models"
                  :key="m.model"
                  class="border-t border-gray-800/60"
                >
                  <td class="px-5 py-3">
                    <span class="text-xs text-gray-600 mr-2">#{{ i + 1 }}</span>
                    <span class="font-medium text-gray-100">{{ m.displayName }}</span>
                    <span v-if="m.imgPartial" class="ml-2 text-xs text-amber-500">⚠ {{ m.imgOkPct }}% images processed</span>
                  </td>
                  <td class="px-4 py-3 text-center">
                    <span v-if="m.isReference" class="text-xs text-blue-400 font-medium">reference</span>
                    <span v-else-if="m.sonnetAgreement !== null" class="font-medium" :class="scoreColor(m.sonnetAgreement)">{{ m.sonnetAgreement }}%</span>
                    <span v-else class="text-xs text-gray-600 italic">—</span>
                  </td>
                  <td class="px-4 py-3 text-center">
                    <span class="font-medium" :class="scoreColor(m.weightedCompleteness)">{{ m.weightedCompleteness }}%</span>
                  </td>
                  <td class="px-4 py-3 text-center text-xs text-gray-400">
                    {{ m.costPerImage > 0 ? (m.costPerImage * 100).toFixed(3) + '¢' : '—' }}
                  </td>
                </tr>
              </tbody>
            </table>
            <div class="px-5 py-2 border-t border-gray-800 text-xs text-gray-600">
              Accuracy: % agreement with Claude Sonnet on EEE verdict (proxy — Sonnet treated as reference until human labels are available). Data completeness weighted by field importance.
            </div>
          </div>

          <!-- Field reliability -->
          <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Field reliability across models</h3>
          <div class="bg-gray-900 rounded-2xl border border-gray-800 overflow-hidden mb-6">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-gray-800 text-xs text-gray-500 uppercase tracking-wider">
                  <th class="text-left px-5 py-3 font-medium">Field</th>
                  <th class="text-center px-4 py-3 font-medium text-white">Consensus</th>
                  <th
                    v-for="m in modelScores.models"
                    :key="m.model"
                    class="text-center px-4 py-3 font-medium text-gray-400"
                  >
                    {{ m.displayName }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="field in modelScores.fields"
                  :key="field"
                  class="border-t border-gray-800/60"
                >
                  <td class="px-5 py-3 text-gray-300 text-xs font-medium">{{ field }}</td>
                  <td class="px-4 py-3 text-center">
                    <span class="font-bold" :class="agreePctColor(modelScores.fieldConsensus[field])">
                      {{ modelScores.fieldConsensus[field] }}%
                    </span>
                  </td>
                  <td
                    v-for="m in modelScores.models"
                    :key="m.model"
                    class="px-4 py-3 text-center"
                  >
                    <span
                      v-if="modelScores.fieldAgreement[field]?.[m.model] !== null && modelScores.fieldAgreement[field]?.[m.model] !== undefined"
                      class="text-xs font-mono"
                      :class="agreePctColor(modelScores.fieldAgreement[field][m.model])"
                    >
                      {{ modelScores.fieldAgreement[field][m.model] }}%
                    </span>
                    <span v-else class="text-gray-700 text-xs">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
            <div class="px-5 py-2 border-t border-gray-800 text-xs text-gray-600">
              Consensus = average agreement across all models against majority vote. High consensus = field is reliably extracted regardless of model choice. Low consensus = fragile — switching models may produce different results.
            </div>
          </div>

          <!-- Field completeness -->
          <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Field completeness (% of images where field was extracted)</h3>
          <div class="bg-gray-900 rounded-2xl border border-gray-800 overflow-hidden mb-6">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-gray-800 text-xs text-gray-500 uppercase tracking-wider">
                  <th class="text-left px-5 py-3 font-medium">Field</th>
                  <th
                    v-for="m in modelScores.models"
                    :key="m.model"
                    class="text-center px-4 py-3 font-medium text-gray-400"
                  >
                    {{ m.displayName }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="field in modelScores.completenessFields"
                  :key="field"
                  class="border-t border-gray-800/60"
                >
                  <td class="px-5 py-3 text-xs">
                    <span class="font-mono text-gray-400">{{ field }}</span>
                    <span class="ml-2 text-gray-700">×{{ modelScores.completenessWeights[field] }}</span>
                  </td>
                  <td
                    v-for="m in modelScores.models"
                    :key="m.model"
                    class="px-4 py-3 text-center"
                  >
                    <span
                      v-if="modelScores.completeness[m.model]?.[field] !== undefined"
                      class="text-xs font-mono"
                      :class="completenessPctColor(modelScores.completeness[m.model][field])"
                    >
                      {{ modelScores.completeness[m.model][field] }}%
                    </span>
                    <span v-else class="text-gray-700 text-xs">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
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
                <th class="text-center px-4 py-3 font-medium text-teal-400">Qwen EEE</th>
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
                  <span class="text-xs font-mono text-gray-200">
                    {{ item.qwenEeeCount }}<span class="text-gray-600">/{{ item.qwenTotal }}</span>
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
          <NuxtLink
            to="/review"
            class="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-800 hover:bg-gray-700 text-gray-200 rounded-lg text-sm font-medium transition border border-gray-700"
          >
            Label ground truth →
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
const totalCostUsd = ref(0)
const costByMonth = ref<any[]>([])
const annualCosts = ref<any[]>([])
const annualOffers = ref(0)
const modelScores = ref<any>(null)

const totalSamples = computed(() => items.value.reduce((sum, i) => sum + (i.sampleSize || 0), 0))
const totalCost = computed(() => totalCostUsd.value.toFixed(2))

const MODEL_SHORT: Record<string, string> = {
  'claude-opus-4-7': 'Claude Opus',
  'claude-sonnet-4-6': 'Claude Sonnet',
  'gemini-2.0-flash-lite': 'Gemini',
  'gpt-4o': 'GPT-4o',
  'geeks_c87e/Qwen/Qwen2.5-VL-72B-Instruct-e07a2308': 'Qwen2.5-VL',
}

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

const scoreColor = (score: number) => {
  if (score >= 80) return 'text-green-400'
  if (score >= 70) return 'text-amber-400'
  return 'text-red-400'
}

const agreePctColor = (pct: number) => {
  if (pct >= 90) return 'text-green-400'
  if (pct >= 75) return 'text-amber-400'
  return 'text-red-400'
}

const completenessPctColor = (pct: number) => {
  if (pct >= 90) return 'text-green-400'
  if (pct >= 50) return 'text-gray-300'
  if (pct >= 20) return 'text-amber-500'
  return 'text-gray-600'
}

const formatModelName = (model: string) => MODEL_SHORT[model] ?? model

const formatMonth = (ym: string) => {
  const [year, month] = ym.split('-')
  return new Date(Number(year), Number(month) - 1).toLocaleString('default', { month: 'long', year: 'numeric' })
}

onMounted(async () => {
  try {
    const [itemsResp, scoresResp] = await Promise.all([
      fetch('/api/item-types'),
      fetch('/api/model-scores'),
    ])

    if (!itemsResp.ok) throw new Error(`API error: ${itemsResp.statusText}`)
    const data = await itemsResp.json()
    items.value = data.items || []
    totalCostUsd.value = data.totalCostUsd || 0
    costByMonth.value = data.costByMonth || []
    annualCosts.value = data.annualCosts || []
    annualOffers.value = data.annualOffers || 0

    if (scoresResp.ok) {
      modelScores.value = await scoresResp.json()
    }
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    loading.value = false
  }
})
</script>
