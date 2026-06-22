<template>
  <div>
    <b-modal
      ref="modal"
      title="Who can see this post?"
      size="lg"
      no-stacking
    >
      <template #default>
        <div v-if="loading" class="text-center p-4">
          <b-spinner /> Loading reach&hellip;
        </div>
        <NoticeMessage v-else-if="!reach || !reach.rippling" variant="info">
          This post isn't rippling out yet, so it's currently only visible on
          its original group(s). The reach map appears once it starts to ripple
          out to neighbouring areas.
        </NoticeMessage>
        <div v-else>
          <p class="mb-2">
            The shaded area shows roughly where this post can currently be seen.
            It started at the marker and ripples outwards over about a week.
          </p>
          <div style="width: 100%; height: 480px">
            <l-map
              ref="mapObject"
              :zoom="10"
              :max-zoom="17"
              :center="[reach.lat, reach.lng]"
              @ready="ready"
            >
              <l-tile-layer :url="osmtile()" :attribution="attribution()" />
              <l-geo-json
                v-if="reachJSON"
                ref="reachobj"
                :geojson="reachJSON"
                :options="reachOptions"
              />
              <l-marker :lat-lng="[reach.lat, reach.lng]" />
            </l-map>
          </div>
          <dl class="row mt-3 mb-0 small">
            <dt class="col-sm-5">Reach so far</dt>
            <dd class="col-sm-7">
              <span v-if="reach.totalticks">
                Step {{ reach.tick }} of {{ reach.totalticks }}
              </span>
              <span v-else>Step {{ reach.tick }}</span>
            </dd>
            <dt class="col-sm-5">Status</dt>
            <dd class="col-sm-7">{{ statusLabel }}</dd>
            <dt class="col-sm-5">Next ripple out</dt>
            <dd class="col-sm-7">{{ nextRippleLabel }}</dd>
          </dl>
        </div>
      </template>
      <template #footer>
        <b-button variant="white" @click="hide"> Close </b-button>
      </template>
    </b-modal>
  </div>
</template>
<script setup>
import { ref, computed } from 'vue'
import { LGeoJson } from '@vue-leaflet/vue-leaflet'
import { attribution, osmtile } from '~/composables/useMap'
import { useOurModal } from '~/composables/useOurModal'
import { useMessageStore } from '~/stores/message'

const props = defineProps({
  messageid: {
    type: Number,
    required: true,
  },
})

const messageStore = useMessageStore()

const { modal, show: showModal, hide } = useOurModal({ autoShow: false })

const loading = ref(false)
const reach = ref(null)
const mapObject = ref(null)
const reachobj = ref(null)

// The current reach polygon comes back as a GeoJSON geometry from the API.
const reachJSON = computed(() => reach.value?.polygon || null)

const reachOptions = {
  fillColor: '#61ae24',
  fillOpacity: 0.25,
  color: '#4d8b1d',
  weight: 2,
}

const statusLabel = computed(() => {
  switch (reach.value?.status) {
    case 'expanding':
      return 'Still rippling out'
    case 'done':
      return 'Fully rippled out'
    case 'stopped':
      return 'Rippling stopped'
    default:
      return reach.value?.status || 'Unknown'
  }
})

// We can't (cheaply) compute the shape of the next reach, but we do know when the
// next expansion is scheduled, so we surface the timing.
const nextRippleLabel = computed(() => {
  const r = reach.value
  if (!r) return ''
  if (r.status === 'done') return 'Fully rippled out — no more expansions'
  if (r.status === 'stopped') return 'Rippling stopped'
  if (!r.nextexpansionat) return 'Not scheduled'

  // MySQL timestamps come back as "YYYY-MM-DD HH:MM:SS" in UTC (the server runs UTC).
  const when = new Date(r.nextexpansionat.replace(' ', 'T') + 'Z')
  const ms = when.getTime() - Date.now()
  if (Number.isNaN(when.getTime())) return 'Not scheduled'
  if (ms <= 0) return 'Due now'

  const mins = Math.round(ms / 60000)
  if (mins < 60) return `Due in about ${mins} minute${mins === 1 ? '' : 's'}`
  const hours = Math.round(mins / 60)
  if (hours < 48) return `Due in about ${hours} hour${hours === 1 ? '' : 's'}`
  const days = Math.round(hours / 24)
  return `Due in about ${days} day${days === 1 ? '' : 's'}`
})

async function show() {
  loading.value = true
  reach.value = null
  showModal()
  try {
    reach.value = await messageStore.fetchReach(props.messageid)
  } finally {
    loading.value = false
  }
}

function ready() {
  if (process.client) {
    fitToReach()
  }
}

function fitToReach() {
  try {
    if (mapObject.value?.leafletObject && reachobj.value?.leafletObject) {
      const bounds = reachobj.value.leafletObject.getBounds()
      if (bounds.isValid()) {
        mapObject.value.leafletObject.fitBounds(bounds.pad(0.1))
      }
    }
  } catch (e) {
    console.log('ModMessageReachMap fit error', e)
  }
}

defineExpose({ show, hide })
</script>
