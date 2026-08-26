<template>
  <div class="distance-sliders">
    <span class="distance-desc d-block text-muted small">
      You'll see mostly nearby posts, but some from further away.
      <span v-if="showDragHint" class="drag-hint"
        >Drag towards <strong>Nearer</strong> or
        <strong>Further</strong>. </span
      >We use road distance and travel time to take account of geography in
      deciding which posts you see.
    </span>

    <!-- Linked: one slider, exactly as before the split. Two sliders showing the same thing would
         be noise for the great majority who never want them apart. -->
    <!-- Nearer/Further is a property of the shared scale, not of either slider, so when both are
         shown only the lower one carries the labels. Repeating them between the two rows read as
         two separate controls that happen to sit together, which is the opposite of the point. -->
    <DistanceSliderRow
      :id="idPrefix + 'Inbound'"
      axis="browse"
      :label="split ? 'Posts I see' : ''"
      :aria-label="split ? 'How far away posts I see can be' : 'How far away'"
      perspective="inbound"
      :with-polygon="withPolygon"
      :shared-axis="split"
      :show-scale-labels="!split"
      @persisted="(miles) => emit('persisted', miles)"
    />

    <DistanceSliderRow
      v-if="split"
      :id="idPrefix + 'Outbound'"
      axis="myPosts"
      label="Who sees my posts"
      aria-label="How far away people who see my posts can be"
      perspective="outbound"
      :with-polygon="withPolygon"
      shared-axis
      show-scale-labels
    />

    <div class="link-action small">
      <template v-if="split">
        <span class="text-muted"
          >Set separately. Your posts can travel further than you would.</span
        >
        <b-button
          variant="link"
          class="p-0 ms-1 align-baseline"
          @click="relink"
        >
          Link them again
        </b-button>
      </template>
      <template v-else>
        <span class="text-muted">Also limits who sees your posts.</span>
        <b-button
          variant="link"
          class="p-0 ms-1 align-baseline"
          @click="split = true"
        >
          Set separately
        </b-button>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import DistanceSliderRow from '~/components/DistanceSliderRow.vue'
import { useMe } from '~/composables/useMe'
import { useAuthStore } from '~/stores/auth'
import { DISTANCE_AXES } from '~/constants'

// The "How far away" control. One question asked in two directions - how far away a post may be for
// me to see it, and how far away someone may be and still see my posts - which used to be a single
// setting doing both jobs.
//
// LINKED BY DEFAULT, and linked means "the outbound keys are absent", not "the two hold equal
// values". Every outbound reader (utils.AuthorReachCapWhere in Go, authorMaxDistanceMiles in the
// batch) falls back to browseMaxDistance when they are absent, which is exactly what happened before
// the split. So a member who never touches this sees no change at all.
//
// Note the two are NOT numerically equal while linked, and the copy must not claim they are: most
// members have never set browseMaxDistance (their band default lives in the separate, inbound-only
// browseReachMaxDistance), so their real outbound reach is already unlimited while their inbound is
// their band radius.
//
// Splitting WRITES NOTHING. It only reveals the second slider; the outbound keys appear when, and
// only when, the member drags it. That is what makes "no change unless you move that slider" literal
// rather than approximate, and it means a member who splits, looks, and thinks better of it has
// changed nothing.
const props = defineProps({
  // Ask /town/near for the reach outline too, for the browse map to shade. Browse only.
  withPolygon: { type: Boolean, default: false },
  // The drag instruction is redundant on touch and costs a line, so callers hide it on mobile.
  showDragHint: { type: Boolean, default: true },
  // Distinguishes the input ids when both the browse filter and Feed settings are in one DOM.
  idPrefix: { type: String, default: 'distance' },
})

const emit = defineEmits(['persisted'])

const { me } = useMe()
const authStore = useAuthStore()

// Split when the member holds an outbound choice of their own, or has just asked to set one. The
// local flag is what lets splitting be free: it survives until they either drag the outbound slider
// (which persists a key, so the split then survives a reload) or link them again.
const splitLocal = ref(false)
const split = computed({
  get: () =>
    splitLocal.value ||
    typeof me.value?.settings?.[DISTANCE_AXES.myPosts.minutesKey] === 'number',
  set: (v) => {
    splitLocal.value = v
  },
})

// Give up the separate outbound choice: forget both outbound keys so the readers fall back to
// browseMaxDistance again. Sending null rather than deleting the properties is deliberate - apiv2
// saves settings with JSON_MERGE_PATCH, which deletes a key patched to null, so nothing is left
// behind.
async function relink() {
  const settings = me.value?.settings
  if (settings) {
    settings[DISTANCE_AXES.myPosts.minutesKey] = null
    settings[DISTANCE_AXES.myPosts.milesKey] = null
    await authStore.saveAndGet({ settings })
  }
  splitLocal.value = false
}
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';

.distance-desc {
  margin-bottom: 0.25rem;
}

// Match the Settings Feed slider: the drag instruction is redundant on touch and costs a line,
// so hide it on mobile.
.drag-hint {
  display: none;

  @include media-breakpoint-up(md) {
    display: inline;
  }
}

.link-action {
  margin-top: 0.25rem;
  line-height: 1.3;
}
</style>
