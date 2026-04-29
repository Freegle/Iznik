import { ref, readonly } from 'vue'

const count = ref(0)
const images = ref([])
const loading = ref(false)

export function useAIImages() {
  const { $api } = useNuxtApp()

  async function fetchCount() {
    try {
      const data = await $api.aiimages.count()
      count.value = data?.count ?? 0
    } catch {
      count.value = 0
    }
  }

  async function fetchReview() {
    loading.value = true
    try {
      const data = await $api.aiimages.review()
      images.value = Array.isArray(data) ? data : []
      count.value = images.value.length
    } catch {
      images.value = []
    } finally {
      loading.value = false
    }
  }

  async function regenerate(id, notes) {
    return $api.aiimages.regenerate(id, notes)
  }

  async function accept(id, pendingExternaluid) {
    return $api.aiimages.accept(id, pendingExternaluid)
  }

  return {
    count: readonly(count),
    images: readonly(images),
    loading: readonly(loading),
    fetchCount,
    fetchReview,
    regenerate,
    accept,
  }
}
