<template>
  <div>
    <b-modal
      ref="modal"
      title="Rippling reach"
      :fullscreen="true"
      hide-footer
      title-class="w-100 text-center"
      body-class="p-0 overflow-hidden"
    >
      <template #default>
        <div v-if="!hasLocation" class="p-4 text-center text-muted">
          This post has no location, so its rippling reach can't be shown.
        </div>
        <div v-else style="height: 100%">
          <RipplingExplorer
            v-if="rendered"
            minimal
            initial-view="outbound"
            :initial-lat="lat"
            :initial-lng="lng"
            :initial-elapsed-hours="elapsedHours"
            :actual-elapsed-hours="actualElapsedHours"
            :spatial-url="spatialUrl"
            :jwt="jwt"
          />
        </div>
      </template>
    </b-modal>
  </div>
</template>
<script setup>
import { computed, ref } from 'vue'
import { useRuntimeConfig } from '#imports'
import RipplingExplorer from './RipplingExplorer.vue'
import { useOurModal } from '~/composables/useOurModal'
import { useMe } from '~/composables/useMe'
import { useMessageStore } from '~/stores/message'

const props = defineProps({
  messageid: { type: Number, default: null },
  lat: { type: Number, default: null },
  lng: { type: Number, default: null },
  // The post's arrival time, so the reach opens at how far it has already rippled.
  arrival: { type: [String, Date], default: null },
})

const runtimeConfig = useRuntimeConfig()
const { jwt } = useMe()
const messageStore = useMessageStore()

const { modal, show: showModal, hide } = useOurModal({ autoShow: false })

// Only mount the explorer while the modal is open, so it seeds + plays the ripple
// fresh each time and tears down (releasing its map/animation) on close.
const rendered = ref(false)
// The post's ACTUAL rippling progress from the backend (null until fetched).
const reach = ref(null)

const spatialUrl = computed(
  () => runtimeConfig.public.SPATIAL_SERVER_URL || 'http://localhost:8196'
)
const hasLocation = computed(() => props.lat != null && props.lng != null)

// How long the post has already been live: the EXPECTED point (where reach SHOULD be).
// The map + scrubber open here; this is the "up to" marker.
const elapsedHours = computed(() => {
  if (!props.arrival) return 0
  const t = new Date(props.arrival).getTime()
  if (Number.isNaN(t)) return 0
  return Math.max(0, (Date.now() - t) / 3600000)
})

// The hazard schedule (wall-clock hours per reach tick) — mirrors config/freegle.php.
const HAZARD_HOURS = [1, 3, 6, 12, 24, 48, 72, 120, 168]
function tickForHours(hours) {
  let t = 1
  HAZARD_HOURS.forEach((h, i) => {
    if (hours >= h) t = i + 1
  })
  return Math.min(t, HAZARD_HOURS.length)
}

// The ACTUAL point ("now"), as elapsed-hours, ONLY when the engine is behind where it
// should be. Null = up to date (or not rippling / dark) -> the modal shows just "up to".
const actualElapsedHours = computed(() => {
  const r = reach.value
  if (!r || !r.rippling || !r.tick) return null
  const expectedTick = tickForHours(elapsedHours.value)
  if (r.tick >= expectedTick) return null // caught up -> only "up to"
  return HAZARD_HOURS[Math.min(r.tick, HAZARD_HOURS.length) - 1]
})

async function show() {
  rendered.value = true
  reach.value = null
  showModal()
  if (props.messageid) {
    try {
      reach.value = await messageStore.fetchReach(props.messageid, false)
    } catch (e) {
      reach.value = null
    }
  }
}

defineExpose({ show, hide })
</script>
