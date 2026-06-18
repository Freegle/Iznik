<template>
  <div class="clearance-item" :data-testid="'clearance-item-' + item.id">
    <div class="clearance-item__head">
      <span class="clearance-item__photo">
        <img
          v-if="thumb"
          :src="thumb"
          alt=""
          loading="lazy"
          @error="brokenImage"
        />
        <v-icon v-else icon="image" class="clearance-item__nophoto" />
      </span>
      <div class="clearance-item__title">
        <span class="clearance-item__ref">#{{ index + 1 }}</span>
        <span class="clearance-item__name">{{ item.name }}</span>
        <b-badge variant="light">{{ item.quantity }} available</b-badge>
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

    <!-- Allocated recipients (Reserved / Collected). -->
    <div v-if="allocatedRows.length" class="clearance-item__group">
      <h6 class="clearance-item__grouphead">Allocated to</h6>
      <ClearanceCandidate
        v-for="row in allocatedRows"
        :key="row.userid"
        :message-id="message.id"
        :bulkitemid="item.id"
        :interest="row"
        :other-allocations="otherAllocationsFor(row.userid)"
      />
    </div>

    <!-- Everyone still in the pool. Once something's been allocated these are
         the fallbacks the offerer can fall back on. -->
    <div v-if="poolRows.length" class="clearance-item__group">
      <h6 class="clearance-item__grouphead">
        {{ allocatedRows.length ? 'Fallback recipients' : 'Interested' }}
      </h6>
      <ClearanceCandidate
        v-for="row in poolRows"
        :key="row.userid"
        :message-id="message.id"
        :bulkitemid="item.id"
        :interest="row"
        :other-allocations="otherAllocationsFor(row.userid)"
      />
    </div>

    <p v-if="!activeRows.length" class="text-muted small mb-0">
      No-one's asked for this yet.
    </p>

    <!-- Declined / withdrawn, tucked away. -->
    <div v-if="inactiveRows.length" class="clearance-item__group">
      <b-button
        variant="link"
        size="sm"
        class="p-0 clearance-item__inactivetoggle"
        data-testid="toggle-inactive"
        @click="showInactive = !showInactive"
      >
        {{ showInactive ? 'Hide' : 'Show' }} {{ inactiveRows.length }}
        declined/withdrawn
      </b-button>
      <template v-if="showInactive">
        <ClearanceCandidate
          v-for="row in inactiveRows"
          :key="row.userid"
          :message-id="message.id"
          :bulkitemid="item.id"
          :interest="row"
        />
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import ClearanceCandidate from '~/components/ClearanceCandidate'
import {
  isAllocatedState,
  isPoolState,
  isInactiveState,
  allocatedQuantity,
} from '~/composables/useClearance'

const props = defineProps({
  // The whole bulk offer (so we can look across items for single-visit hints).
  message: { type: Object, required: true },
  // This catalogue item, including its owner-only `interest` array.
  item: { type: Object, required: true },
  // Zero-based position, for the #N reference shown to the offerer.
  index: { type: Number, default: 0 },
})

const showInactive = ref(false)

const interest = computed(() => props.item.interest || [])

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

const poolRows = computed(() =>
  // Bulk collectors first (fewer collection visits), then those who gave a
  // collection time, then by quantity.
  interest.value
    .filter((i) => isPoolState(i.state))
    .slice()
    .sort(
      (a, b) =>
        (b.cancollect ? 1 : 0) - (a.cancollect ? 1 : 0) ||
        (b.quantity || 0) - (a.quantity || 0)
    )
)

const inactiveRows = computed(() =>
  interest.value.filter((i) => isInactiveState(i.state))
)

const activeRows = computed(() => [...allocatedRows.value, ...poolRows.value])

const allocated = computed(() => allocatedQuantity(interest.value))
const remaining = computed(() =>
  Math.max(0, (props.item.quantity || 0) - allocated.value)
)

const conditionLabel = computed(() =>
  props.item.condition === 'LikeNew' ? 'Like new' : props.item.condition
)

const thumb = computed(() => {
  const a = props.item.attachments && props.item.attachments[0]
  return (a && (a.paththumb || a.path)) || props.item.photourl || null
})

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
  inactiveRows,
  activeRows,
  allocated,
  remaining,
  otherAllocationsFor,
})
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.clearance-item {
  border: 1px solid $color-gray--lighter;
  border-radius: 6px;
  padding: 0.75rem;
  margin-bottom: 0.75rem;
}

.clearance-item__head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
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
