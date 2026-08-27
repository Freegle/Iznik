import { computed, markRaw } from 'vue'

// The shaded area on the browse map: the member's real drive-time reach, as traced by the
// routing server, rather than a hull drawn round wherever posts happen to be.
//
// It lives in shared state because of who can afford to ask for it. The shape comes from a
// Dijkstra over the road network, and the distance slider ALREADY runs that Dijkstra on every
// change (via /town/near, to convert the chosen minutes into a mile radius). So the slider
// publishes the shape it gets for free and the map subscribes, instead of the map making a
// second identical routing call.
//
// The consequence is that the overlay only appears where a slider is mounted (browse). On
// explore and the landing pages PostMap falls back to its post hull, which is the right
// answer there: those pages have no travel-time setting for a reach to be drawn from.
//
// The shape is an ILLUSTRATION of how far the member can travel, NOT the set of posts they
// can see. Each post ripples outward from its OWN origin with its own budget, so a post can
// reach a member who could not have reached it in the same time. Never use this for
// containment.

const STATE_KEY = 'reach-overlay'

function emptyState() {
  return { geojson: null, seq: 0 }
}

// One slot per distance axis (see DISTANCE_AXES): 'browse' is the shape of what the member can
// see, 'myPosts' the shape of who can see their posts. They are separate slots rather than one
// because the two sliders fetch independently, so a shared sequence number would let a change on
// one axis discard the other axis's in-flight shape and blank half the map.
export function useReachOverlay(axis = 'browse') {
  const state = useState(STATE_KEY + ':' + axis, emptyState)

  // The GeoJSON Feature to shade, or null when we have nothing (no location, routing
  // unavailable, or a reach too small to trace). Callers fall back rather than draw nothing.
  const reachGeoJSON = computed(() => state.value.geojson)

  // nextReachSeq/publishReach are the out-of-order guard. Dragging the slider fires several
  // overlapping /town/near calls, and without this the LAST to land wins rather than the
  // LATEST asked for, which leaves the map showing a travel time the slider no longer says.
  // Take a sequence number before fetching, hand it back on publish, and a stale answer is
  // dropped.
  function nextReachSeq() {
    state.value = { ...state.value, seq: state.value.seq + 1 }
    return state.value.seq
  }

  function publishReach(seq, geojson) {
    if (seq !== state.value.seq) return
    state.value = {
      ...state.value,
      // markRaw: the shape is inert data on its way to Leaflet, never something we mutate
      // and re-render from. Without it Vue deep-proxies every one of the ~2,000 coordinate
      // pairs in a wide reach, which costs allocations for reactivity nothing reads.
      geojson: geojson ? markRaw(geojson) : null,
    }
  }

  // Empty the overlay now. Counts as a newer event than anything already in flight - a
  // clear happens because we have just learned there is no reach to draw (no location, a
  // logout), so a response issued before it must not put the old shape back.
  function clearReach() {
    state.value = { ...emptyState(), seq: state.value.seq + 1 }
  }

  return {
    reachGeoJSON,
    nextReachSeq,
    publishReach,
    clearReach,
  }
}
