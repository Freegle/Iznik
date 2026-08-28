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
  DISTANCE_AXES,
} from '~/constants'

// Shared logic for the TIME-based "How far away" sliders (browse filter + Feed settings). A slider
// is a travel-time budget in MINUTES - matching the reach system (drive-time isochrones) rather
// than miles. On change we convert the chosen minutes to a crow-flies mile radius via real routing
// (location-aware, no hardcoded miles<->minutes constant) and persist BOTH:
//   - <axis>.minutesKey: the source of truth, so the slider restores.
//   - <axis>.milesKey:   the derived radius the fast Haversine filters read.
// The far-right stop means "no limit": it stores BROWSE_DISTANCE_UNLIMITED so the server's own
// reach keeps governing. `onPersisted(miles)` runs after a successful save (emit / refetch count).
//
// TWO AXES (see DISTANCE_AXES). The same question in opposite directions:
//   axis 'browse'  (INBOUND, the default) - how far away a post may be for me to see it.
//   axis 'myPosts' (OUTBOUND)             - how far away someone may be and still see my posts.
//
// They differ in exactly two ways beyond which keys they write:
//
//  1. Their TOP STOP. The inbound stop is NOT a fixed 30 minutes: the reach engine sizes each
//     post's budget from how thinly freeglers are spread around it (20 dense / 30 medium / 45
//     sparse), so the inbound slider asks the server for this member's own cap and tops out there.
//     Until that answer arrives - and whenever density cannot be measured - the flat cap applies,
//     so the slider is never wider than something the server will honour. The OUTBOUND stop is the
//     ripple ceiling (BROWSE_MINUTES_MAX) for every member, because a post's reach grows to the
//     ceiling whatever band its origin is in. Band-capping the outbound axis would tell a city
//     member their posts reach 20 minutes when they already reach 45.
//
//  2. What the top stop STORES. Inbound stores the sentinel only when the member's own band earns
//     the ceiling, and a real derived radius below it - otherwise a member below the ceiling would
//     silently inherit the widest band's reach. Outbound's range IS the ceiling, so its top stop
//     always means "no limit" and always stores the sentinel.
//
// `withPolygon` additionally asks /town/near for the OUTLINE of the chosen travel time and
// publishes it via useReachOverlay (into this axis's own slot), for the browse map to shade. It
// rides on these calls rather than having its own because the routing pass that produces the shape
// is the one this composable already makes; the Feed settings sliders leave it off, and so pay
// nothing for a map they have not got. See useReachOverlay for why the shape is an illustration and
// never a containment test.
export function useReachDistance(
  onPersisted,
  { withPolygon = false, axis = 'browse' } = {}
) {
  const { minutesKey, milesKey, metresKey, bandCapped } = DISTANCE_AXES[axis]
  const authStore = useAuthStore()
  const { me } = useMe()
  const runtimeConfig = useRuntimeConfig()
  const apiInstance = api(runtimeConfig)
  const { nextReachSeq, publishReach, clearReach } = useReachOverlay(axis)

  // The top of the slider for this member. A band-capped axis starts at the flat cap and narrows or
  // widens once the server reports the band; BROWSE_MINUTES_MAX is the ceiling across all bands, so
  // a server that reports something larger (a future band, a misconfigured env) must not stretch
  // the UI past a travel time we are willing to offer. An axis that is not band-capped sits at the
  // ceiling from the start and never moves.
  const maxMinutes = ref(
    bandCapped ? BROWSE_MINUTES_FALLBACK_MAX : BROWSE_MINUTES_MAX
  )

  // Slider position comes from this axis's saved travel-time budget.
  //
  // When the OUTBOUND budget is unset the two are still linked, so the outbound slider must show
  // the member's real current outbound reach, which is whatever browseMaxDistance yields:
  //   - they have chosen an inbound distance -> the same travel time caps their posts too, so show
  //     browseMaxMinutes.
  //   - they have never chosen one (the common case: the band default lives in the separate,
  //     inbound-only browseReachMaxDistance key) -> their posts are not capped at all, so show the
  //     top stop, which means "no limit".
  // Showing the band default here instead would understate a city member's reach by more than half.
  //
  // The gate is browseMaxDistance and NOT browseMaxMinutes, deliberately: browseMaxDistance is
  // exactly the key the outbound readers fall back to, while browseMaxMinutes is also written by
  // browse:backfill-max-distance for members who never chose (their radius goes to the separate,
  // inbound-only browseReachMaxDistance). Gating on the minutes would therefore show an outbound
  // cap to members who have none.
  //
  // A member with browseMaxDistance but NO browseMaxMinutes - a pre-2026-07-10 miles-slider write -
  // has a stored cap we cannot place on a minutes scale without inverting miles back to minutes,
  // which would take several routing calls. The `??` therefore shows the top stop. That is the
  // SAME reading the inbound slider has had since it became time-based (on master:
  // `browseMaxMinutes ?? maxMinutes.value`), and it is a known state with a designed remedy rather
  // than an open hole: browse:backfill-max-distance rewrites BOTH keys for these members, giving
  // them their band cap in minutes and reconciling the radius to match, so the top stop the control
  // shows becomes true instead of remaining a claim. See
  // BackfillBrowseMaxDistanceCommandTest::testOverridesALegacyMilesOnlyCapToTheBandCap, whose own
  // comment puts it as "the time-based slider shows this member no limit - storage must match".
  // So pinning such a member (DistanceSliders.pinOutbound) at the top stop agrees with that
  // decision rather than fighting it.
  const savedMinutes = computed(() => {
    const settings = me.value?.settings
    const own = settings?.[minutesKey]
    if (typeof own === 'number') return own
    if (axis === 'myPosts' && typeof settings?.browseMaxDistance === 'number') {
      return settings?.browseMaxMinutes ?? maxMinutes.value
    }
    return maxMinutes.value
  })

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

  // One call to /town/near answers both questions: the member's cap (which describes their
  // location, not the chosen time) and the radius for a given travel time. Null if there's no known
  // location or the call fails - best-effort, we then leave the cap and the radius unchanged.
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
      // Only a band-capped axis takes its top from the server. The outbound axis is bounded by the
      // ripple ceiling, which is the same for everyone, so cap_minutes must not narrow it.
      if (
        bandCapped &&
        typeof r?.cap_minutes === 'number' &&
        r.cap_minutes > 0
      ) {
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
  // A member from before this axis was time-based has a stored radius but no
  // minutes: for the chitchat axis that is settings.newsfeedarea in METRES
  // (or the legacy string 'nearby'). Without this, the slider claimed
  // "Anywhere" for every such member while their feed stayed filtered to the
  // old radius - misrepresenting the filter and inviting an accidental
  // widening. Invert the radius onto the minutes scale with a short
  // bisection over the same routing lookup the slider itself uses, show the
  // handle there, and persist BOTH keys so this runs once per member. The
  // stored metres value is deliberately left untouched: the feed keeps
  // filtering to exactly the radius they chose.
  async function adoptLegacyMetres() {
    if (!metresKey) return false
    const settings = me.value?.settings
    if (!settings || typeof settings[minutesKey] === 'number') return false
    const legacy = settings[metresKey]
    if (legacy === 'nearby') {
      // Old "Nearby" = their own area: the narrow end of the scale. Shown,
      // not persisted - their first drag writes real values.
      sliderValue.value = BROWSE_MINUTES_MIN
      return true
    }
    const metres = Number(legacy)
    if (!Number.isFinite(metres) || metres <= 0) return false
    const wantMiles = metres / 1609.344
    let lo = BROWSE_MINUTES_MIN
    let hi = maxMinutes.value
    for (let i = 0; i < 3; i++) {
      const mid = Math.round((lo + hi) / 2)
      const r = await reachRadiusFor(mid)
      if (r === null) return false // routing unavailable: leave the slider alone
      if (r < wantMiles) lo = mid
      else hi = mid
    }
    const minutes = Math.round((lo + hi) / 2)
    sliderValue.value = positionFor(minutes)
    settings[minutesKey] = minutes
    settings[milesKey] = wantMiles
    await authStore.saveAndGet({ settings })
    return true
  }

  async function loadCap() {
    if (await adoptLegacyMetres()) {
      return
    }
    const asked = savedMinutes.value
    await fetchNear(asked)

    const saved = me.value?.settings?.[minutesKey]
    if (bandCapped && typeof saved === 'number' && saved > maxMinutes.value) {
      await onSliderChange(maxMinutes.value)
      return
    }

    // The handle was placed before we knew the cap, so positionFor() measured it against
    // the flat fallback and clamped anything above that down onto it: a sparse-band member
    // who chose 45 was shown 30 - just past the middle of the range, about 19 miles - for
    // the whole visit, on every page carrying the slider. The watch on savedMinutes cannot
    // fix it, because for that member savedMinutes never changes value; only maxMinutes
    // does. Now the real cap is in, re-derive the position. Deliberately here rather than
    // in a watcher on maxMinutes: onSliderChange() also calls fetchNear(), so a watcher
    // would fire mid-interaction and could pull the handle back to the saved position
    // while the member was still moving it.
    sliderValue.value = positionFor(savedMinutes.value)

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
    // The outbound axis is not band-capped: its range IS the ceiling, so its top stop
    // always takes this branch and always means a genuine "no limit".
    if (atTop && maxMinutes.value >= BROWSE_MINUTES_MAX) {
      settings[minutesKey] = minutes
      settings[milesKey] = BROWSE_DISTANCE_UNLIMITED
      if (metresKey) settings[metresKey] = 0 // the axis's API form of "anywhere"
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
    settings[minutesKey] = minutes
    if (atTop) sliderValue.value = minutes
    if (radius !== null) {
      settings[milesKey] = radius
      if (metresKey) settings[metresKey] = Math.round(radius * 1609.344)
    } else {
      // The derivation failed (no known location, or the routing call errored).
      // The old cached radius belongs to a DIFFERENT slider position - keeping
      // it silently filters the feed and digests to a cap the slider no longer
      // shows (seen live: slider at 25 minutes, feed capped at a stale 1 mile).
      // Fail open instead: the server's own reach still governs, and the next
      // successful slider change - or the browse:backfill-max-distance batch
      // command - restores a derived cap.
      settings[milesKey] = BROWSE_DISTANCE_UNLIMITED
      if (metresKey) settings[metresKey] = 0
    }
    await authStore.saveAndGet({ settings })
    if (onPersisted)
      onPersisted(settings[milesKey] ?? BROWSE_DISTANCE_UNLIMITED)
  }

  // Components get the cap without asking; the composable is also called directly in tests, where
  // there is no instance to mount into and loadCap() is driven explicitly.
  //
  // Giving an axis back up (forgetting the member's choice so the readers fall back again) is
  // deliberately NOT here. It belongs to whoever owns the linked/split state - DistanceSliders -
  // and putting it here would mean that component instantiating this composable for an axis it is
  // not currently showing, which would spend a routing call on a slider nobody has asked for.
  if (getCurrentInstance()) {
    onMounted(loadCap)
  }

  return { sliderValue, maxMinutes, onSliderChange, loadCap }
}
