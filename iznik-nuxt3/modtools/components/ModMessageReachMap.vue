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
            <span class="reach-key" />
            Shaded area = how far this post has actually rippled out.
            <template v-if="ringLegendItems.length">
              <br />
              The dashed outlines are further places it also reaches. People
              there can see this post and reply to it, and are emailed about it,
              just like people in the shaded area. Hover an outline to see why
              it is included.
              <br />
              <span
                v-for="item in ringLegendItems"
                :key="item.label"
                class="me-3 text-nowrap"
              >
                <span
                  class="ring-key"
                  :style="{ borderColor: item.color, background: item.color }"
                />
                {{ item.label }}
              </span>
            </template>
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
            :overflow-rings="reach?.overflow || null"
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
import RipplingExplorer from './RipplingExplorer.vue'
import { useRuntimeConfig } from '#imports'
import { useOurModal } from '~/composables/useOurModal'
import { useMe } from '~/composables/useMe'
import { useMessageStore } from '~/stores/message'
import { ringLegend } from '~/modtools/composables/rippling/overflowrings.js'

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

// Which ring lanes this post carries, for the caption under the map. Empty for the
// great majority of posts, which have no rings at all.
const ringLegendItems = computed(() => ringLegend(reach.value?.overflow))

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

<style scoped>
.ring-key,
.reach-key {
  display: inline-block;
  width: 0.7rem;
  height: 0.7rem;
  border: 1px solid;
  border-radius: 2px;
  vertical-align: baseline;
}

/* The rings are drawn dashed and faint on the map; the key says so. */
.ring-key {
  opacity: 0.55;
  border-style: dashed;
}

.reach-key {
  border-color: #0055cc;
  background: #0055cc;
  opacity: 0.35;
}
</style>
