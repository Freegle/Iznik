<template>
  <!--
    Reach hint below the distance slider, from a single routing pass server-side:
    "Upto X-Y miles by road" (X-Y = min/max frontier road-distance across directions) followed by
    example places it covers ("e.g. Town, Town", biggest-population first) - or the nearest place
    when none are in reach. Responsive: on mobile the town list drops to its own line and truncates
    with an ellipsis; on tablet and up it's one line, ellipsis-truncated. Fixed per-breakpoint height
    so the async result doesn't shift the page (CLS-safe). Fetches lazily, only once scrolled into
    view. Pulsates while fetching.
  -->
  <div
    v-observe-visibility="onVisibility"
    class="nearby-towns text-muted small mt-1"
    :class="{ 'nearby-towns--wrap': bits.wrap }"
    aria-live="polite"
  >
    <span v-if="loading" class="pulsate">Finding nearby places…</span>
    <template v-else-if="bits.lead || bits.tail">
      <span class="nt-lead">{{ bits.lead }}</span
      ><span v-if="bits.sep" class="nt-sep">{{ bits.sep }}</span
      ><span class="nt-tail" :class="{ 'nt-tail--wrap': bits.wrap }">{{
        bits.tail
      }}</span>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useMe } from '~/composables/useMe'
import api from '~/api'

// Furthest towns the setting reaches (biggest-population first) as examples, plus the road-distance
// reach range. Real miles - the reach follows road distance and travel time, which the slider copy
// explains.
const props = defineProps({
  // The slider's travel-time budget in MINUTES. The reach hint it drives is still shown in road
  // miles (the frontier the isochrone reaches), which is what members find meaningful.
  minutes: { type: Number, required: true },
})

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)
const { me } = useMe()

const towns = ref([])
const closer = ref('')
const frontierMedian = ref(null)
const frontierMax = ref(null)
const loading = ref(false)
const visible = ref(false)
let timer = null
let seq = 0

// Split into a fixed lead ("Upto X-Y miles by road") that always stays visible and a tail (the town
// examples, or the nearest place) that truncates with an ellipsis. `sep` joins them on one line
// (tablet+); it's hidden on mobile where the tail drops to its own line.
const bits = computed(() => {
  const lo = frontierMedian.value
  const hi = frontierMax.value
  let lead = ''
  if (lo != null && hi != null) {
    const a = Math.round(lo)
    const b = Math.round(hi)
    if (b > 0) {
      const unit = b === 1 ? 'mile' : 'miles'
      const dist = a > 0 && a < b ? `${a}-${b}` : `${b}`
      lead = `Max ${dist} ${unit} by road`
    }
  }
  if (towns.value.length) {
    return {
      lead,
      sep: lead ? ', ' : '',
      tail: `e.g. ${towns.value.join(', ')}`,
      wrap: false,
    }
  }
  if (closer.value) {
    // Unlike the "e.g. Town, Town" examples list above (safe to ellipsis-clip - losing an
    // example or two still leaves a useful hint), this tail is a single town name and IS the
    // whole message. Ellipsis-truncating it can hide the only information it conveys, so it
    // wraps instead of clipping (`wrap: true` -> .nt-tail--wrap, Discourse 9808).
    // "Close to X" not "Closer than X": at the minimum setting the reach still includes
    // areas beyond X, so "closer than" is misleading (Neville, Discourse 9808/584).
    return {
      lead,
      sep: lead ? '. ' : '',
      tail: `Close to ${closer.value}`,
      wrap: true,
    }
  }
  return { lead, sep: '', tail: '', wrap: false }
})

// Debounce so dragging the slider doesn't spam the (expensive) routing pass.
function schedule() {
  if (timer) clearTimeout(timer)
  timer = setTimeout(fetchTowns, 350)
}

function reset() {
  towns.value = []
  closer.value = ''
  frontierMedian.value = null
  frontierMax.value = null
}

async function fetchTowns() {
  if (!visible.value) return
  const lat = me.value?.lat
  const lng = me.value?.lng
  const minutes = props.minutes
  if ((!lat && !lng) || !minutes) {
    reset()
    loading.value = false
    return
  }
  const mySeq = ++seq
  loading.value = true
  try {
    const r = await apiInstance.town.fetchNear(lat, lng, minutes)
    if (mySeq !== seq) return // a newer request superseded this one
    towns.value = r?.towns || []
    closer.value = r?.closer_than || ''
    frontierMedian.value =
      typeof r?.frontier_median_miles === 'number'
        ? r.frontier_median_miles
        : null
    frontierMax.value =
      typeof r?.frontier_max_miles === 'number' ? r.frontier_max_miles : null
  } catch (e) {
    if (mySeq === seq) reset()
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
  () => props.minutes,
  () => {
    if (visible.value) schedule()
  }
)
</script>

<style scoped>
/* Mobile-first: two lines - the range on line 1, the town examples on line 2, ellipsis-truncated.
   min-height reserves both lines so the async result never shifts the page (CLS-safe). */
.nearby-towns {
  min-height: 2.5rem;
  line-height: 1.25rem;
}
.nt-lead {
  display: block;
  white-space: nowrap;
}
.nt-sep {
  display: none; /* only used to join lead + tail inline on tablet+ */
}
.nt-tail {
  display: block;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
/* The "Close to X" nearest-town name IS the whole message, unlike the "e.g. Town, Town"
   examples list above (safe to ellipsis-clip - losing an example or two still leaves a useful
   hint). Clipping the single town name could hide the only information it conveys, so it wraps
   onto another line instead of truncating (Discourse 9808). */
.nt-tail--wrap {
  white-space: normal;
  overflow: visible;
  text-overflow: clip;
}

/* Tablet and up: a single line, ellipsis-truncated. Inline (not flex) so a parent text-align:center
   actually centres the line when it fits; when it overflows, the container's own ellipsis clips the
   tail (the town list) while the lead ("Upto X-Y miles by road") stays visible. */
@media (min-width: 768px) {
  .nearby-towns {
    min-height: 1.25rem;
    height: 1.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  /* The "Close to X" name wraps rather than clips (bits.wrap -> .nt-tail--wrap).
     That case is breakpoint-agnostic (set from server data, not viewport), so at
     >=768px the fixed single-line box above would clip the wrapped 2nd line
     vertically with no ellipsis. Let the container grow to fit instead (Discourse
     9808). Must follow the .nearby-towns rule to win at equal specificity. */
  .nearby-towns--wrap {
    height: auto;
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
  }
  .nt-lead,
  .nt-sep,
  .nt-tail {
    display: inline;
  }
}

.pulsate {
  animation: pulsate 1.2s ease-in-out infinite;
}
@keyframes pulsate {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.45;
  }
}
</style>
