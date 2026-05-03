<template>
  <div class="min-h-screen bg-gray-950 text-gray-100">

    <!-- Registration overlay — shown until reviewer is known -->
    <div v-if="!reviewerName" class="fixed inset-0 bg-gray-950/95 flex items-center justify-center z-50 px-4">
      <div class="bg-gray-900 border border-gray-700 rounded-2xl p-8 max-w-md w-full">
        <h2 class="text-xl font-bold text-white mb-2">Choose your reviewer name</h2>
        <p class="text-sm text-gray-400 mb-6">
          This name is stored permanently so your labels are tracked separately from other reviewers.
          Pick something short and unique (letters, numbers, hyphens).
        </p>
        <form @submit.prevent="register" class="space-y-4">
          <input
            v-model="registrationName"
            type="text"
            placeholder="e.g. alice, bob42, edward"
            maxlength="32"
            required
            autofocus
            class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-blue-600"
            :class="{ 'border-red-600': registrationError }"
          />
          <p v-if="registrationError" class="text-sm text-red-400">{{ registrationError }}</p>
          <button
            type="submit"
            :disabled="registering"
            class="w-full bg-blue-700 hover:bg-blue-600 disabled:opacity-50 text-white rounded-lg px-4 py-3 font-semibold transition"
          >
            {{ registering ? 'Registering…' : 'Start labelling' }}
          </button>
        </form>
      </div>
    </div>

    <!-- Header -->
    <header class="bg-gray-900 border-b border-gray-800 px-6 py-4 sticky top-0 z-10">
      <div class="max-w-7xl mx-auto flex items-center justify-between">
        <div class="flex items-center gap-3">
          <NuxtLink to="/" class="text-blue-400 hover:text-blue-300 text-sm">← Home</NuxtLink>
          <span class="text-gray-700">|</span>
          <h1 class="text-lg font-semibold text-white">Ground truth labelling</h1>
        </div>
        <div v-if="reviewerName" class="flex items-center gap-2 text-sm text-gray-400">
          <span class="text-gray-600">Reviewing as</span>
          <span class="font-semibold text-gray-200">{{ reviewerName }}</span>
        </div>
      </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
      <!-- Field selector tabs -->
      <div v-if="fields.length > 0" class="mb-8">
        <div class="flex gap-3 flex-wrap mb-6">
          <button
            v-for="field in fields"
            :key="field.field"
            @click="selectField(field.field)"
            :class="[
              'px-4 py-3 rounded-lg border transition text-sm font-medium',
              selectedField === field.field
                ? 'bg-blue-900/70 border-blue-700 text-blue-300'
                : 'bg-gray-800 border-gray-700 text-gray-400 hover:bg-gray-700/50',
            ]"
          >
            <div class="flex items-center gap-2">
              <span>{{ field.short }}</span>
              <span class="text-xs bg-gray-700 px-2 py-0.5 rounded">{{ myLabelledCount(field.field) }}/{{ field.total }}</span>
              <span class="text-xs text-gray-500">w{{ field.weight }}</span>
            </div>
          </button>
        </div>

        <!-- Field introduction -->
        <div v-if="currentFieldDef" class="bg-indigo-950/40 border border-indigo-800/40 rounded-xl px-5 py-4 mb-4 text-sm text-indigo-200 leading-relaxed">
          {{ currentFieldDef.intro }}
        </div>

        <!-- Progress bar for selected field (my progress) -->
        <div class="bg-gray-900 rounded-lg border border-gray-800 p-3">
          <div class="flex items-center justify-between mb-2">
            <span class="text-xs text-gray-500 uppercase tracking-wider">Your progress</span>
            <span class="text-xs text-gray-400">{{ fieldProgress.labelled }} / {{ fieldProgress.total }}</span>
          </div>
          <div class="w-full bg-gray-800 rounded-full h-2 mb-2">
            <div
              class="bg-blue-600 h-2 rounded-full transition-all"
              :style="{ width: `${fieldProgress.total > 0 ? (fieldProgress.labelled / fieldProgress.total) * 100 : 0}%` }"
            />
          </div>
          <div v-if="stats.reviewers && stats.reviewers.length > 1" class="text-xs text-gray-600">
            All reviewers: {{ reviewerTotalsForField }}
          </div>
        </div>
      </div>

      <div v-if="loading" class="text-center py-20 text-gray-500">Loading next item…</div>
      <div v-else-if="error" class="bg-red-900/30 border border-red-700 rounded-lg p-4 text-red-300">{{ error }}</div>

      <template v-else-if="currentItem.messageid">
        <!-- Two-column layout -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
          <!-- Left: Image -->
          <div class="lg:col-span-2">
            <div class="bg-gray-900 rounded-2xl border border-gray-800 overflow-hidden h-full flex flex-col">
              <div class="flex-1 bg-black flex items-center justify-center min-h-96">
                <img
                  v-if="!imageFailed"
                  :src="currentItem.imageUrl"
                  :alt="`${currentItem.messageid}-${currentItem.attid}`"
                  class="max-w-full max-h-full object-contain"
                  @error="imageFailed = true"
                />
                <div v-else class="w-full h-96 bg-gray-800 flex items-center justify-center text-gray-500">
                  <div class="text-center">
                    <p class="text-sm">Image unavailable</p>
                    <p class="text-xs text-gray-600 mt-1">{{ currentItem.messageid }} / {{ currentItem.attid }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Right: Item info, question, classification -->
          <div class="lg:col-span-1 flex flex-col gap-6">
            <!-- Item name -->
            <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6">
              <p class="text-xs text-gray-500 uppercase tracking-wider mb-2">Item type</p>
              <h2 class="text-2xl font-bold text-white">{{ currentItem.itemName || '—' }}</h2>
              <p class="text-xs text-gray-600 mt-3">msg {{ currentItem.messageid }} / att {{ currentItem.attid }}</p>
            </div>

            <!-- Field question -->
            <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6">
              <p class="text-sm font-semibold text-white mb-4">{{ currentItem.fieldQuestion }}</p>

              <div v-if="currentItem.hasDisagreement" class="mb-4 inline-block">
                <span class="text-xs px-3 py-1.5 rounded-full bg-amber-900/60 text-amber-300 border border-amber-700">
                  ⚠ Models disagree
                </span>
              </div>

              <!-- Per-model values -->
              <div class="space-y-3">
                <div
                  v-for="model in currentItem.modelValues"
                  :key="model.model"
                  class="bg-gray-800/50 rounded-lg p-3 border border-gray-700/50"
                >
                  <div class="flex items-start justify-between">
                    <span class="text-sm font-semibold" :class="modelColor(model.model)">
                      {{ model.displayName }}
                    </span>
                    <span
                      class="text-xs px-2 py-1 rounded font-medium"
                      :class="[fieldValueClass(model.value, selectedField), selectedField === 'Electrical components' ? 'block max-w-xs text-left whitespace-normal' : '']"
                    >
                      {{ formatFieldValue(model.value, selectedField) }}
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Label buttons -->
            <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6 space-y-3">
              <p class="text-xs text-gray-500 uppercase tracking-wider mb-4">
                {{ currentFieldDef?.type === 'bucket' ? 'Select weight range' : currentFieldDef?.type === 'binary' ? 'Your label (Y/N/?)' : 'Is this correct? (Y/N/?)' }}
              </p>

              <!-- Bucket buttons (weight) -->
              <div v-if="currentFieldDef?.type === 'bucket'" class="space-y-2 mb-4">
                <button
                  v-for="bucket in currentFieldDef.buckets"
                  :key="bucket.key"
                  @click="submitLabel(bucket.key)"
                  :disabled="submitting"
                  class="w-full bg-blue-900/40 hover:bg-blue-800/60 disabled:opacity-50 text-blue-200 border border-blue-700/50 rounded-lg px-4 py-2.5 text-sm font-medium transition text-left"
                >
                  {{ bucket.label }}
                </button>
                <button
                  @click="submitLabel('unsure')"
                  :disabled="submitting"
                  class="w-full bg-gray-700 hover:bg-gray-600 disabled:opacity-50 text-gray-300 border border-gray-600 rounded-lg px-4 py-2 text-sm transition"
                >
                  Can't tell (U)
                </button>
              </div>

              <!-- Binary / correct buttons -->
              <div v-else class="grid grid-cols-3 gap-3 mb-4">
                <button
                  @click="submitLabel(currentFieldDef?.type === 'binary' ? 'eee' : 'correct')"
                  :disabled="submitting"
                  class="bg-green-900/70 hover:bg-green-800 disabled:opacity-50 text-green-300 border border-green-700 rounded-lg px-4 py-3 text-sm font-bold transition"
                >
                  {{ currentFieldDef?.type === 'binary' ? 'EEE ✓' : 'Correct ✓' }}<br /><span class="text-xs font-normal">Y</span>
                </button>
                <button
                  @click="submitLabel(currentFieldDef?.type === 'binary' ? 'not_eee' : 'incorrect')"
                  :disabled="submitting"
                  class="bg-red-900/70 hover:bg-red-800 disabled:opacity-50 text-red-300 border border-red-700 rounded-lg px-4 py-3 text-sm font-bold transition"
                >
                  {{ currentFieldDef?.type === 'binary' ? 'Not EEE ✗' : 'Incorrect ✗' }}<br /><span class="text-xs font-normal">N</span>
                </button>
                <button
                  @click="submitLabel('unsure')"
                  :disabled="submitting"
                  class="bg-gray-700 hover:bg-gray-600 disabled:opacity-50 text-gray-300 border border-gray-600 rounded-lg px-4 py-3 text-sm font-bold transition"
                >
                  Can't tell<br /><span class="text-xs font-normal">U</span>
                </button>
              </div>

              <!-- Notes toggle -->
              <button @click="showNotes = !showNotes" class="text-xs text-gray-400 hover:text-gray-300 transition">
                {{ showNotes ? '✕ Hide' : '+ Add' }} notes
              </button>

              <textarea
                v-show="showNotes"
                v-model="notes"
                placeholder="Optional: reasons for this label…"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-xs text-gray-200 placeholder-gray-600 focus:outline-none focus:border-gray-500"
                rows="3"
              />

              <button
                @click="skipItem"
                :disabled="submitting"
                class="w-full text-xs text-gray-500 hover:text-gray-400 py-2 transition"
              >
                S: Skip to next
              </button>
            </div>

            <Transition name="fade">
              <div v-if="showConfirmation" class="bg-green-900/60 border border-green-700 rounded-lg p-3 text-green-300 text-xs text-center">
                Saved ✓
              </div>
            </Transition>
          </div>
        </div>

        <!-- Accuracy table (shown once any field has ≥10 quorum labels) -->
        <div v-if="hasAnyLabels" class="bg-gray-900 rounded-2xl border border-gray-800 overflow-hidden">
          <div class="px-6 py-4 border-b border-gray-800">
            <h3 class="text-sm font-semibold text-white">Model accuracy by field</h3>
            <p class="text-xs text-gray-500 mt-1">Based on quorum labels (plurality across reviewers)</p>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-gray-800 text-xs text-gray-500 uppercase tracking-wider">
                  <th class="text-left px-6 py-3 font-medium">Field</th>
                  <th class="text-center px-4 py-3 font-medium">Quorum labels</th>
                  <th v-for="model in allModels" :key="model" class="text-center px-4 py-3 font-medium">{{ formatModelName(model) }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="field in stats.fields" :key="field.field" class="border-t border-gray-800/60">
                  <td class="px-6 py-3 font-medium text-gray-200">{{ field.field }}</td>
                  <td class="px-4 py-3 text-center text-gray-300">{{ field.labelledTotal }}</td>
                  <td v-for="model in allModels" :key="model" class="px-4 py-3 text-center">
                    <span v-if="field.modelAccuracy && field.modelAccuracy[model] !== undefined" :class="accuracyColor(field.modelAccuracy[model])">
                      {{ field.modelAccuracy[model] }}%
                    </span>
                    <span v-else class="text-gray-600">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <!-- Per-reviewer contribution -->
          <div v-if="stats.reviewers && stats.reviewers.length > 0" class="px-6 py-4 border-t border-gray-800">
            <p class="text-xs text-gray-500 uppercase tracking-wider mb-3">Labels by reviewer</p>
            <div class="flex flex-wrap gap-4">
              <div v-for="reviewer in stats.reviewers" :key="reviewer" class="text-xs">
                <span class="text-gray-300 font-semibold">{{ reviewer }}</span>
                <span class="text-gray-500 ml-1">
                  {{ totalLabelsByReviewer(reviewer) }} labels
                </span>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- All items labelled -->
      <template v-else>
        <div class="bg-green-900/30 border border-green-700 rounded-2xl p-8 text-center">
          <p class="text-xl font-semibold text-green-300 mb-2">All items labelled for this field! 🎉</p>
          <p class="text-gray-400 mb-6">{{ fieldProgress.labelled }} labels created</p>
          <NuxtLink to="/" class="inline-block px-6 py-3 bg-blue-900/70 hover:bg-blue-800 text-blue-300 border border-blue-700 rounded-lg transition">
            ← Back to home
          </NuxtLink>
        </div>
      </template>
    </main>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'default' })

interface Field {
  field: string
  short: string
  weight: number
  dbColumn: string
  type: 'binary' | 'correct' | 'bucket'
  buckets?: { label: string; key: string }[]
  intro: string
  total: number
}

const WEIGHT_BUCKETS = [
  { label: '< 1 kg', key: 'under_1kg' },
  { label: '1–5 kg', key: '1_5kg' },
  { label: '5–20 kg', key: '5_20kg' },
  { label: '20–100 kg', key: '20_100kg' },
  { label: '> 100 kg', key: 'over_100kg' },
]

const FIELDS: Field[] = [
  {
    field: 'EEE', short: 'EEE', weight: 5, dbColumn: 'is_eee', type: 'binary', total: 0,
    intro: 'EEE (Electrical and Electronic Equipment) is the most important field — it determines whether an item falls under the WEEE recycling directive and must be handled by a licensed collector. An item is EEE if it requires electricity or a battery to function, even partially. Common examples: TVs, laptops, phones, power tools, kettles, lamps. Items that are purely mechanical (wooden furniture, clothing, books) are not EEE. If there\'s genuine doubt — e.g. a toy that might have batteries — mark it EEE to be safe.',
  },
  {
    field: 'Electrical components', short: 'Electrical', weight: 4, dbColumn: 'electrical_components_description', type: 'correct', total: 0,
    intro: 'The model lists the key electrical components it believes are present in the item (e.g. "motor, heating element, thermostat" for a kettle). You\'re checking whether these components are plausible given what you can see in the photo and the item name — not whether the list is exhaustive. Mark Correct if the listed components make sense for this type of item. Mark Incorrect if the model has hallucinated components that clearly don\'t belong (e.g. listing "LCD screen" for a toaster).',
  },
  {
    field: 'Photo quality', short: 'Photo', weight: 4, dbColumn: 'photo_quality', type: 'correct', total: 0,
    intro: 'The model rates photo quality as Good, Adequate, or Poor. Good means the item is clearly visible, well-lit, and the photo gives enough detail to classify the item confidently. Adequate means the photo is usable but has issues (blurry, dark, partial view). Poor means the photo is too unclear to be useful. Check whether the model\'s rating matches your own impression of the photo.',
  },
  {
    field: 'Condition', short: 'Condition', weight: 3, dbColumn: 'condition', type: 'correct', total: 0,
    intro: 'The model assesses the physical condition of the item: New, Good, Fair, or Poor. New means unused or as-new. Good means clearly used but fully functional with no significant damage. Fair means visible wear, minor damage, or unclear functionality. Poor means significant damage, broken, or unusable. Check whether the model\'s assessment matches what you can see in the photo. If the photo is too poor quality to judge, use "Can\'t tell".',
  },
  {
    field: 'Weight (kg)', short: 'Weight', weight: 3, dbColumn: 'weight_kg_min', type: 'bucket', buckets: WEIGHT_BUCKETS, total: 0,
    intro: 'Select the weight band that best matches this item type. You\'re not checking the model\'s specific number — you\'re providing the ground-truth bucket so we can see whether the model\'s estimate is in the right range. Use your general knowledge of the item type: a phone is under 1 kg, a laptop is 1–5 kg, a washing machine is 20–100 kg. If you genuinely have no idea, choose "Can\'t tell".',
  },
  {
    field: 'Value band', short: 'Value', weight: 2, dbColumn: 'value_band_gbp', type: 'correct', total: 0,
    intro: 'The model estimates the second-hand / donation value in GBP: Under £20, £20–100, £100–500, or Over £500. This is the realistic resale or rehoming value, not the original retail price. A working iPhone might be £100–500; a broken kettle is Under £20. Check whether the model\'s band is reasonable for this item type and apparent condition. If the photo quality makes it impossible to judge condition, use "Can\'t tell".',
  },
  {
    field: 'Brand', short: 'Brand', weight: 1, dbColumn: 'brand', type: 'correct', total: 0,
    intro: 'The model tries to identify the manufacturer or brand from the photo (e.g. "Samsung", "Dyson", "unknown"). Mark Correct if the brand shown matches what you can see in the image, or if the model correctly says "unknown" when no brand is visible. Mark Incorrect if the model has named a wrong brand or missed an obvious one that\'s clearly visible. If the photo quality makes the brand impossible to read, use "Can\'t tell".',
  },
]

// Reviewer auth
const reviewerName = ref<string | null>(null)
const registrationName = ref('')
const registrationError = ref('')
const registering = ref(false)

const register = async () => {
  registering.value = true
  registrationError.value = ''
  try {
    const resp = await fetch('/api/review/register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: registrationName.value }),
    })
    const data = await resp.json()
    if (!resp.ok) {
      registrationError.value = data.statusMessage || 'Registration failed'
      return
    }
    reviewerName.value = data.name
    await loadNextItem()
  } catch (e) {
    registrationError.value = 'Network error — please try again'
  } finally {
    registering.value = false
  }
}

const checkWhoami = async () => {
  try {
    const resp = await fetch('/api/review/whoami')
    const data = await resp.json()
    if (data.name) {
      reviewerName.value = data.name
      await loadNextItem()
    }
  } catch {}
}

const selectedField = ref<string>('EEE')
const fields = ref<Field[]>(FIELDS)
const currentFieldDef = computed(() => FIELDS.find(f => f.field === selectedField.value))

const currentItem = ref<any>({
  messageid: null, attid: null, itemName: null, imageUrl: null,
  field: '', fieldQuestion: '', modelValues: [],
  progress: { labelled: 0, total: 0 }, hasDisagreement: false,
})

const stats = ref<any>({ fields: [], reviewers: [] })
const loading = ref(false)
const error = ref<string | null>(null)
const submitting = ref(false)
const imageFailed = ref(false)
const showNotes = ref(false)
const notes = ref('')
const showConfirmation = ref(false)

const myLabelledCount = (fieldName: string): number => {
  if (!reviewerName.value) return 0
  const f = stats.value.fields.find((f: any) => f.field === fieldName)
  return f?.labelledByReviewer?.[reviewerName.value] ?? 0
}

const fieldProgress = computed(() => {
  const f = fields.value.find(f => f.field === selectedField.value)
  const labelled = myLabelledCount(selectedField.value)
  return { labelled, total: f?.total ?? 0 }
})

const reviewerTotalsForField = computed(() => {
  const f = stats.value.fields.find((f: any) => f.field === selectedField.value)
  if (!f?.labelledByReviewer) return ''
  return Object.entries(f.labelledByReviewer as Record<string, number>)
    .map(([name, count]) => `${name}: ${count}`)
    .join(', ')
})

const allModels = computed(() => {
  const models = new Set<string>()
  for (const field of stats.value.fields) {
    if (field.modelAccuracy) Object.keys(field.modelAccuracy).forEach(m => models.add(m))
  }
  return Array.from(models).sort()
})

const hasAnyLabels = computed(() =>
  stats.value.fields.some((f: any) => f.labelledTotal >= 10)
)

const totalLabelsByReviewer = (reviewer: string): number =>
  stats.value.fields.reduce((sum: number, f: any) => sum + (f.labelledByReviewer?.[reviewer] ?? 0), 0)

const formatModelName = (model: string) => {
  if (model.includes('claude-sonnet-4-6')) return 'Claude Sonnet'
  if (model.includes('claude-opus-4-7')) return 'Claude Opus'
  if (model.includes('gemini')) return 'Gemini Flash'
  if (model.includes('gpt-4o')) return 'GPT-4o'
  if (model.includes('Qwen')) return 'Qwen2.5'
  return model
}

const modelColor = (model: string) => {
  if (model.includes('claude')) return 'text-blue-400'
  if (model.includes('gemini')) return 'text-violet-400'
  if (model.includes('gpt')) return 'text-emerald-400'
  if (model.includes('Qwen')) return 'text-teal-400'
  return 'text-gray-300'
}

const accuracyColor = (acc: number) => {
  if (acc >= 80) return 'text-green-400'
  if (acc >= 60) return 'text-amber-400'
  return 'text-red-400'
}

const formatFieldValue = (value: any, field?: string) => {
  if (value === null || value === undefined) return '—'
  if (field === 'EEE') return value == 1 ? 'Yes' : 'No'
  return String(value)
}

const fieldValueClass = (value: any, field?: string) => {
  if (field === 'EEE') {
    return value == 1
      ? 'bg-green-900/60 text-green-300 border border-green-700/50'
      : 'bg-blue-900/60 text-blue-300 border border-blue-700/50'
  }
  return 'bg-gray-700 text-gray-300'
}

const selectField = (field: string) => {
  selectedField.value = field
  loadNextItem()
}

const loadNextItem = async () => {
  if (!reviewerName.value) return
  loading.value = true
  error.value = null
  imageFailed.value = false
  showNotes.value = false
  notes.value = ''

  try {
    const [nextResp, statsResp] = await Promise.all([
      fetch(`/api/review/next?field=${encodeURIComponent(selectedField.value)}`),
      fetch('/api/review/stats'),
    ])

    if (nextResp.status === 401) {
      reviewerName.value = null
      return
    }
    if (!nextResp.ok) throw new Error((await nextResp.json()).statusMessage || nextResp.statusText)
    if (!statsResp.ok) throw new Error(`Stats error: ${statsResp.statusText}`)

    currentItem.value = await nextResp.json()
    const statsData = await statsResp.json()
    stats.value = statsData

    for (const statField of statsData.fields) {
      const f = fields.value.find(f => f.field === statField.field)
      if (f) f.total = statField.total
    }
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    loading.value = false
  }
}

const submitLabel = async (label: string) => {
  submitting.value = true
  try {
    const resp = await fetch('/api/review/label', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        messageid: currentItem.value.messageid,
        attid: currentItem.value.attid,
        field: selectedField.value,
        label,
        notes: notes.value || null,
      }),
    })

    if (resp.status === 401) { reviewerName.value = null; return }
    if (!resp.ok) throw new Error('Failed to save label')

    showConfirmation.value = true
    setTimeout(() => {
      showConfirmation.value = false
      loadNextItem()
    }, 1500)
  } catch (e) {
    error.value = (e as Error).message
    submitting.value = false
  }
}

const skipItem = async () => {
  submitting.value = true
  try { await loadNextItem() } finally { submitting.value = false }
}

const handleKeydown = (e: KeyboardEvent) => {
  if (submitting.value || loading.value || !reviewerName.value) return
  const fieldType = currentFieldDef.value?.type
  if (e.key.toLowerCase() === 'y' && fieldType !== 'bucket') {
    e.preventDefault()
    submitLabel(fieldType === 'binary' ? 'eee' : 'correct')
  } else if (e.key.toLowerCase() === 'n' && fieldType !== 'bucket') {
    e.preventDefault()
    submitLabel(fieldType === 'binary' ? 'not_eee' : 'incorrect')
  } else if (e.key.toLowerCase() === 'u' || e.key === '?') {
    e.preventDefault()
    submitLabel('unsure')
  } else if (e.key.toLowerCase() === 's') {
    e.preventDefault()
    skipItem()
  } else if (e.key === 'Tab') {
    e.preventDefault()
    showNotes.value = !showNotes.value
  }
}

onMounted(async () => {
  window.addEventListener('keydown', handleKeydown)
  await checkWhoami()
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
})
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
