<template>
  <!--
    "Near: <places>" hint under a distance slider. Fixed single-line height so the async result
    never shifts the page (CLS-safe). Renders lazily - the routing-backed fetch (~1-3s) only fires
    once this is actually scrolled into view (most settings visits never reach it). Shows a
    pulsating loader while fetching, the list when found, and nothing (space kept) when none.
  -->
  <div
    v-observe-visibility="onVisibility"
    class="nearby-towns text-muted small mt-1"
    aria-live="polite"
  >
    <span v-if="loading" class="pulsate">Finding nearby places…</span>
    <span v-else-if="towns.length">Near: {{ towns.join(', ') }}</span>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useMe } from '~/composables/useMe'
import api from '~/api'

// Up to 5 FURTHEST towns the current setting reaches by travel, biggest-population first. Names
// only, no units, so the miles-slider / drive-time mismatch is invisible.
const props = defineProps({
  miles: { type: Number, required: true },
})

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)
const { me } = useMe()

const towns = ref([])
const loading = ref(false)
const visible = ref(false)
let timer = null
let seq = 0

// Debounce so dragging the slider doesn't spam the (expensive) routing pass.
function schedule() {
  if (timer) clearTimeout(timer)
  timer = setTimeout(fetchTowns, 350)
}

async function fetchTowns() {
  if (!visible.value) return
  const lat = me.value?.lat
  const lng = me.value?.lng
  const miles = props.miles
  if ((!lat && !lng) || !miles) {
    towns.value = []
    loading.value = false
    return
  }
  const mySeq = ++seq
  loading.value = true
  try {
    const r = await apiInstance.town.fetchNear(lat, lng, miles)
    if (mySeq !== seq) return // a newer request superseded this one
    towns.value = r?.towns || []
  } catch (e) {
    if (mySeq === seq) towns.value = []
  } finally {
    if (mySeq === seq) loading.value = false
  }
}

// Lazy: first time it scrolls into view, fetch. Thereafter re-fetch on slider changes.
function onVisibility(isVisible) {
  if (isVisible && !visible.value) {
    visible.value = true
    schedule()
  }
}
watch(
  () => props.miles,
  () => {
    if (visible.value) schedule()
  }
)
</script>

<style scoped>
.nearby-towns {
  /* Reserve exactly one line, always, so the loader/results appearing never shift the layout
     (CLS). One line + ellipsis: names are ordered biggest-first, so any overflow drops the
     smaller places, never the significant ones. */
  height: 1.25rem;
  line-height: 1.25rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
