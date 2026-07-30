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
          <div
            v-if="reachHeld"
            class="alert alert-warning py-1 px-2 mb-0 small text-center"
          >
            This post's reach is <strong>frozen (held)</strong> — usually
            because the origin copy went back to Pending. The blue area is where
            it actually stopped.
          </div>
          <div
            v-else-if="reach?.polygon"
            class="text-muted small text-center py-1"
          >
            Blue area = how far this post has actually rippled out.
          </div>
          <div
            v-else-if="reach && !reach.rippling"
            class="text-muted small text-center py-1"
          >
            This post hasn't rippled out yet, so there's no reach to show.
          </div>
          <RipplingExplorer
            v-if="rendered"
            minimal
            hide-projection
            initial-view="outbound"
            :initial-lat="lat"
            :initial-lng="lng"
            :initial-elapsed-hours="elapsedHours"
            :actual-reach="reach?.polygon || null"
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

// A held reach is frozen (e.g. the origin copy was pulled back to Pending) — worth
// calling out, since the outline then stops short of where the post is still listed.
const reachHeld = computed(() => reach.value?.status === 'held')

// How long the post has already been live. Seeds the explorer's time position so the
// group tinting matches the post's age rather than a default slider value.
const elapsedHours = computed(() => {
  if (!props.arrival) return 0
  const t = new Date(props.arrival).getTime()
  if (Number.isNaN(t)) return 0
  return Math.max(0, (Date.now() - t) / 3600000)
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
