<template>
  <p v-if="towns.length" class="nearby-towns text-muted small mb-0 mt-1">
    Near: {{ towns.join(', ') }}
  </p>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useMe } from '~/composables/useMe'
import api from '~/api'

// "Near: <places>" under a distance slider - the (up to 5) FURTHEST towns the current setting
// reaches by travel, biggest first. Names only, no units, so the miles-slider / drive-time
// mismatch is invisible. Debounced so dragging doesn't spam the routing pass.
const props = defineProps({
  miles: { type: Number, required: true },
})

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)
const { me } = useMe()

const towns = ref([])
let timer = null

watch(
  () => props.miles,
  (miles) => {
    if (timer) clearTimeout(timer)
    timer = setTimeout(async () => {
      const lat = me.value?.lat
      const lng = me.value?.lng
      if ((!lat && !lng) || !miles) {
        towns.value = []
        return
      }
      try {
        const r = await apiInstance.town.fetchNear(lat, lng, miles)
        towns.value = r?.towns || []
      } catch (e) {
        towns.value = []
      }
    }, 350)
  },
  { immediate: true }
)
</script>
