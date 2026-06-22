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

const props = defineProps({
  lat: { type: Number, default: null },
  lng: { type: Number, default: null },
  // The post's arrival time, so the reach opens at how far it has already rippled.
  arrival: { type: [String, Date], default: null },
})

const runtimeConfig = useRuntimeConfig()
const { jwt } = useMe()

const { modal, show: showModal, hide } = useOurModal({ autoShow: false })

// Only mount the explorer while the modal is open, so it seeds + plays the ripple
// fresh each time and tears down (releasing its map/animation) on close.
const rendered = ref(false)

const spatialUrl = computed(
  () => runtimeConfig.public.SPATIAL_SERVER_URL || 'http://localhost:8196'
)
const hasLocation = computed(() => props.lat != null && props.lng != null)

// How long the post has already been live, so the reach opens at its current point.
const elapsedHours = computed(() => {
  if (!props.arrival) return 0
  const t = new Date(props.arrival).getTime()
  if (Number.isNaN(t)) return 0
  return Math.max(0, (Date.now() - t) / 3600000)
})

function show() {
  rendered.value = true
  showModal()
}

defineExpose({ show, hide })
</script>
