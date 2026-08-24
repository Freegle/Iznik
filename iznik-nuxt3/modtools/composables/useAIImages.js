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

  function regenerate(id, notes) {
    return $api.aiimages.regenerate(id, notes)
  }

  async function accept(id, pendingExternaluid) {
    const result = await $api.aiimages.accept(id, pendingExternaluid)
    count.value = Math.max(0, count.value - 1)
    return result
  }

  async function keep(id) {
    const result = await $api.aiimages.keep(id)
    count.value = Math.max(0, count.value - 1)
    return result
  }

  async function suppress(id) {
    const result = await $api.aiimages.suppress(id)
    count.value = Math.max(0, count.value - 1)
    return result
  }

  return {
    count: readonly(count),
    images: readonly(images),
    loading: readonly(loading),
    fetchCount,
    fetchReview,
    regenerate,
    accept,
    keep,
    suppress,
  }
}
