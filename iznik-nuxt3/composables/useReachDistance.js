import { ref, computed, watch } from 'vue'
import { useAuthStore } from '~/stores/auth'
import { useMe } from '~/composables/useMe'
import api from '~/api'
import {
  BROWSE_DISTANCE_UNLIMITED,
  BROWSE_MINUTES_MIN,
  BROWSE_MINUTES_MAX,
} from '~/constants'

// Shared logic for the TIME-based "How far away" slider (browse filter + Feed settings). The slider
// is a travel-time budget in MINUTES - matching the reach system (drive-time isochrones) rather than
// miles. On change we convert the chosen minutes to a crow-flies mile radius via real routing
// (location-aware, no hardcoded miles<->minutes constant) and persist BOTH:
//   - settings.browseMaxMinutes: the source of truth, so the slider restores to the right position.
//   - settings.browseMaxDistance: the derived radius the fast Haversine feed filter reads unchanged.
// The far-right (MAX) stop means "no limit": it stores BROWSE_DISTANCE_UNLIMITED so the server's own
// reach keeps governing. `onPersisted(miles)` runs after a successful save (emit / refetch count).
export function useReachDistance(onPersisted) {
  const authStore = useAuthStore()
  const { me } = useMe()
  const runtimeConfig = useRuntimeConfig()
  const apiInstance = api(runtimeConfig)

  // Slider position comes from the saved travel-time budget; default (unset) is the max = "no limit".
  const savedMinutes = computed(
    () => me.value?.settings?.browseMaxMinutes ?? BROWSE_MINUTES_MAX
  )

  function positionFor(minutes) {
    if (minutes >= BROWSE_MINUTES_MAX) return BROWSE_MINUTES_MAX
    if (minutes < BROWSE_MINUTES_MIN) return BROWSE_MINUTES_MIN
    return minutes
  }

  // Local slider position, separate from what's saved: RangeSlider emits update:modelValue every
  // drag tick for an instant visual, and a separate `change` only on release - so we persist once
  // the member settles, not on every tick.
  const sliderValue = ref(positionFor(savedMinutes.value))
  watch(savedMinutes, (m) => {
    sliderValue.value = positionFor(m)
  })

  // The crow-flies radius the chosen travel time reaches, from the routing-backed /town/near. Null
  // if there's no known location or the call fails (best-effort - we then leave the cap unchanged).
  async function reachRadiusFor(minutes) {
    const lat = me.value?.lat
    const lng = me.value?.lng
    if (!lat && !lng) return null
    try {
      const r = await apiInstance.town.fetchNear(lat, lng, minutes)
      return typeof r?.reach_radius_miles === 'number'
        ? r.reach_radius_miles
        : null
    } catch (e) {
      return null
    }
  }

  async function onSliderChange(minutes) {
    const settings = me.value?.settings
    if (!settings) return

    if (minutes >= BROWSE_MINUTES_MAX) {
      // Far right = "no limit": defer to the server's own reach extent.
      settings.browseMaxMinutes = BROWSE_MINUTES_MAX
      settings.browseMaxDistance = BROWSE_DISTANCE_UNLIMITED
      await authStore.saveAndGet({ settings })
      if (onPersisted) onPersisted(BROWSE_DISTANCE_UNLIMITED)
      return
    }

    const radius = await reachRadiusFor(minutes)
    settings.browseMaxMinutes = minutes
    if (radius !== null) {
      settings.browseMaxDistance = radius
    }
    await authStore.saveAndGet({ settings })
    if (onPersisted)
      onPersisted(settings.browseMaxDistance ?? BROWSE_DISTANCE_UNLIMITED)
  }

  return { sliderValue, onSliderChange }
}
