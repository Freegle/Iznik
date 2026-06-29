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
      <!-- The AI concierge status + pause control. Shown whenever the Helper has
           been started for this clearance. -->
      <HelperStatusBar
        v-if="helperBatch"
        :batch="helperBatch"
        @pause="setStatus('paused')"
        @resume="setStatus('active')"
      />

      <!-- Decisions the Helper wants the human to confirm/edit/send. -->
      <div
        v-if="pendingProposals.length"
        class="clearance-manager__proposals"
        data-testid="helper-proposals"
      >
        <h5 class="clearance-manager__proposalhead">
          <v-icon icon="bell" class="me-1" />
          {{ pendingProposals.length }}
          {{ pendingProposals.length === 1 ? 'decision' : 'decisions' }} for you
        </h5>
        <HelperProposalCard
          v-for="p in pendingProposals"
          :key="p.id"
          :proposal="p"
          @resolve="onResolve"
        />
      </div>

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
        :helper-by-user="helperByUser"
        :sent-users="sentUsers"
      />
    </template>
  </div>
</template>

<script setup>
import { computed, watch, onMounted, onUnmounted } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useUserStore } from '~/stores/user'
import { useMe } from '~/composables/useMe'
import NoticeMessage from '~/components/NoticeMessage'
import ClearanceManageItem from '~/components/ClearanceManageItem'
import HelperStatusBar from '~/components/HelperStatusBar'
import HelperProposalCard from '~/components/HelperProposalCard'
import {
  allocatedQuantity,
  distinctInterestedUsers,
} from '~/composables/useClearance'

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

// --- Freegle Helper (AI concierge) overlay ---------------------------------
const helper = computed(() => messageStore.helperById?.(props.id))
const helperBatch = computed(() => helper.value?.batch || null)

const pendingProposals = computed(() =>
  (helper.value?.proposals || []).filter((p) => p.status === 'pending')
)

// userid -> helper replier record (with item_states), for the candidate rows.
const helperByUser = computed(() => {
  const map = {}
  for (const r of helper.value?.repliers || []) {
    map[r.userid] = r
  }
  return map
})

// Set of userids the Helper has messaged, so candidate rows show an "AI" badge.
// sent rows carry replierid; resolve back to userid via the replier records.
const sentUsers = computed(() => {
  const byReplierId = {}
  for (const r of helper.value?.repliers || []) byReplierId[r.id] = r.userid
  const set = new Set()
  for (const s of helper.value?.sent || []) {
    const uid = byReplierId[s.replierid]
    if (uid) set.add(uid)
  }
  return set
})

async function setStatus(status) {
  try {
    await messageStore.helperSetStatus(props.id, status)
  } catch (e) {
    console.error('Failed to change Helper status', e)
  }
}

async function onResolve({ id, decision, text }) {
  try {
    await messageStore.helperResolveProposal(props.id, id, decision, text)
  } catch (e) {
    console.error('Failed to resolve proposal', e)
  }
}

const fullyAllocated = computed(
  () =>
    items.value.filter(
      (it) =>
        it.quantity > 0 && allocatedQuantity(it.interest || []) >= it.quantity
    ).length
)

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
  // Load the Helper state (batch/repliers/proposals/sent). Tolerate absence —
  // the Helper may not have been started for this clearance.
  try {
    await messageStore.fetchHelper(props.id)
  } catch (e) {
    // No Helper state yet; the page renders without the AI overlay.
  }
}

// Poll the Helper state so the page reflects the background loop live — AI activity
// and, importantly, the pause heartbeat (so "Pausing…" flips to "Paused — stopped"
// once the driver has acknowledged). Light: only refetches the helper overlay.
let pollTimer = null
function startPolling() {
  stopPolling()
  pollTimer = setInterval(() => {
    if (canManage.value) {
      messageStore.fetchHelper(props.id).catch(() => {})
    }
  }, 12000)
}
function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

onMounted(() => {
  load()
  startPolling()
})
watch(() => props.id, load)
onUnmounted(stopPolling)

defineExpose({
  message,
  items,
  isClearance,
  canManage,
  peopleInterested,
  fullyAllocated,
  load,
  helperBatch,
  pendingProposals,
  helperByUser,
  sentUsers,
  setStatus,
  onResolve,
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
</style>
