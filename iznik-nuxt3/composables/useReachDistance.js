import { ref, computed, watch, getCurrentInstance, onMounted } from 'vue'
import { useAuthStore } from '~/stores/auth'
import { useMe } from '~/composables/useMe'
import { useReachOverlay } from '~/composables/useReachOverlay'
import api from '~/api'
import {
  BROWSE_DISTANCE_UNLIMITED,
  BROWSE_MINUTES_MIN,
  BROWSE_MINUTES_MAX,
  BROWSE_MINUTES_FALLBACK_MAX,
} from '~/constants'

// Shared logic for the TIME-based "How far away" slider (browse filter + Feed settings). The slider
// is a travel-time budget in MINUTES - matching the reach system (drive-time isochrones) rather than
// miles. On change we convert the chosen minutes to a crow-flies mile radius via real routing
// (location-aware, no hardcoded miles<->minutes constant) and persist BOTH:
//   - settings.browseMaxMinutes: the source of truth, so the slider restores.
//   - settings.browseMaxDistance: the derived radius the fast Haversine feed filter reads.
// The far-right stop means "no limit": it stores BROWSE_DISTANCE_UNLIMITED so the server's own
// reach keeps governing. `onPersisted(miles)` runs after a successful save (emit / refetch count).
//
// The far-right stop is NOT a fixed 30 minutes. The reach engine sizes each post's budget from how
// thinly freeglers are spread around it (20 dense / 30 medium / 45 sparse), so the slider asks the
// server for this member's own cap and tops out there. Until that answer arrives - and whenever
// density cannot be measured - the flat cap applies, so the slider is never wider than something
// the server will honour.
//
// `withPolygon` additionally asks /town/near for the OUTLINE of the chosen travel time and
// publishes it via useReachOverlay, for the browse map to shade. It rides on these calls rather
// than having its own because the routing pass that produces the shape is the one this composable
// already makes; the Feed settings slider leaves it off, and so pays nothing for a map it has not
// got. See useReachOverlay for why the shape is an illustration and never a containment test.
export function useReachDistance(onPersisted, { withPolygon = false } = {}) {
  const authStore = useAuthStore()
  const { me } = useMe()
  const runtimeConfig = useRuntimeConfig()
  const apiInstance = api(runtimeConfig)
  const { nextReachSeq, publishReach, clearReach } = useReachOverlay()

  // The top of the slider for this member. Starts at the flat cap and narrows or widens once the
  // server reports the band. BROWSE_MINUTES_MAX is the ceiling across all bands: a server that
  // reports something larger (a future band, a misconfigured env) must not stretch the UI past a
  // travel time we are willing to offer.
  const maxMinutes = ref(BROWSE_MINUTES_FALLBACK_MAX)

  // Slider position comes from the saved travel-time budget; default (unset) is the top = "no limit".
  const savedMinutes = computed(
    () => me.value?.settings?.browseMaxMinutes ?? maxMinutes.value
  )

  function positionFor(minutes) {
    if (minutes >= maxMinutes.value) return maxMinutes.value
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

  // One call to /town/near answers both questions: the member's cap (which describes their location,
  // not the chosen time) and the radius for a given travel time. Null if there's no known location
  // or the call fails - best-effort, we then leave the cap and the radius unchanged.
  async function fetchNear(minutes) {
    const lat = me.value?.lat
    const lng = me.value?.lng
    if (!lat && !lng) {
      // No location means no reach to draw. Clear rather than leave the last member's
      // shape shaded on the map after a logout.
      if (withPolygon) clearReach()
      return null
    }
    // Claim the sequence number BEFORE awaiting, so an earlier-issued call that lands later
    // is recognised as stale and does not overwrite a newer shape.
    const seq = withPolygon ? nextReachSeq() : null
    try {
      const r = await apiInstance.town.fetchNear(lat, lng, minutes, withPolygon)
      if (typeof r?.cap_minutes === 'number' && r.cap_minutes > 0) {
        maxMinutes.value = Math.min(r.cap_minutes, BROWSE_MINUTES_MAX)
      }
      if (withPolygon) {
        publishReach(seq, r?.reach_polygon ?? null)
      }
      return r
    } catch (e) {
      // A failed lookup leaves the cap and radius alone (best-effort), but the shape must
      // not be left behind: it would shade a travel time the slider no longer shows.
      if (withPolygon) publishReach(seq, null)
      return null
    }
  }

  // The crow-flies radius the chosen travel time reaches, from the routing-backed /town/near.
  async function reachRadiusFor(minutes) {
    const r = await fetchNear(minutes)
    return typeof r?.reach_radius_miles === 'number'
      ? r.reach_radius_miles
      : null
  }

  // Learn this member's cap, and reconcile a saved position that now sits above it. A position above
  // the cap is one the reach engine no longer honours: leaving it saved would show the slider at the
  // top ("no limit") while the stored radius kept filtering to the old, narrower travel time - the
  // same divergence that had members seeing only old posts. So we persist the correction once,
  // rather than displaying one thing and filtering by another.
  async function loadCap() {
    const asked = savedMinutes.value
    await fetchNear(asked)

    const saved = me.value?.settings?.browseMaxMinutes
    if (typeof saved === 'number' && saved > maxMinutes.value) {
      await onSliderChange(maxMinutes.value)
      return
    }

    // A member who has never touched the slider sits at the top stop, and before the server
    // answers we do not know where that is - so the first call had to ask for the flat
    // fallback. Now that we know their real cap, the shape we drew is for the wrong travel
    // time: a rural member's slider says 45 minutes over a 30-minute shape, a city member's
    // says 20 over the same. Redraw it. Only the shape needs this; the cap and the radius are
    // already right (the sentinel, or a value the member chose).
    if (withPolygon && savedMinutes.value !== asked) {
      await fetchNear(savedMinutes.value)
    }
  }

  async function onSliderChange(minutes) {
    const settings = me.value?.settings
    if (!settings) return

    const atTop = minutes >= maxMinutes.value
    if (atTop) minutes = maxMinutes.value

    // "No limit" defers to the server's own reach, and the server grows every post's
    // reach to the widest budget ANY band earns (BROWSE_MINUTES_MAX). So the sentinel
    // only means "as far as my own band goes" for a member whose band earns that
    // ceiling. Below it - a city or middling area - the top stop still needs a real
    // derived radius, or the member would silently inherit the widest band's reach.
    if (atTop && maxMinutes.value >= BROWSE_MINUTES_MAX) {
      settings.browseMaxMinutes = minutes
      settings.browseMaxDistance = BROWSE_DISTANCE_UNLIMITED
      sliderValue.value = minutes
      await authStore.saveAndGet({ settings })
      if (onPersisted) onPersisted(BROWSE_DISTANCE_UNLIMITED)
      // This branch skips the radius derivation because "no limit" needs no radius - but the
      // map still has to be told, or dragging from 20 minutes to the top would leave the
      // 20-minute shape shaded under a slider that now says no limit.
      if (withPolygon) await fetchNear(minutes)
      return
    }

    const radius = await reachRadiusFor(minutes)
    settings.browseMaxMinutes = minutes
    if (atTop) sliderValue.value = minutes
    if (radius !== null) {
      settings.browseMaxDistance = radius
    } else {
      // The derivation failed (no known location, or the routing call errored).
      // The old cached radius belongs to a DIFFERENT slider position - keeping
      // it silently filters the feed and digests to a cap the slider no longer
      // shows (seen live: slider at 25 minutes, feed capped at a stale 1 mile).
      // Fail open instead: the server's own reach still governs, and the next
      // successful slider change - or the browse:backfill-max-distance batch
      // command - restores a derived cap.
      settings.browseMaxDistance = BROWSE_DISTANCE_UNLIMITED
    }
    await authStore.saveAndGet({ settings })
    if (onPersisted)
      onPersisted(settings.browseMaxDistance ?? BROWSE_DISTANCE_UNLIMITED)
  }

  // Components get the cap without asking; the composable is also called directly in tests, where
  // there is no instance to mount into and loadCap() is driven explicitly.
  if (getCurrentInstance()) {
    onMounted(loadCap)
  }

  return { sliderValue, maxMinutes, onSliderChange, loadCap }
}
