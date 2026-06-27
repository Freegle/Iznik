<template>
  <div class="clearance-manager" data-testid="clearance-manager">
    <div
      v-if="!message"
      class="text-center my-5"
      data-testid="clearance-loading"
    >
      <b-spinner />
      <p class="text-muted mt-2">Loading your clearance…</p>
    </div>

    <NoticeMessage v-else-if="!isClearance" variant="warning">
      This post isn't a clearance with multiple items, so there's nothing to
      manage here.
    </NoticeMessage>

    <NoticeMessage v-else-if="!canManage" variant="warning">
      You can only manage clearances that you posted.
    </NoticeMessage>

    <template v-else>
      <div class="clearance-manager__head">
        <h2 class="clearance-manager__title">{{ message.subject }}</h2>
        <p class="clearance-manager__totals" data-testid="clearance-totals">
          {{ items.length }} items ·
          <strong>{{ peopleInterested }}</strong>
          {{ peopleInterested === 1 ? 'person' : 'people' }} interested ·
          <strong>{{ fullyAllocated }}/{{ items.length }}</strong>
          items fully allocated
        </p>

        <details class="clearance-manager__legend small text-muted">
          <summary>What the labels mean</summary>
          <ul class="mb-0 mt-1">
            <li>
              <strong>Wants it</strong> — they've asked; still in the pool.
            </li>
            <li>
              <strong>Allocated</strong> — you've promised it; we send them the
              collection details automatically.
            </li>
            <li><strong>Collected</strong> — they've picked it up.</li>
            <li>
              <strong>Fallback recipients</strong> — backups if an allocation
              falls through.
            </li>
          </ul>
        </details>
      </div>

      <ClearanceManageItem
        v-for="(item, idx) in items"
        :key="item.id"
        :message="message"
        :item="item"
        :index="idx"
      />

      <div
        v-if="allCollected && !message.outcomes?.length"
        class="clearance-manager__close-cta"
        data-testid="clearance-close-cta"
      >
        <v-icon icon="check-circle" class="text-success me-2" />
        <strong>All items collected!</strong>
        <b-button
          variant="success"
          class="ms-3"
          @click="showOutcomeModal = true"
        >
          Close this offer
        </b-button>
        <OutcomeModal
          v-if="showOutcomeModal"
          :id="id"
          type="Taken"
          @hidden="showOutcomeModal = false"
        />
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch, onMounted, defineAsyncComponent } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useUserStore } from '~/stores/user'
import { useMe } from '~/composables/useMe'
import NoticeMessage from '~/components/NoticeMessage'
import ClearanceManageItem from '~/components/ClearanceManageItem'
import {
  allocatedQuantity,
  collectedQuantity,
  distinctInterestedUsers,
} from '~/composables/useClearance'

const OutcomeModal = defineAsyncComponent(() => import('./OutcomeModal'))

const props = defineProps({
  // The bulk offer's message id.
  id: { type: Number, required: true },
})

const messageStore = useMessageStore()
const userStore = useUserStore()
const { myid } = useMe()

const message = computed(() => messageStore.byId(props.id))

const items = computed(() =>
  (message.value?.bulkitems || [])
    .slice()
    .sort((a, b) => (a.position || 0) - (b.position || 0))
)

const isClearance = computed(
  () => (message.value?.bulkcount || items.value.length) > 0
)

// Only the offerer manages a clearance. (Mods have their own modtools view.)
const canManage = computed(
  () => !!message.value && message.value.fromuser === myid.value
)

const peopleInterested = computed(() => distinctInterestedUsers(items.value))

const fullyAllocated = computed(
  () =>
    items.value.filter(
      (it) =>
        it.quantity > 0 && allocatedQuantity(it.interest || []) >= it.quantity
    ).length
)

const showOutcomeModal = ref(false)

// Every item with stock has its full quantity collected — the clearance is done.
// Items with quantity 0 are skipped (placeholders), not treated as uncollected.
const allCollected = computed(() => {
  const stocked = items.value.filter((it) => it.quantity > 0)
  if (!stocked.length) return false
  return stocked.every(
    (it) => collectedQuantity(it.interest || []) >= it.quantity
  )
})

// Load the full message (with the owner-only interest arrays), then the
// display names / reputation of everyone who's expressed interest so the
// candidate rows can show who they are.
async function load() {
  await messageStore.fetch(props.id, true)
  // Fetch everyone who's expressed interest — including withdrawn/rejected, so
  // their names still show in the declined/withdrawn section (and if the offerer
  // restores a rejected candidate).
  const ids = new Set()
  for (const item of message.value?.bulkitems || []) {
    for (const i of item.interest || []) {
      ids.add(i.userid)
    }
  }
  if (ids.size) {
    await userStore.fetchMultiple([...ids])
  }
}

onMounted(load)
watch(() => props.id, load)

defineExpose({
  message,
  items,
  isClearance,
  canManage,
  peopleInterested,
  fullyAllocated,
  allCollected,
  showOutcomeModal,
  load,
})
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.clearance-manager__title {
  font-size: 1.4rem;
  font-weight: 700;
}

.clearance-manager__totals {
  color: $color-gray--dark;
  margin-bottom: 0.25rem;
}

.clearance-manager__legend {
  margin-bottom: 1rem;

  summary {
    cursor: pointer;
  }
}

/* Hardcoded hex (not SCSS vars) so an undefined var can't break page load. */
.clearance-manager__close-cta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 1.5rem;
  padding: 1rem;
  background: #e6f4ea;
  border: 1px solid #198754;
  border-radius: 4px;
}
</style>
