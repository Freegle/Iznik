<template>
  <div class="clearance-item" :data-testid="'clearance-item-' + item.id">
    <div class="clearance-item__head">
      <button
        type="button"
        class="clearance-item__photo"
        :class="{ 'clearance-item__photo--zoom': thumb }"
        :title="thumb ? 'Click to enlarge' : ''"
        @click.stop.prevent="enlarge"
      >
        <img
          v-if="thumb"
          :src="thumb"
          alt=""
          loading="lazy"
          @error="brokenImage"
        />
        <v-icon v-else icon="image" class="clearance-item__nophoto" />
      </button>
      <div class="clearance-item__title">
        <span class="clearance-item__ref">#{{ index + 1 }}</span>
        <span
          class="clearance-item__name"
          :class="{ 'clearance-item__name--gone': !isAvailable }"
          >{{ item.name }}</span
        >
        <b-badge v-if="!isAvailable" variant="danger"
          >No longer available</b-badge
        >
        <b-badge v-else variant="light">{{ item.quantity }} available</b-badge>
        <b-badge
          v-if="item.condition && item.condition !== 'Unknown'"
          variant="info"
        >
          {{ conditionLabel }}
        </b-badge>
      </div>
    </div>

    <!-- Allocation progress: how much of this item is spoken for. -->
    <div class="clearance-item__alloc">
      <b-progress :max="item.quantity || 1" class="clearance-item__bar">
        <b-progress-bar :value="allocated" variant="success" />
      </b-progress>
      <span class="clearance-item__allocnum small">
        <b-badge v-if="remaining <= 0" variant="success"
          >Fully allocated</b-badge
        >
        <template v-else>
          Allocated {{ allocated }} of {{ item.quantity }} ·
          <strong>{{ remaining }} left</strong>
        </template>
      </span>
      <span class="clearance-item__intnum small text-muted">
        {{ item.interestcount || 0 }} interested
      </span>
    </div>

    <!-- Freegle Helper's per-item summary, grouped by FSM state. -->
    <HelperItemSummary :item-states="itemStates" />

    <!-- Allocated recipients (Reserved / Collected). -->
    <div v-if="allocatedRows.length" class="clearance-item__group">
      <h6 class="clearance-item__grouphead">Allocated to</h6>
      <ClearanceCandidate
        v-for="row in allocatedRows"
        :key="row.userid"
        v-bind="candProps(row)"
      />
    </div>

    <!-- Needs you: the AI couldn't handle these chats. Always shown (never
         collapsed) — these are the ones to deal with. -->
    <div
      v-if="needsYouRows.length"
      class="clearance-item__group clearance-item__group--needsyou"
    >
      <h6 class="clearance-item__grouphead">
        <v-icon icon="circle-exclamation" class="me-1" />Needs you ({{
          needsYouRows.length
        }})
      </h6>
      <ClearanceCandidate
        v-for="row in needsYouRows"
        :key="row.userid"
        v-bind="candProps(row)"
      />
    </div>

    <!-- Everyone ready for a decision (Helper QUALIFIED, or no Helper running).
         Once something's been allocated these are the fallbacks. Sorted by the
         Helper's priority score when available. -->
    <div v-if="decisionRows.length" class="clearance-item__group">
      <h6 class="clearance-item__grouphead">
        {{ allocatedRows.length ? 'Fallback recipients' : 'Ready to decide' }}
      </h6>
      <ClearanceCandidate
        v-for="row in decisionRows"
        :key="row.userid"
        v-bind="candProps(row)"
      />
    </div>

    <!-- Outreach: people the Helper is still gathering from. Listed but collapsed
         until they've replied enough to be a real candidate. -->
    <div v-if="outreachRows.length" class="clearance-item__group">
      <b-button
        variant="link"
        size="sm"
        class="p-0 clearance-item__inactivetoggle"
        data-testid="toggle-outreach"
        @click="showOutreach = !showOutreach"
      >
        {{ showOutreach ? 'Hide' : 'Show' }} {{ outreachRows.length }}
        being contacted by Helper
      </b-button>
      <template v-if="showOutreach">
        <ClearanceCandidate
          v-for="row in outreachRows"
          :key="row.userid"
          v-bind="candProps(row)"
        />
      </template>
    </div>

    <p v-if="!activeRows.length" class="text-muted small mb-0">
      No-one's asked for this yet.
    </p>

    <!-- Excluded / withdrawn, tucked away. -->
    <div v-if="inactiveRows.length" class="clearance-item__group">
      <b-button
        variant="link"
        size="sm"
        class="p-0 clearance-item__inactivetoggle"
        data-testid="toggle-inactive"
        @click="showInactive = !showInactive"
      >
        {{ showInactive ? 'Hide' : 'Show' }} {{ inactiveRows.length }}
        excluded/withdrawn
      </b-button>
      <template v-if="showInactive">
        <ClearanceCandidate
          v-for="row in inactiveRows"
          :key="row.userid"
          v-bind="candProps(row)"
        />
      </template>
    </div>

    <!-- Full-screen photo zoom, opened from the item photo (offerer view). -->
    <MessagePhotosModal
      v-if="showPhotos"
      :id="message.id"
      :initial-index="photoIndex"
      @hidden="showPhotos = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, defineAsyncComponent } from 'vue'
import ClearanceCandidate from '~/components/ClearanceCandidate'
import HelperItemSummary from '~/components/HelperItemSummary'
import {
  isAllocatedState,
  isPoolState,
  isInactiveState,
  allocatedQuantity,
  isOutreachState,
  isNeedsYouState,
} from '~/composables/useClearance'

const MessagePhotosModal = defineAsyncComponent(() =>
  import('~/components/MessagePhotosModal')
)

const props = defineProps({
  // The whole bulk offer (so we can look across items for single-visit hints).
  message: { type: Object, required: true },
  // This catalogue item, including its owner-only `interest` array.
  item: { type: Object, required: true },
  // Zero-based position, for the #N reference shown to the offerer.
  index: { type: Number, default: 0 },
  // userid -> Freegle Helper replier record (with item_states). Empty when the
  // Helper isn't running for this clearance.
  helperByUser: { type: Object, default: () => ({}) },
  // Set of userids the Helper has messaged (for the AI badge).
  sentUsers: { type: Object, default: () => new Set() },
})

const showInactive = ref(false)
const showOutreach = ref(false)

const interest = computed(() => props.item.interest || [])

// --- Helper (AI) overlay lookups for this item -----------------------------
// The per-item FSM state for a user: prefer the item-specific helper state, else
// fall back to the replier's overall state.
function helperItemStateFor(userid) {
  const r = props.helperByUser[userid]
  if (!r) return null
  const s = (r.item_states || []).find((x) => x.bulkitemid === props.item.id)
  return s || null
}
function helperStateFor(userid) {
  const s = helperItemStateFor(userid)
  if (s) return s.state
  return props.helperByUser[userid]?.state || null
}
function scoreFor(userid) {
  const s = helperItemStateFor(userid)
  return s ? s.score : null
}
function scoreBreakdownFor(userid) {
  const s = helperItemStateFor(userid)
  return s ? s.score_breakdown : null
}
function aiSentFor(userid) {
  return props.sentUsers?.has ? props.sentUsers.has(userid) : false
}
// Why the AI handed this conversation over (shown on a "Needs you" row).
function noteFor(userid) {
  return props.helperByUser[userid]?.escalation_reason || null
}

// All the per-candidate props in one place, so each row binds cleanly.
function candProps(row) {
  return {
    messageId: props.message.id,
    bulkitemid: props.item.id,
    interest: row,
    otherAllocations: otherAllocationsFor(row.userid),
    helperState: helperStateFor(row.userid),
    score: scoreFor(row.userid),
    scoreBreakdown: scoreBreakdownFor(row.userid),
    aiSent: aiSentFor(row.userid),
    note: noteFor(row.userid),
  }
}

// All helper item_states for THIS item, for the per-item FSM summary.
const itemStates = computed(() => {
  const out = []
  for (const r of Object.values(props.helperByUser)) {
    const s = (r.item_states || []).find((x) => x.bulkitemid === props.item.id)
    if (s) out.push(s)
  }
  return out
})

// Is this pool candidate one the Helper is still gathering from (outreach)?
function isOutreachCandidate(userid) {
  const st = helperStateFor(userid)
  return st ? isOutreachState(st) : false
}
// Did the AI escalate this candidate's chat to the human?
function isNeedsYouCandidate(userid) {
  const st = helperStateFor(userid)
  return st ? isNeedsYouState(st) : false
}

const allocatedRows = computed(() =>
  // Reserved before Collected, so "still to collect" sits at the top.
  interest.value
    .filter((i) => isAllocatedState(i.state))
    .slice()
    .sort(
      (a, b) =>
        (a.state === 'Reserved' ? -1 : 1) - (b.state === 'Reserved' ? -1 : 1)
    )
)

// Everyone still in the interest pool (not allocated, not declined), sorted by the
// Helper's priority score first (the prioritisation it reasoned out), then bulk
// collectors, those who gave a collection time, then quantity.
const poolRows = computed(() =>
  interest.value
    .filter((i) => isPoolState(i.state))
    .slice()
    .sort((a, b) => {
      const sa = scoreFor(a.userid)
      const sb = scoreFor(b.userid)
      if (sa != null && sb != null && sa !== sb) return sb - sa
      if (sa != null && sb == null) return -1
      if (sa == null && sb != null) return 1
      return (
        (b.cancollect ? 1 : 0) - (a.cancollect ? 1 : 0) ||
        (b.quantity || 0) - (a.quantity || 0)
      )
    })
)

// Pool split: escalations the AI couldn't handle (surfaced) vs ready-to-decide vs
// still being gathered (outreach — collapsed until they reply).
const needsYouRows = computed(() =>
  poolRows.value.filter((i) => isNeedsYouCandidate(i.userid))
)
const decisionRows = computed(() =>
  poolRows.value.filter(
    (i) => !isOutreachCandidate(i.userid) && !isNeedsYouCandidate(i.userid)
  )
)
const outreachRows = computed(() =>
  poolRows.value.filter((i) => isOutreachCandidate(i.userid))
)

const inactiveRows = computed(() =>
  interest.value.filter((i) => isInactiveState(i.state))
)

const activeRows = computed(() => [...allocatedRows.value, ...poolRows.value])

const allocated = computed(() => allocatedQuantity(interest.value))
// The owner (or an external owner via the secret update link) can mark an item
// as no longer available; then there's nothing left to allocate.
const isAvailable = computed(() => props.item.available !== false)
const remaining = computed(() =>
  isAvailable.value
    ? Math.max(0, (props.item.quantity || 0) - allocated.value)
    : 0
)

const conditionLabel = computed(() =>
  props.item.condition === 'LikeNew' ? 'Like new' : props.item.condition
)

const thumb = computed(() => {
  const a = props.item.attachments && props.item.attachments[0]
  return (a && (a.paththumb || a.path)) || props.item.photourl || null
})

// Full-screen photo zoom, opened from the item photo.
const showPhotos = ref(false)
const photoIndex = ref(0)
function enlarge() {
  const att = props.item.attachments && props.item.attachments[0]
  if (!att) return
  const all = props.message.attachments || []
  const idx = all.findIndex((a) => a.id === att.id)
  photoIndex.value = idx >= 0 ? idx : 0
  showPhotos.value = true
}

// Names of OTHER items in this clearance the given user is already allocated.
// Surfacing this lets the offerer give one person several items in one trip.
function otherAllocationsFor(userid) {
  const names = []
  for (const other of props.message.bulkitems || []) {
    if (other.id === props.item.id) continue
    const theirs = (other.interest || []).find((i) => i.userid === userid)
    if (theirs && isAllocatedState(theirs.state)) names.push(other.name)
  }
  return names
}

function brokenImage(event) {
  event.target.src = '/placeholder.jpg'
}

defineExpose({
  allocatedRows,
  poolRows,
  decisionRows,
  needsYouRows,
  outreachRows,
  inactiveRows,
  activeRows,
  allocated,
  remaining,
  isAvailable,
  otherAllocationsFor,
  itemStates,
  helperStateFor,
  scoreFor,
  scoreBreakdownFor,
  noteFor,
  aiSentFor,
  candProps,
  showOutreach,
})
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.clearance-item {
  border: 1px solid $color-gray--light;
  border-radius: 8px;
  padding: 0.85rem 1rem;
  margin-bottom: 0.85rem;
  background-color: $color-white;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.clearance-item__photo--zoom {
  cursor: zoom-in;
}

/* The photo is a button now — strip default button chrome. */
.clearance-item__photo {
  padding: 0;
  border: 0;
}

.clearance-item__head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

/* "Needs you" is the attention group — give it a clear left border. */
.clearance-item__group--needsyou {
  border-left: 3px solid #dc3545;
  padding-left: 0.5rem;
  margin: 0.4rem 0;
}
.clearance-item__group--needsyou .clearance-item__grouphead {
  color: #b02a37;
}

.clearance-item__photo {
  flex: 0 0 44px;
  width: 44px;
  height: 44px;
  border-radius: 5px;
  overflow: hidden;
  background-color: $color-gray--lighter;
  display: flex;
  align-items: center;
  justify-content: center;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.clearance-item__nophoto {
  color: $color-gray--normal;
}

.clearance-item__title {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.clearance-item__ref {
  color: $color-gray--normal;
  font-size: 0.85em;
}

.clearance-item__name {
  font-weight: 600;
}

.clearance-item__name--gone {
  text-decoration: line-through;
  color: $color-gray--normal;
}

.clearance-item__alloc {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0.5rem 0;
}

.clearance-item__bar {
  flex: 1 1 8rem;
  min-width: 6rem;
}

.clearance-item__grouphead {
  font-size: 0.9rem;
  font-weight: 700;
  margin: 0.5rem 0 0.1rem;
  color: $color-gray--dark;
}

.clearance-item__inactivetoggle {
  margin-top: 0.25rem;
}
</style>
