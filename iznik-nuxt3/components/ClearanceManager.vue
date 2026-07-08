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
      <!-- The AI concierge mode selector — Paused / Approve / Automatic.
           Shown whenever the Helper has been started for this clearance. -->
      <div
        v-if="helperBatch"
        class="clearance-manager__mode mb-3"
        data-testid="helper-mode-selector"
      >
        <div class="btn-group" role="group" aria-label="Helper mode">
          <b-button
            size="sm"
            :variant="helperMode === 'paused' ? 'warning' : 'outline-secondary'"
            data-testid="helper-mode-paused"
            @click="setMode('paused')"
          >
            Paused
          </b-button>
          <b-button
            size="sm"
            :variant="helperMode === 'approve' ? 'primary' : 'outline-secondary'"
            data-testid="helper-mode-approve"
            @click="setMode('approve')"
          >
            Approve
          </b-button>
          <b-button
            size="sm"
            :variant="helperMode === 'automatic' ? 'success' : 'outline-secondary'"
            data-testid="helper-mode-automatic"
            @click="setMode('automatic')"
          >
            Automatic
          </b-button>
        </div>
        <p v-if="helperMode === 'approve'" class="small text-muted mt-1 mb-0">
          Approve: every message is held for you to edit and approve before it's sent.
        </p>
      </div>

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
        <div
          class="d-flex align-items-start justify-content-between gap-2 flex-wrap"
        >
          <h2 class="clearance-manager__title">{{ message.subject }}</h2>
          <b-button
            variant="outline-primary"
            size="sm"
            data-testid="clearance-edit"
            @click="goEdit"
          >
            <v-icon icon="pen" /> Edit offer
          </b-button>
        </div>
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

      <!-- Private access instructions — only the offerer edits and sees these
           on this page. They are delivered to a collector automatically once
           the offerer allocates (Reserves) them an item. -->
      <div class="clearance-manager__access mt-3 mb-3" data-testid="clearance-access-section">
        <label class="form-label fw-semibold" for="clearance-access-edit">
          Access instructions
        </label>
        <p class="small text-muted mb-1">
          Shared with someone only once you allocate them an item.
        </p>
        <b-form-textarea
          id="clearance-access-edit"
          v-model="localAccessInstructions"
          data-testid="clearance-access-edit"
          rows="3"
          placeholder="e.g. 12 High St, side gate, buzz flat 3"
        />
        <b-button
          class="mt-2 clearance-save-btn"
          variant="success"
          size="sm"
          @click="saveAccessInstructions"
        >
          Save
        </b-button>
      </div>

      <!-- A secret link so an external owner (e.g. someone giving items away
           through other channels too) can keep availability up to date without
           a Freegle login. Only the item list + counts are exposed - no replies. -->
      <div
        class="clearance-manager__sharelink mb-3"
        data-testid="clearance-sharelink-section"
      >
        <b-button
          v-if="!shareLink"
          variant="outline-primary"
          size="sm"
          :disabled="shareLoading"
          data-testid="clearance-sharelink-get"
          @click="getShareLink"
        >
          <b-spinner v-if="shareLoading" small class="me-1" />
          <v-icon v-else icon="link" /> Get an update link to share
        </b-button>
        <div v-else data-testid="clearance-sharelink-ready">
          <label class="small text-muted d-block mb-1">
            Send this to whoever manages the items - they can update what's left
            (available/taken and how many) without logging in:
          </label>
          <div class="d-flex gap-2 align-items-center">
            <b-form-input
              :model-value="shareLink"
              readonly
              class="flex-grow-1"
              data-testid="clearance-sharelink-input"
              @focus="selectAll"
            />
            <b-button
              variant="outline-secondary"
              data-testid="clearance-sharelink-copy"
              @click="copyShareLink"
            >
              {{ shareCopied ? 'Copied!' : 'Copy' }}
            </b-button>
          </div>
        </div>
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
import { computed, ref, watch, onMounted, onUnmounted } from 'vue'
import { useRouter, useRuntimeConfig, useNuxtApp } from '#imports'
import { useMessageStore } from '~/stores/message'
import { useUserStore } from '~/stores/user'
import { useMe } from '~/composables/useMe'
import NoticeMessage from '~/components/NoticeMessage'
import ClearanceManageItem from '~/components/ClearanceManageItem'
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
const router = useRouter()
const runtimeConfig = useRuntimeConfig()
const { $api } = useNuxtApp()

// Secret update-link sharing (for an external item-owner).
const shareLink = ref('')
const shareLoading = ref(false)
const shareCopied = ref(false)

async function getShareLink() {
  shareLoading.value = true
  try {
    const res = await $api.message.bulkEditLink(props.id)
    if (res?.token) {
      const base =
        runtimeConfig.public?.USER_SITE || 'https://www.ilovefreegle.org'
      shareLink.value = base + '/clearance/update/' + res.token
    }
  } catch (e) {
    console.error('Failed to get update link', e)
  } finally {
    shareLoading.value = false
  }
}

async function copyShareLink() {
  try {
    await navigator.clipboard.writeText(shareLink.value)
    shareCopied.value = true
    setTimeout(() => {
      shareCopied.value = false
    }, 2000)
  } catch (e) {
    // Clipboard blocked (e.g. insecure context) - the field is selectable to copy manually.
  }
}

function selectAll(e) {
  e.target.select()
}

// Open the create/edit form pre-loaded with this clearance for editing.
function goEdit() {
  router.push('/give/clearance?id=' + props.id)
}

const message = computed(() => messageStore.byId(props.id))

// Local copy of the access instructions so the textarea is editable without
// mutating the store directly. Synced whenever the message (re)loads.
const localAccessInstructions = ref('')
watch(
  () => message.value?.accessinstructions,
  (v) => {
    localAccessInstructions.value = v || ''
  },
  { immediate: true }
)

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

// Derive the three-way mode from the batch state:
//   paused   -> status === 'paused'
//   approve  -> status === 'active', automode === 'approve'
//   automatic-> status === 'active', automode === 'automatic' (or unset)
const helperMode = computed(() => {
  if (!helperBatch.value) return null
  if (helperBatch.value.status === 'paused') return 'paused'
  return helperBatch.value.automode === 'approve' ? 'approve' : 'automatic'
})
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

// Three-way mode setter. Delegates to the store's helperSetStatus, which posts
// SetStatus (with the optional automode) and re-fetches helper state.
async function setMode(mode) {
  try {
    if (mode === 'paused') {
      await messageStore.helperSetStatus(props.id, 'paused')
    } else {
      // 'approve' or 'automatic' run with status active.
      await messageStore.helperSetStatus(props.id, 'active', mode)
    }
  } catch (e) {
    console.error('Failed to change Helper mode', e)
  }
}

// Thin shim kept for any callers that still pass a bare status string.
async function setStatus(status) {
  await setMode(status === 'paused' ? 'paused' : 'automatic')
}

async function onResolve({ id, decision, text }) {
  try {
    await messageStore.helperResolveProposal(props.id, id, decision, text)
  } catch (e) {
    console.error('Failed to resolve proposal', e)
  }
}

async function saveAccessInstructions() {
  try {
    await messageStore.patch({ id: props.id, accessinstructions: localAccessInstructions.value })
  } catch (e) {
    console.error('Failed to save access instructions', e)
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
  helperMode,
  pendingProposals,
  helperByUser,
  sentUsers,
  setMode,
  setStatus,
  onResolve,
  localAccessInstructions,
  saveAccessInstructions,
  shareLink,
  shareLoading,
  shareCopied,
  getShareLink,
  copyShareLink,
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

/* Use the app's standard success green ($color-success), not Bootstrap's
   default success (which renders a different teal-green). */
.clearance-save-btn {
  background-color: $color-success;
  border-color: $color-success;

  &:hover,
  &:focus,
  &:active {
    background-color: $color-success-hover;
    border-color: $color-success-hover;
  }
}
</style>
