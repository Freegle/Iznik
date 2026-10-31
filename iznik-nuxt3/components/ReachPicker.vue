<template>
  <div class="reach-picker">
    <p v-if="online" class="text-muted small mb-0">
      Online opportunities are shown to freeglers everywhere.
    </p>
    <template v-else>
      <div class="reach-picker__centre">
        <span>
          Shown around
          <strong>{{ centreName }}</strong>
        </span>
        <b-button
          v-if="!choosing"
          variant="link"
          size="sm"
          class="p-0 ms-2"
          @click="choosing = true"
        >
          Change
        </b-button>
      </div>
      <div v-if="choosing" class="reach-picker__postcode">
        <!-- An empty value stops PostCode filling in, and selecting, the member's own
             postcode the moment it opens. -->
        <PostCode
          value=""
          size="md"
          :find="false"
          label="Postcode where it's happening"
          @selected="choosePlace"
        />
        <b-button variant="link" size="sm" class="p-0" @click="useMyLocation">
          Use my location instead
        </b-button>
      </div>
      <label :for="sliderId" class="reach-picker__label mt-2">
        How far away should people see it?
      </label>
      <RangeSlider
        :id="sliderId"
        v-model="minutes"
        :min="BROWSE_MINUTES_MIN"
        :max="BROWSE_MINUTES_MAX"
        :step="BROWSE_MINUTES_STEP"
        left-label="Nearer"
        right-label="Further"
        aria-label="How far away people see it"
        @change="emitValue"
      />
      <NearbyTowns
        v-if="centre"
        eager
        :minutes="minutes"
        :lat="centre.lat"
        :lng="centre.lng"
        perspective="outbound"
      />
      <p class="text-muted small mt-2 mb-0">
        {{ hint }}
      </p>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import RangeSlider from '~/components/RangeSlider.vue'
import NearbyTowns from '~/components/NearbyTowns.vue'
import PostCode from '~/components/PostCode.vue'
import { useMe } from '~/composables/useMe'
import { useLocationStore } from '~/stores/location'
import { uid } from '~/composables/useId'
import api from '~/api'
import {
  BROWSE_MINUTES_MIN,
  BROWSE_MINUTES_MAX,
  BROWSE_MINUTES_STEP,
  BROWSE_MINUTES_FALLBACK_MAX,
} from '~/constants'

// Where a community event or volunteering opportunity is shown, and how far. With no
// communities to post to, each item is shown around a centre - the poster's location
// unless they name a postcode - to everyone within a drive time they choose here.
//
// The slider starts at the density cap for the centre, which is as far as a post from
// there would reach, so posting once does the job posting to several communities used to.
// The server turns the drive time into a radius when the item is saved.
//
// v-model is { lat, lng, reachminutes }. lat/lng are null for a new item on the poster's own
// location; the server fills them in.
const props = defineProps({
  modelValue: { type: Object, required: true },
  // An online volunteering opportunity is shown everywhere, so there is nothing to pick.
  online: { type: Boolean, default: false },
  // What the distance means for the thing being placed.
  hint: {
    type: String,
    default:
      'You only need to add it once. Everyone within this distance can see it.',
  },
})

const emit = defineEmits(['update:modelValue'])

const { me } = useMe()
const locationStore = useLocationStore()
const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const sliderId = uid('reach-')
const choosing = ref(false)
const placeName = ref(null)

const centre = computed(() => {
  if (props.modelValue.lat && props.modelValue.lng) {
    return { lat: props.modelValue.lat, lng: props.modelValue.lng }
  }
  if (me.value?.lat || me.value?.lng) {
    return { lat: me.value.lat, lng: me.value.lng }
  }
  return null
})

const isMine = computed(
  () =>
    !props.modelValue.lat ||
    (props.modelValue.lat === me.value?.lat &&
      props.modelValue.lng === me.value?.lng)
)

const centreName = computed(() =>
  isMine.value ? 'your location' : placeName.value || 'the place you chose'
)

const minutes = ref(
  props.modelValue.reachminutes || BROWSE_MINUTES_FALLBACK_MAX
)

function emitValue() {
  emit('update:modelValue', {
    ...props.modelValue,
    reachminutes: minutes.value,
  })
}

function choosePlace(pc) {
  if (pc?.lat && pc?.lng) {
    placeName.value = pc.name
    choosing.value = false
    emit('update:modelValue', {
      ...props.modelValue,
      lat: pc.lat,
      lng: pc.lng,
      reachminutes: minutes.value,
    })
  }
}

function useMyLocation() {
  placeName.value = null
  choosing.value = false
  // Sent explicitly rather than as null, so an existing item moves back to the poster.
  emit('update:modelValue', {
    ...props.modelValue,
    lat: me.value?.lat ?? null,
    lng: me.value?.lng ?? null,
    reachminutes: minutes.value,
  })
}

// Name a chosen centre we did not just pick here (an existing item), so the poster can
// see where it is shown around.
async function nameCentre() {
  const { lat, lng } = props.modelValue
  if (!lat || !lng || placeName.value || isMine.value) return
  try {
    const loc = await locationStore.fetchByLatLng(lat, lng)
    placeName.value = loc?.name || null
  } catch (e) {
    placeName.value = null
  }
}

// With no drive time chosen yet, start at the cap for the centre: how far a post from
// there reaches. /town/near reports it whatever minutes it is asked about.
async function adoptDefault() {
  if (props.modelValue.reachminutes || !centre.value) return
  try {
    const r = await apiInstance.town.fetchNear(
      centre.value.lat,
      centre.value.lng,
      BROWSE_MINUTES_FALLBACK_MAX
    )
    if (typeof r?.cap_minutes === 'number' && r.cap_minutes > 0) {
      minutes.value = Math.min(
        Math.max(r.cap_minutes, BROWSE_MINUTES_MIN),
        BROWSE_MINUTES_MAX
      )
    }
  } catch (e) {
    // Keep the flat default; the server applies the real cap if nothing is sent.
  }
  emitValue()
}

watch(
  centre,
  () => {
    nameCentre()
    adoptDefault()
  },
  { immediate: true }
)

watch(
  () => props.modelValue.reachminutes,
  (m) => {
    if (m && m !== minutes.value) minutes.value = m
  }
)
</script>

<style scoped lang="scss">
.reach-picker__centre {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
}

.reach-picker__label {
  font-weight: 500;
  font-size: 0.9rem;
  margin-bottom: 0;
}
</style>
