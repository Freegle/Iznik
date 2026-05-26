<template>
  <div class="min-h-screen bg-gray-950 text-gray-100">
    <!-- Header -->
    <header class="bg-gray-900 border-b border-gray-800 px-6 py-4 sticky top-0 z-10">
      <div class="max-w-7xl mx-auto flex items-center gap-3">
        <NuxtLink to="/" class="text-blue-400 hover:text-blue-300 text-sm">← All items</NuxtLink>
        <span class="text-gray-700">|</span>
        <h1 class="text-lg font-semibold text-white">{{ decodedName }}</h1>
        <span class="text-gray-500 text-sm">({{ images.length }} samples)</span>
      </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8 space-y-10">
      <div v-if="loading" class="text-center py-20 text-gray-500">Loading…</div>
      <div v-else-if="error" class="bg-red-900/30 border border-red-700 rounded-lg p-4 text-red-300">{{ error }}</div>

      <div
        v-else
        v-for="image in images"
        :key="`${image.messageid}-${image.attid}`"
        class="bg-gray-900 rounded-2xl overflow-hidden border border-gray-800"
      >
        <!-- ── IMAGE + MESSAGE INFO ── -->
        <div class="flex gap-0 border-b border-gray-800">
          <!-- Clickable image -->
          <button
            class="w-72 flex-shrink-0 bg-black group relative overflow-hidden focus:outline-none"
            @click="openModal(image)"
          >
            <img
              :src="image.imageUrl"
              :alt="image.subject"
              class="w-full h-64 object-contain transition group-hover:scale-105"
              @error="(e: Event) => { (e.target as HTMLImageElement).src = placeholderSvg }"
            />
            <div class="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition flex items-end justify-center pb-3">
              <span class="text-white text-xs bg-black/70 px-3 py-1 rounded-full opacity-0 group-hover:opacity-100 transition">
                View full size &amp; reasoning
              </span>
            </div>
          </button>
          <!-- Message info -->
          <div class="flex-1 p-5 flex flex-col justify-between min-w-0">
            <div>
              <h3 class="font-semibold text-base text-white leading-snug">{{ image.subject || '(no subject)' }}</h3>
              <p class="text-gray-400 text-sm mt-2 leading-relaxed line-clamp-5">{{ image.textbody }}</p>
            </div>
            <div class="flex items-center gap-4 mt-4 text-xs text-gray-600">
              <span>msg {{ image.messageid }}</span>
              <span>att {{ image.attid }}</span>
            </div>
          </div>
        </div>

        <!-- ── COMPONENT COMPARISON — the key research section ── -->
        <div class="border-b border-gray-800">
          <div class="px-5 py-2.5 bg-gray-800/40 flex items-center gap-2">
            <span class="text-xs font-bold uppercase tracking-widest text-gray-400">Components extracted</span>
            <span
              v-if="eeeRuleDisagrees(image.classifications)"
              class="text-xs px-2 py-0.5 rounded-full bg-amber-900/60 text-amber-300 border border-amber-700"
            >⚠ EEE verdict differs</span>
          </div>
          <div class="grid" :class="image.classifications.length === 2 ? 'grid-cols-2 divide-x divide-gray-800' : 'grid-cols-1'">
            <div v-for="cls in image.classifications" :key="cls.model" class="p-5">
              <!-- Model name + EEE rule badge -->
              <div class="flex items-center gap-2 mb-3">
                <span class="font-bold text-sm" :class="modelColor(cls.model)">
                  {{ formatModelName(cls.model) }}
                </span>
                <span
                  class="text-xs px-2 py-0.5 rounded-full font-medium border"
                  :class="eeeRuleBadge(cls)"
                >
                  {{ eeeRuleLabel(cls) }}
                </span>
              </div>
              <!-- Component chips -->
              <div v-if="parseComponents(cls.electricalComponentsDescription).length" class="flex flex-wrap gap-1.5">
                <span
                  v-for="comp in parseComponents(cls.electricalComponentsDescription)"
                  :key="comp"
                  class="text-xs px-2.5 py-1 rounded-full bg-gray-700 text-gray-200 border border-gray-600"
                >{{ comp }}</span>
              </div>
              <p v-else class="text-gray-600 text-sm italic">No electrical components identified</p>
            </div>
          </div>
        </div>

        <!-- ── FIELD COMPARISON TABLE ── -->
        <div class="px-5 py-4">
          <table class="w-full text-sm" aria-label="Field comparison">
            <thead>
              <tr class="text-xs text-gray-500">
                <th class="text-left font-medium pb-2 w-32">Field</th>
                <th
                  v-for="cls in image.classifications"
                  :key="cls.model"
                  class="text-left font-medium pb-2"
                  :class="modelColor(cls.model)"
                >
                  {{ formatModelName(cls.model) }}
                </th>
                <th class="text-left font-medium pb-2 w-8"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="field in comparisonFields"
                :key="field.key"
                class="border-t border-gray-800/60"
              >
                <td class="py-1.5 text-gray-300 text-xs">{{ field.label }}</td>
                <td
                  v-for="cls in image.classifications"
                  :key="cls.model"
                  class="py-1.5 text-xs font-mono text-gray-200"
                >
                  {{ formatField(cls, field.key) }}
                </td>
                <td class="py-1.5 text-center">
                  <span :class="agreementIndicator(image.classifications, field.key).cls">
                    {{ agreementIndicator(image.classifications, field.key).icon }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
          <p class="mt-2 text-xs text-gray-600">
            * Value band comparisons are inflation-sensitive: the same item type may span multiple years
            in the sample set. Adjacent-band disagreement (~) likely reflects dataset age rather than model error.
          </p>
        </div>
      </div>
    </main>

    <!-- ── MODAL ── -->
    <Teleport to="body">
      <div
        v-if="modalImage"
        class="fixed inset-0 bg-black/90 z-50 flex items-start justify-center p-4 overflow-y-auto"
        @click.self="closeModal"
      >
        <div class="bg-gray-900 rounded-2xl w-full max-w-5xl my-8 border border-gray-700 overflow-hidden shadow-2xl">
          <!-- Modal header -->
          <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800">
            <h2 class="font-semibold text-white truncate mr-4">{{ modalImage.subject }}</h2>
            <button
              class="text-gray-400 hover:text-white text-3xl leading-none flex-shrink-0 w-8 h-8 flex items-center justify-center"
              @click="closeModal"
            >×</button>
          </div>

          <!-- Full image -->
          <div class="bg-black flex justify-center py-6 px-4">
            <img
              :src="modalImage.imageUrl"
              :alt="modalImage.subject"
              class="max-h-[480px] object-contain"
              @error="(e: Event) => { (e.target as HTMLImageElement).src = placeholderSvg }"
            />
          </div>

          <!-- Message text -->
          <div v-if="modalImage.textbody" class="px-6 py-4 bg-gray-800/30 border-y border-gray-800">
            <p class="text-sm text-gray-300 whitespace-pre-wrap leading-relaxed">{{ modalImage.textbody }}</p>
          </div>

          <!-- Per-model detail columns -->
          <div
            class="grid divide-gray-800"
            :class="modalImage.classifications.length === 2 ? 'grid-cols-2 divide-x' : 'grid-cols-1'"
          >
            <div v-for="cls in modalImage.classifications" :key="cls.model" class="p-6 space-y-5">
              <h3 class="font-bold text-base" :class="modelColor(cls.model)">
                {{ formatModelName(cls.model) }}
              </h3>

              <!-- Components -->
              <section>
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-2">Components identified</div>
                <div v-if="parseComponents(cls.electricalComponentsDescription).length" class="flex flex-wrap gap-1.5">
                  <span
                    v-for="comp in parseComponents(cls.electricalComponentsDescription)"
                    :key="comp"
                    class="text-xs px-2.5 py-1 rounded-full bg-gray-700 text-gray-200 border border-gray-600"
                  >{{ comp }}</span>
                </div>
                <p v-else class="text-xs text-gray-600 italic">None identified</p>
              </section>

              <!-- EEE determination (rule-based only) -->
              <section>
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-2">EEE (component rule)</div>
                <span class="text-xs px-2.5 py-1 rounded border" :class="eeeRuleBadge(cls)">
                  {{ eeeRuleLabel(cls) }}
                </span>
                <span v-if="cls.electricalComponentsDescription && !isEeeByRule(cls)"
                  class="ml-2 text-xs text-amber-400">
                  (has supplementary components only)
                </span>
              </section>

              <!-- Reasoning -->
              <section v-if="cls.isEeeReasoning">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-2">Reasoning</div>
                <div class="bg-gray-800/50 rounded-lg p-3">
                  <p class="text-xs text-gray-300 whitespace-pre-wrap leading-relaxed">{{ cls.isEeeReasoning }}</p>
                </div>
              </section>

              <!-- All attribute fields -->
              <section>
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-2">All fields</div>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                  <dt class="text-gray-400">WEEE category</dt><dd class="text-gray-200">{{ cls.weeeCategory || '—' }}</dd>
                  <dt class="text-gray-400">Condition</dt><dd class="text-gray-200">{{ cls.condition || '—' }}</dd>
                  <dt class="text-gray-400">Brand</dt><dd class="text-gray-200">{{ cls.brand || '—' }}</dd>
                  <dt class="text-gray-400">Model #</dt><dd class="text-gray-200 font-mono">{{ cls.modelNumber || '—' }}</dd>
                  <dt class="text-gray-400">Value (£)</dt><dd class="text-gray-200">{{ cls.valueBandGbp || '—' }}</dd>
                  <dt class="text-gray-400">Photo quality</dt><dd class="text-gray-200">{{ cls.photoQuality !== null ? `${cls.photoQuality}/5` : '—' }}</dd>
                  <dt class="text-gray-400">Weight (kg)</dt>
                  <dd class="text-gray-200">
                    {{ cls.weightKgMin !== null || cls.weightKgMax !== null ? `${cls.weightKgMin ?? '?'}–${cls.weightKgMax ?? '?'}` : '—' }}
                  </dd>
                  <dt class="text-gray-400">Size (W×H×D cm)</dt>
                  <dd class="text-gray-200 font-mono">
                    {{ cls.sizeCm ? `${cls.sizeCm.w}×${cls.sizeCm.h}×${cls.sizeCm.d}` : '—' }}
                  </dd>
                  <dt class="text-gray-400">Material</dt>
                  <dd class="text-gray-200">
                    {{ cls.materialPrimary || '—' }}{{ cls.materialSecondary ? ` / ${cls.materialSecondary}` : '' }}
                  </dd>
                  <dt class="text-gray-400">Complete</dt>
                  <dd class="text-gray-200">
                    {{ cls.itemComplete === 1 ? 'Yes' : cls.itemComplete === 0 ? 'No' : '—' }}
                    {{ cls.itemCompleteNotes ? `(${cls.itemCompleteNotes})` : '' }}
                  </dd>
                  <dt class="text-gray-400">Accessories</dt>
                  <dd class="text-gray-200">
                    {{ cls.accessoriesVisible?.length ? cls.accessoriesVisible.join(', ') : 'none listed' }}
                  </dd>
                </dl>
              </section>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const name = route.params.name as string
const decodedName = decodeURIComponent(name)

const images = ref<any[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const modalImage = ref<any>(null)

const placeholderSvg =
  'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22300%22 height=%22200%22%3E%3Crect fill=%22%231f2937%22 width=%22300%22 height=%22200%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-size=%2214%22 fill=%22%236b7280%22 text-anchor=%22middle%22 dy=%22.3em%22%3EImage unavailable%3C/text%3E%3C/svg%3E'

const comparisonFields = [
  { key: 'isEeeFromComponents', label: 'EEE (rule)' },
  { key: 'weeeCategory', label: 'WEEE cat.' },
  { key: 'condition', label: 'Condition' },
  { key: 'valueBandGbp', label: 'Value (£) *' },
  { key: 'weightKgMin', label: 'Weight (kg)' },
  { key: 'sizeCm', label: 'Size (cm)' },
  { key: 'materialPrimary', label: 'Material' },
  { key: 'itemComplete', label: 'Complete?' },
  { key: 'brand', label: 'Brand' },
  { key: 'modelNumber', label: 'Model #' },
  { key: 'photoQuality', label: 'Photo quality' },
]

const formatModelName = (model: string) => {
  if (model.toLowerCase().includes('claude')) return 'Claude'
  if (model.toLowerCase().includes('gemini')) return 'Gemini'
  if (model.toLowerCase().includes('gpt')) return 'GPT-4o'
  return model
}

const modelColor = (model: string) => {
  if (model.toLowerCase().includes('claude')) return 'text-blue-400'
  if (model.toLowerCase().includes('gemini')) return 'text-violet-400'
  if (model.toLowerCase().includes('gpt')) return 'text-emerald-400'
  return 'text-gray-300'
}

const parseComponents = (desc: string | null): string[] => {
  if (!desc) return []
  return desc.split(';').map((s) => s.trim()).filter(Boolean)
}

// Three-state EEE rule verdict:
//   'eee'        — primary_eee component confirmed (isEeeFromComponents = 1)
//   'electrical' — electrical components found but none are primary_eee
//   'none'       — no electrical components identified at all
const eeeState = (cls: any): 'eee' | 'electrical' | 'none' => {
  if (cls.isEeeFromComponents === 1) return 'eee'
  const comps = parseComponents(cls.electricalComponentsDescription)
  return comps.length > 0 ? 'electrical' : 'none'
}

const isEeeByRule = (cls: any): boolean => eeeState(cls) === 'eee'

const eeeRuleLabel = (cls: any): string => {
  const s = eeeState(cls)
  if (s === 'eee') return 'EEE ✓'
  if (s === 'electrical') return 'Electrical components'
  return 'No electrical components'
}

const eeeRuleBadge = (cls: any): string => {
  const s = eeeState(cls)
  if (s === 'eee') return 'bg-green-900/50 text-green-300 border-green-700'
  if (s === 'electrical') return 'bg-amber-900/50 text-amber-300 border-amber-700'
  return 'bg-gray-800 text-gray-400 border-gray-600'
}

const eeeRuleDisagrees = (classifications: any[]) => {
  if (classifications.length < 2) return false
  const states = classifications.map(c => eeeState(c))
  return new Set(states).size > 1
}

// ── Material normalisation ────────────────────────────────────────────────
const MATERIAL_SYNONYMS: Record<string, string> = {
  fabric: 'fabric', upholstery: 'fabric', textile: 'fabric', cloth: 'fabric',
  leather: 'leather', 'faux leather': 'leather', leatherette: 'leather',
  wood: 'wood', timber: 'wood', wooden: 'wood', mdf: 'wood', chipboard: 'wood',
  metal: 'metal', steel: 'metal', aluminium: 'metal', aluminum: 'metal',
  iron: 'metal', copper: 'metal', brass: 'metal', chrome: 'metal',
  plastic: 'plastic', polymer: 'plastic', abs: 'plastic', polypropylene: 'plastic', nylon: 'plastic',
  rubber: 'rubber', silicone: 'rubber',
  glass: 'glass', 'tempered glass': 'glass', ceramic: 'ceramic', porcelain: 'ceramic',
  foam: 'foam', 'memory foam': 'foam',
}
const normMat = (m: string | null): string => {
  if (!m) return ''
  const lower = m.toLowerCase().trim()
  // Try full string first, then each word
  if (MATERIAL_SYNONYMS[lower]) return MATERIAL_SYNONYMS[lower]
  for (const word of lower.split(/[\s,/]+/)) {
    if (MATERIAL_SYNONYMS[word]) return MATERIAL_SYNONYMS[word]
  }
  return lower
}

// ── Value band ordering ───────────────────────────────────────────────────
const VALUE_BANDS = ['0-20', '20-100', '100-500', '500+']
const bandIdx = (b: string | null) => (b ? VALUE_BANDS.indexOf(b) : -1)

// ── Helpers ───────────────────────────────────────────────────────────────
const sortDims = (s: any): number[] | null => {
  if (!s || typeof s !== 'object') return null
  return [s.w, s.h, s.d].map(Number).sort((a, b) => a - b)
}

const pctDiff = (a: number, b: number): number => {
  const avg = (a + b) / 2
  return avg > 0 ? Math.abs(a - b) / avg : 0
}

const formatField = (cls: any, key: string): string => {
  const val = cls[key]
  if (key === 'isEeeFromComponents') return eeeRuleLabel(cls)
  if (val === null || val === undefined) return '—'
  if (key === 'itemComplete') return val === 1 ? 'Yes' : val === 0 ? 'No' : '—'
  if (key === 'photoQuality') return `${val}/5`
  if (key === 'weightKgMin') {
    const max = cls.weightKgMax
    return max !== null && max !== undefined ? `${val}–${max}` : `${val}`
  }
  if (key === 'sizeCm' && typeof val === 'object') return `${val.w}×${val.h}×${val.d}`
  return String(val)
}

// Returns: 'match' | 'close' | 'diff' | 'na'
const compare = (a: any, b: any, key: string): 'match' | 'close' | 'diff' | 'na' => {
  if (a === null || a === undefined || b === null || b === undefined) return 'na'
  if (key === 'photoQuality') {
    return a === b ? 'match' : Math.abs(Number(a) - Number(b)) <= 1 ? 'close' : 'diff'
  }
  if (key === 'valueBandGbp') {
    const ia = bandIdx(a), ib = bandIdx(b)
    if (ia < 0 || ib < 0) return 'na'
    return ia === ib ? 'match' : Math.abs(ia - ib) === 1 ? 'close' : 'diff'
  }
  if (key === 'weightKgMin') {
    // Compare overlapping ranges
    const [aMin, aMax] = [Number(a.weightKgMin ?? a), Number(a.weightKgMax ?? a)]
    const [bMin, bMax] = [Number(b.weightKgMin ?? b), Number(b.weightKgMax ?? b)]
    if (aMin <= bMax && bMin <= aMax) return 'match' // ranges overlap
    const gap = Math.max(aMin, bMin) - Math.min(aMax, bMax)
    const mid = (aMin + aMax + bMin + bMax) / 4
    return gap / mid <= 0.3 ? 'close' : 'diff'
  }
  if (key === 'sizeCm') {
    const da = sortDims(a), db = sortDims(b)
    if (!da || !db) return 'na'
    const diffs = da.map((v, i) => pctDiff(v, db[i]))
    const maxDiff = Math.max(...diffs)
    return maxDiff <= 0.1 ? 'match' : maxDiff <= 0.3 ? 'close' : 'diff'
  }
  if (key === 'materialPrimary') {
    const na = normMat(a), nb = normMat(b)
    if (!na || !nb) return 'na'
    return na === nb ? 'match' : 'diff'
  }
  if (key === 'isEeeFromComponents') {
    // Binary: EEE = 1, anything else (0 or null) = Not EEE
    return (a === 1) === (b === 1) ? 'match' : 'diff'
  }
  // Default: case-insensitive string equality
  return String(a).toLowerCase() === String(b).toLowerCase() ? 'match' : 'diff'
}

const agreementIndicator = (classifications: any[], key: string) => {
  if (classifications.length < 2) return { cls: '', icon: '' }

  // weightKgMin comparison needs the whole cls object for range overlap logic
  const needsCtx = key === 'weightKgMin'
  const getValue = (c: any) => needsCtx ? c : (c[key] ?? null)
  const vals = classifications.map(getValue)

  const isNull = (v: any) => needsCtx ? v.weightKgMin === null : v === null
  if (vals.every(isNull))
    return { cls: 'text-gray-700', icon: '·' }

  // Aggregate pairwise comparisons; worst result wins
  const rank = { match: 0, close: 1, diff: 2, na: 3 }
  let worst: 'match' | 'close' | 'diff' | 'na' = 'match'
  for (let i = 0; i < vals.length - 1; i++) {
    for (let j = i + 1; j < vals.length; j++) {
      const r = compare(vals[i], vals[j], key)
      if (rank[r] > rank[worst]) worst = r
    }
  }

  if (worst === 'na') return { cls: 'text-gray-700', icon: '·' }
  if (worst === 'match') return { cls: 'text-green-500', icon: '✓' }
  if (worst === 'close') return { cls: 'text-amber-400', icon: '~' }
  return { cls: 'text-red-400', icon: '✗' }
}

const openModal = (image: any) => {
  modalImage.value = image
  document.body.style.overflow = 'hidden'
}

const closeModal = () => {
  modalImage.value = null
  document.body.style.overflow = ''
}

const handleKeyDown = (e: KeyboardEvent) => {
  if (e.key === 'Escape') closeModal()
}

onMounted(async () => {
  document.addEventListener('keydown', handleKeyDown)
  try {
    const response = await fetch(`/api/item-types/${name}/images`)
    if (!response.ok) throw new Error(`API error: ${response.statusText}`)
    const data = await response.json()
    images.value = data.images || []
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    loading.value = false
  }
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeyDown)
  document.body.style.overflow = ''
})
</script>
