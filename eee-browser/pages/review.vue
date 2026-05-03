<template>
  <div class="min-h-screen bg-gray-950 text-gray-100">

    <!-- Registration overlay -->
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
        <div class="flex gap-3 flex-wrap mb-4">
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
            </div>
          </button>
        </div>

        <!-- Field introduction -->
        <div v-if="currentFieldDef" class="bg-indigo-950/40 border border-indigo-800/40 rounded-xl px-5 py-4 mb-4 text-sm text-indigo-200 leading-relaxed">
          {{ currentFieldDef.intro }}
        </div>

        <!-- Progress bar (my progress) -->
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
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
          <!-- Image -->
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

          <!-- Right: Item info + labelling -->
          <div class="lg:col-span-1 flex flex-col gap-6">
            <!-- Item name -->
            <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6">
              <p class="text-xs text-gray-500 uppercase tracking-wider mb-2">Item type</p>
              <h2 class="text-2xl font-bold text-white">{{ currentItem.itemName || '—' }}</h2>
              <p class="text-xs text-gray-600 mt-3">msg {{ currentItem.messageid }} / att {{ currentItem.attid }}</p>
            </div>

            <!-- Labelling panel -->
            <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6 space-y-4">
              <p class="text-sm font-semibold text-white">{{ currentItem.fieldQuestion }}</p>

              <!-- EEE: binary yes/no -->
              <div v-if="currentFieldDef?.type === 'binary'" class="grid grid-cols-3 gap-3">
                <button @click="submitLabel('eee')" :disabled="submitting"
                  class="bg-green-900/70 hover:bg-green-800 disabled:opacity-50 text-green-300 border border-green-700 rounded-lg px-3 py-3 text-sm font-bold transition">
                  EEE ✓<br><span class="text-xs font-normal">Y</span>
                </button>
                <button @click="submitLabel('not_eee')" :disabled="submitting"
                  class="bg-red-900/70 hover:bg-red-800 disabled:opacity-50 text-red-300 border border-red-700 rounded-lg px-3 py-3 text-sm font-bold transition">
                  Not EEE ✗<br><span class="text-xs font-normal">N</span>
                </button>
                <button @click="submitLabel('unsure')" :disabled="submitting"
                  class="bg-gray-700 hover:bg-gray-600 disabled:opacity-50 text-gray-300 border border-gray-600 rounded-lg px-3 py-3 text-sm font-bold transition">
                  Can't tell<br><span class="text-xs font-normal">U</span>
                </button>
              </div>

              <!-- Electrical components: presence -->
              <div v-else-if="currentFieldDef?.type === 'presence'" class="grid grid-cols-3 gap-3">
                <button @click="submitLabel('present')" :disabled="submitting"
                  class="bg-green-900/70 hover:bg-green-800 disabled:opacity-50 text-green-300 border border-green-700 rounded-lg px-3 py-3 text-sm font-bold transition">
                  Yes, visible<br><span class="text-xs font-normal">Y</span>
                </button>
                <button @click="submitLabel('absent')" :disabled="submitting"
                  class="bg-red-900/70 hover:bg-red-800 disabled:opacity-50 text-red-300 border border-red-700 rounded-lg px-3 py-3 text-sm font-bold transition">
                  None visible<br><span class="text-xs font-normal">N</span>
                </button>
                <button @click="submitLabel('unsure')" :disabled="submitting"
                  class="bg-gray-700 hover:bg-gray-600 disabled:opacity-50 text-gray-300 border border-gray-600 rounded-lg px-3 py-3 text-sm font-bold transition">
                  Can't tell<br><span class="text-xs font-normal">U</span>
                </button>
              </div>

              <!-- Photo quality: 1-5 rating -->
              <div v-else-if="currentFieldDef?.type === 'rating5'" class="space-y-2">
                <div class="grid grid-cols-5 gap-2">
                  <button v-for="n in [1,2,3,4,5]" :key="n"
                    @click="submitLabel(String(n))" :disabled="submitting"
                    class="bg-gray-700 hover:bg-blue-900/70 disabled:opacity-50 text-gray-200 border border-gray-600 rounded-lg py-3 text-sm font-bold transition">
                    {{ n }}
                  </button>
                </div>
                <div class="flex justify-between text-xs text-gray-500 px-1">
                  <span>Poor</span><span>Excellent</span>
                </div>
                <button @click="submitLabel('unsure')" :disabled="submitting"
                  class="w-full bg-gray-800 hover:bg-gray-700 disabled:opacity-50 text-gray-400 border border-gray-700 rounded-lg px-3 py-2 text-xs transition">
                  Can't assess (U)
                </button>
              </div>

              <!-- Condition -->
              <div v-else-if="currentFieldDef?.type === 'condition'" class="grid grid-cols-3 gap-3">
                <button @click="submitLabel('reusable')" :disabled="submitting"
                  class="bg-green-900/70 hover:bg-green-800 disabled:opacity-50 text-green-300 border border-green-700 rounded-lg px-3 py-3 text-sm font-bold transition">
                  Working<br><span class="text-xs font-normal">Y</span>
                </button>
                <button @click="submitLabel('damaged')" :disabled="submitting"
                  class="bg-red-900/70 hover:bg-red-800 disabled:opacity-50 text-red-300 border border-red-700 rounded-lg px-3 py-3 text-sm font-bold transition">
                  Damaged<br><span class="text-xs font-normal">N</span>
                </button>
                <button @click="submitLabel('unsure')" :disabled="submitting"
                  class="bg-gray-700 hover:bg-gray-600 disabled:opacity-50 text-gray-300 border border-gray-600 rounded-lg px-3 py-3 text-sm font-bold transition">
                  Can't tell<br><span class="text-xs font-normal">U</span>
                </button>
              </div>

              <!-- Weight bucket -->
              <div v-else-if="currentFieldDef?.type === 'bucket'" class="space-y-2">
                <button v-for="bucket in currentFieldDef.buckets" :key="bucket.key"
                  @click="submitLabel(bucket.key)" :disabled="submitting"
                  class="w-full bg-blue-900/40 hover:bg-blue-800/60 disabled:opacity-50 text-blue-200 border border-blue-700/50 rounded-lg px-4 py-2.5 text-sm font-medium transition text-left">
                  {{ bucket.label }}
                </button>
                <button @click="submitLabel('unsure')" :disabled="submitting"
                  class="w-full bg-gray-700 hover:bg-gray-600 disabled:opacity-50 text-gray-300 border border-gray-600 rounded-lg px-4 py-2 text-sm transition">
                  Can't tell (U)
                </button>
              </div>

              <!-- Size bucket -->
              <div v-else-if="currentFieldDef?.type === 'size_bucket'" class="space-y-2">
                <button v-for="bucket in currentFieldDef.buckets" :key="bucket.key"
                  @click="submitLabel(bucket.key)" :disabled="submitting"
                  class="w-full bg-blue-900/40 hover:bg-blue-800/60 disabled:opacity-50 text-blue-200 border border-blue-700/50 rounded-lg px-4 py-2.5 text-sm font-medium transition text-left">
                  {{ bucket.label }}
                </button>
                <button @click="submitLabel('unsure')" :disabled="submitting"
                  class="w-full bg-gray-700 hover:bg-gray-600 disabled:opacity-50 text-gray-300 border border-gray-600 rounded-lg px-4 py-2 text-sm transition">
                  Can't tell (U)
                </button>
              </div>

              <!-- Value band -->
              <div v-else-if="currentFieldDef?.type === 'value_band'" class="space-y-2">
                <button v-for="band in VALUE_BANDS" :key="band.key"
                  @click="submitLabel(band.key)" :disabled="submitting"
                  class="w-full bg-blue-900/40 hover:bg-blue-800/60 disabled:opacity-50 text-blue-200 border border-blue-700/50 rounded-lg px-4 py-2.5 text-sm font-medium transition text-left">
                  {{ band.label }}
                </button>
                <button @click="submitLabel('unsure')" :disabled="submitting"
                  class="w-full bg-gray-700 hover:bg-gray-600 disabled:opacity-50 text-gray-300 border border-gray-600 rounded-lg px-4 py-2 text-sm transition">
                  Can't tell (U)
                </button>
              </div>

              <!-- Brand: visibility -->
              <div v-else-if="currentFieldDef?.type === 'brand_presence'" class="grid grid-cols-3 gap-3">
                <button @click="submitLabel('visible')" :disabled="submitting"
                  class="bg-green-900/70 hover:bg-green-800 disabled:opacity-50 text-green-300 border border-green-700 rounded-lg px-3 py-3 text-sm font-bold transition">
                  Visible<br><span class="text-xs font-normal">Y</span>
                </button>
                <button @click="submitLabel('not_visible')" :disabled="submitting"
                  class="bg-red-900/70 hover:bg-red-800 disabled:opacity-50 text-red-300 border border-red-700 rounded-lg px-3 py-3 text-sm font-bold transition">
                  Not visible<br><span class="text-xs font-normal">N</span>
                </button>
                <button @click="submitLabel('unsure')" :disabled="submitting"
                  class="bg-gray-700 hover:bg-gray-600 disabled:opacity-50 text-gray-300 border border-gray-600 rounded-lg px-3 py-3 text-sm font-bold transition">
                  Can't tell<br><span class="text-xs font-normal">U</span>
                </button>
              </div>

              <!-- Notes -->
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

              <button @click="skipItem" :disabled="submitting"
                class="w-full text-xs text-gray-500 hover:text-gray-400 py-2 transition">
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

        <!-- Accuracy table (≥10 quorum labels) -->
        <div v-if="hasAnyLabels" class="bg-gray-900 rounded-2xl border border-gray-800 overflow-hidden">
          <div class="px-6 py-4 border-b border-gray-800">
            <h3 class="text-sm font-semibold text-white">Model accuracy by field</h3>
            <p class="text-xs text-gray-500 mt-1">Blind-labelled ground truth, quorum across reviewers. Photo quality: ±1 accepted.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-gray-800 text-xs text-gray-500 uppercase tracking-wider">
                  <th class="text-left px-6 py-3 font-medium">Field</th>
                  <th class="text-center px-4 py-3 font-medium">Labels</th>
                  <th v-for="model in allModels" :key="model" class="text-center px-4 py-3 font-medium">{{ formatModelName(model) }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="field in stats.fields" :key="field.field" class="border-t border-gray-800/60">
                  <td class="px-6 py-3 font-medium text-gray-200">{{ field.field }}</td>
                  <td class="px-4 py-3 text-center text-gray-300">{{ field.labelledTotal }}</td>
                  <td v-for="model in allModels" :key="model" class="px-4 py-3 text-center">
                    <span v-if="field.modelAccuracy?.[model] !== undefined" :class="accuracyColor(field.modelAccuracy[model])">
                      {{ field.modelAccuracy[model] }}%
                    </span>
                    <span v-else class="text-gray-600">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="stats.reviewers?.length > 0" class="px-6 py-4 border-t border-gray-800">
            <p class="text-xs text-gray-500 uppercase tracking-wider mb-3">Labels by reviewer</p>
            <div class="flex flex-wrap gap-4">
              <div v-for="reviewer in stats.reviewers" :key="reviewer" class="text-xs">
                <span class="text-gray-300 font-semibold">{{ reviewer }}</span>
                <span class="text-gray-500 ml-1">{{ totalLabelsByReviewer(reviewer) }} labels</span>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- All done -->
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
  type: 'binary' | 'presence' | 'rating5' | 'condition' | 'bucket' | 'size_bucket' | 'value_band' | 'brand_presence'
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

const SIZE_BUCKETS = [
  { label: 'Tiny — under 20 cm (phone, remote, small gadget)', key: 'tiny' },
  { label: 'Small — 20–50 cm (laptop, tablet, small appliance)', key: 'small' },
  { label: 'Medium — 50–100 cm (monitor, printer, microwave)', key: 'medium' },
  { label: 'Large — over 100 cm (washing machine, large TV, fridge)', key: 'large' },
]

const VALUE_BANDS = [
  { label: 'Under £20', key: '0-20' },
  { label: '£20–100', key: '20-100' },
  { label: '£100–500', key: '100-500' },
  { label: 'Over £500', key: '500+' },
]

const FIELDS: Field[] = [
  {
    field: 'EEE', short: 'EEE', weight: 5, dbColumn: 'is_eee', type: 'binary', total: 0,
    intro: 'An item is EEE if it requires electricity or a battery to function, even partially. Common examples: TVs, laptops, phones, power tools, kettles, lamps. Purely mechanical items (furniture, clothing, books) are not EEE. When in doubt, mark EEE to be safe.',
  },
  {
    field: 'Electrical components', short: 'Electrical', weight: 4, dbColumn: 'electrical_components_description', type: 'presence', total: 0,
    intro: 'Look at the photo and decide: can you directly see any electrical components? This includes cables, displays, batteries, motors, circuit boards, or any other part that uses power. Mark "Yes, visible" if you can clearly see at least one electrical component in the photo.',
  },
  {
    field: 'Photo quality', short: 'Photo', weight: 4, dbColumn: 'photo_quality', type: 'rating5', total: 0,
    intro: 'Rate the photo quality on a scale of 1 to 5. 5 = sharp, well-lit, item clearly visible with good detail. 3 = usable but some issues (slight blur, partial view, dark). 1 = too unclear or dark to classify the item confidently.',
  },
  {
    field: 'Condition', short: 'Condition', weight: 3, dbColumn: 'condition', type: 'condition', total: 0,
    intro: 'Based on what you can see, is this item working and undamaged, or is it clearly broken/damaged? Mark "Working" if it looks functional and undamaged. Mark "Damaged" if there is visible damage, breakage, or it obviously does not work. Use "Can\'t tell" if the photo quality makes it impossible to judge.',
  },
  {
    field: 'Weight (kg)', short: 'Weight', weight: 3, dbColumn: 'weight_kg_min', type: 'bucket', buckets: WEIGHT_BUCKETS, total: 0,
    intro: 'Select the weight range you would expect for this type of item. Use your general knowledge: a phone is under 1 kg, a laptop is 1–5 kg, a microwave is 5–20 kg, a washing machine is 20–100 kg. You do not need to see the weight — estimate from the item type.',
  },
  {
    field: 'Size', short: 'Size', weight: 2, dbColumn: 'size_cm', type: 'size_bucket', buckets: SIZE_BUCKETS, total: 0,
    intro: 'Select the size range based on the item\'s longest dimension. Use the photo and your knowledge of the item type. A phone is tiny (<20 cm), a laptop is small (20–50 cm), a monitor is medium (50–100 cm), a fridge is large (>100 cm).',
  },
  {
    field: 'Value band', short: 'Value', weight: 2, dbColumn: 'value_band_gbp', type: 'value_band', total: 0,
    intro: 'What is the realistic second-hand / rehoming value of this item in its apparent condition? Use the donation/resale market — not the original retail price. A working iPhone might be £100–500; a broken kettle is under £20.',
  },
  {
    field: 'Brand', short: 'Brand', weight: 1, dbColumn: 'brand', type: 'brand_presence', total: 0,
    intro: 'Is a brand name or logo clearly readable in this photo? Mark "Visible" only if you can actually read the brand from the image. Mark "Not visible" if no brand is discernible. Use "Can\'t tell" if the photo is too poor quality.',
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
    if (!resp.ok) { registrationError.value = data.statusMessage || 'Registration failed'; return }
    reviewerName.value = data.name
    await loadNextItem()
  } catch { registrationError.value = 'Network error — please try again' }
  finally { registering.value = false }
}

const checkWhoami = async () => {
  try {
    const resp = await fetch('/api/review/whoami')
    const data = await resp.json()
    if (data.name) { reviewerName.value = data.name; await loadNextItem() }
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
  return { labelled: myLabelledCount(selectedField.value), total: f?.total ?? 0 }
})

const reviewerTotalsForField = computed(() => {
  const f = stats.value.fields.find((f: any) => f.field === selectedField.value)
  if (!f?.labelledByReviewer) return ''
  return Object.entries(f.labelledByReviewer as Record<string, number>)
    .map(([name, count]) => `${name}: ${count}`).join(', ')
})

const allModels = computed(() => {
  const models = new Set<string>()
  for (const field of stats.value.fields) {
    if (field.modelAccuracy) Object.keys(field.modelAccuracy).forEach((m: string) => models.add(m))
  }
  return Array.from(models).sort()
})

const hasAnyLabels = computed(() => stats.value.fields.some((f: any) => f.labelledTotal >= 10))

const totalLabelsByReviewer = (reviewer: string): number =>
  stats.value.fields.reduce((sum: number, f: any) => sum + (f.labelledByReviewer?.[reviewer] ?? 0), 0)

const formatModelName = (model: string) => {
  if (model.includes('claude-sonnet')) return 'Claude Sonnet'
  if (model.includes('gemini')) return 'Gemini Flash'
  if (model.includes('gpt-4o')) return 'GPT-4o'
  if (model.includes('Qwen')) return 'Qwen2.5'
  return model
}

const modelColor = (model: string) => {
  if (model.includes('claude')) return 'text-blue-400'
  if (model.includes('gemini')) return 'text-violet-400'
  if (model.includes('gpt')) return 'text-emerald-400'
  return 'text-teal-400'
}

const accuracyColor = (acc: number) => {
  if (acc >= 80) return 'text-green-400'
  if (acc >= 60) return 'text-amber-400'
  return 'text-red-400'
}

const formatFieldValue = (value: any, field?: string) => {
  if (value === null || value === undefined) return '—'
  if (field === 'EEE') return value == 1 ? 'Yes (EEE)' : 'No'
  return String(value)
}

const fieldValueClass = (value: any, field?: string) => {
  if (field === 'EEE') {
    return value == 1
      ? 'text-green-300 bg-green-900/40 px-2 py-0.5 rounded'
      : 'text-blue-300 bg-blue-900/40 px-2 py-0.5 rounded'
  }
  return 'text-gray-300'
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
    if (nextResp.status === 401) { reviewerName.value = null; return }
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
    }, 800)
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
  const ft = currentFieldDef.value?.type
  if (ft === 'binary' || ft === 'presence' || ft === 'condition' || ft === 'brand_presence') {
    if (e.key.toLowerCase() === 'y') {
      e.preventDefault()
      const yesLabel = ft === 'binary' ? 'eee' : ft === 'presence' ? 'present' : ft === 'condition' ? 'reusable' : 'visible'
      submitLabel(yesLabel)
    } else if (e.key.toLowerCase() === 'n') {
      e.preventDefault()
      const noLabel = ft === 'binary' ? 'not_eee' : ft === 'presence' ? 'absent' : ft === 'condition' ? 'damaged' : 'not_visible'
      submitLabel(noLabel)
    }
  }
  if (e.key.toLowerCase() === 'u' || e.key === '?') { e.preventDefault(); submitLabel('unsure') }
  if (e.key.toLowerCase() === 's') { e.preventDefault(); skipItem() }
  if (e.key === 'Tab') { e.preventDefault(); showNotes.value = !showNotes.value }
}

onMounted(async () => {
  window.addEventListener('keydown', handleKeydown)
  await checkWhoami()
})

onUnmounted(() => window.removeEventListener('keydown', handleKeydown))
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
