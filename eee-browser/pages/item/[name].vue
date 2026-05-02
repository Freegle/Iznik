<template>
  <div class="min-h-screen bg-gray-100 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-7xl mx-auto">
      <div class="mb-8">
        <NuxtLink to="/" class="text-blue-600 hover:text-blue-800 mb-4 inline-block">
          ← Back to Items
        </NuxtLink>
        <h1 class="text-4xl font-bold text-gray-900">{{ decodedName }}</h1>
      </div>

      <div v-if="loading" class="text-center py-12">
        <p class="text-gray-600">Loading classification data...</p>
      </div>

      <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-8">
        <p class="text-red-800">{{ error }}</p>
      </div>

      <div v-else class="space-y-12">
        <div
          v-for="image in images"
          :key="`${image.messageid}-${image.attid}`"
          class="bg-white rounded-lg shadow-lg overflow-hidden"
        >
          <!-- Image Section -->
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 p-6">
            <div class="lg:col-span-1">
              <img
                :src="image.imageUrl"
                :alt="image.subject"
                class="w-full h-auto rounded-lg object-cover max-h-96"
                @error="(e) => (e.target.src = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22300%22 height=%22300%22%3E%3Crect fill=%22%23f0f0f0%22 width=%22300%22 height=%22300%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-size=%2220%22 fill=%22%23999%22 text-anchor=%22middle%22 dy=%22.3em%22%3EImage not available%3C/text%3E%3C/svg%3E')"
              />
              <div class="mt-4 p-4 bg-gray-50 rounded">
                <h3 class="font-semibold text-gray-900 mb-2">Subject</h3>
                <p class="text-sm text-gray-700">{{ image.subject || 'N/A' }}</p>
              </div>
            </div>

            <!-- Classifications Section -->
            <div class="lg:col-span-2">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div
                  v-for="classification in image.classifications"
                  :key="classification.model"
                  :class="getAgreementClass(image.classifications, classification)"
                  class="p-4 rounded-lg border-l-4"
                >
                  <h4 class="font-bold text-lg mb-3 text-gray-900">
                    {{ formatModelName(classification.model) }}
                  </h4>

                  <!-- is_eee Badge -->
                  <div class="mb-3">
                    <span class="text-xs font-semibold text-gray-600">is_eee</span>
                    <div class="mt-1">
                      <span
                        :class="
                          classification.isEee === null
                            ? 'badge-unknown'
                            : classification.isEee
                              ? 'badge-true'
                              : 'badge-false'
                        "
                      >
                        {{ classification.isEee === null ? 'Unknown' : classification.isEee ? 'Yes' : 'No' }}
                      </span>
                      <span class="text-xs text-gray-500 ml-2">
                        ({{ (classification.isEeeConfidence * 100).toFixed(0) }}%)
                      </span>
                    </div>
                  </div>

                  <!-- EEE Components -->
                  <div class="mb-3">
                    <span class="text-xs font-semibold text-gray-600">contains_eee_components</span>
                    <div class="mt-1">
                      <span
                        :class="
                          classification.containsEeeComponents === null
                            ? 'badge-unknown'
                            : classification.containsEeeComponents
                              ? 'badge-true'
                              : 'badge-false'
                        "
                      >
                        {{ classification.containsEeeComponents === null ? 'Unknown' : classification.containsEeeComponents ? 'Yes' : 'No' }}
                      </span>
                    </div>
                  </div>

                  <!-- Electrical Components Description -->
                  <div v-if="classification.electricalComponentsDescription" class="mb-3">
                    <span class="text-xs font-semibold text-gray-600">electrical_components</span>
                    <p class="text-sm text-gray-700 mt-1">
                      {{ truncateText(classification.electricalComponentsDescription, 100) }}
                    </p>
                  </div>

                  <!-- Key Fields -->
                  <div class="space-y-2 text-sm mb-3">
                    <div v-if="classification.brand" class="flex justify-between">
                      <span class="text-gray-600">Brand:</span>
                      <span class="font-medium">{{ classification.brand }}</span>
                    </div>
                    <div v-if="classification.modelNumber" class="flex justify-between">
                      <span class="text-gray-600">Model #:</span>
                      <span class="font-medium">{{ classification.modelNumber }}</span>
                    </div>
                    <div v-if="classification.condition" class="flex justify-between">
                      <span class="text-gray-600">Condition:</span>
                      <span class="font-medium">{{ classification.condition }}</span>
                    </div>
                    <div v-if="classification.photoQuality" class="flex justify-between">
                      <span class="text-gray-600">Photo Quality:</span>
                      <span class="font-medium">{{ classification.photoQuality }}/5</span>
                    </div>
                    <div v-if="classification.valueBandGbp" class="flex justify-between">
                      <span class="text-gray-600">Value (GBP):</span>
                      <span class="font-medium">{{ classification.valueBandGbp }}</span>
                    </div>
                    <div v-if="classification.weightKgMin || classification.weightKgMax" class="flex justify-between">
                      <span class="text-gray-600">Weight (kg):</span>
                      <span class="font-medium">
                        {{ classification.weightKgMin || '?' }} - {{ classification.weightKgMax || '?' }}
                      </span>
                    </div>
                  </div>

                  <!-- Reasoning -->
                  <details v-if="classification.isEeeReasoning" class="text-sm">
                    <summary class="cursor-pointer font-semibold text-gray-700 hover:text-gray-900">
                      Reasoning
                    </summary>
                    <div class="mt-2 p-3 bg-gray-50 rounded text-gray-700 whitespace-pre-wrap text-xs">
                      {{ classification.isEeeReasoning }}
                    </div>
                  </details>
                </div>
              </div>
            </div>
          </div>

          <!-- Description -->
          <div v-if="image.textbody" class="border-t border-gray-200 p-6 bg-gray-50">
            <h5 class="font-semibold text-gray-900 mb-2">Description</h5>
            <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ truncateText(image.textbody, 500) }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const name = route.params.name as string
const decodedName = decodeURIComponent(name)

const images = ref<any[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const formatModelName = (model: string) => {
  if (model.includes('claude')) return 'Claude'
  if (model.includes('gemini')) return 'Gemini'
  return model
}

const truncateText = (text: string, maxLength: number) => {
  if (!text) return ''
  return text.length > maxLength ? text.substring(0, maxLength) + '...' : text
}

const getAgreementClass = (classifications: any[], current: any) => {
  if (classifications.length < 2) return ''

  const otherClassification = classifications.find((c) => c.model !== current.model)
  if (!otherClassification) return ''

  // Check if both agree on is_eee
  if (current.isEee === otherClassification.isEee) {
    return 'agreement'
  }

  return 'disagreement'
}

onMounted(async () => {
  try {
    const response = await fetch(`/api/item-types/${name}/images`)
    if (!response.ok) {
      throw new Error(`API error: ${response.statusText}`)
    }
    const data = await response.json()
    images.value = data.images || []
  } catch (e) {
    error.value = (e as Error).message
    console.error('Failed to load images:', e)
  } finally {
    loading.value = false
  }
})
</script>
