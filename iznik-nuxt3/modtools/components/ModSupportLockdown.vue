<template>
  <div>
    <ModSupportLockdownPress v-if="!store.active" @pressed="refreshAll" />

    <div v-else>
      <NoticeMessage
        variant="danger"
        class="mb-3"
        data-testid="lockdown-active-banner"
      >
        <strong>Lockdown on.</strong>
        <span data-testid="lockdown-status-line">
          Pressed {{ timeago(store.startedat) }} by {{ store.startedbyname
          }}{{ store.reason ? ': ' + store.reason : '' }}
        </span>
      </NoticeMessage>

      <ModSupportLockdownSurfaces
        :surfaces="store.surfaces"
        :held-counts="store.stats?.held ?? {}"
        @toggle-surface="onToggleSurface"
        @set-chat-mode="onSetChatMode"
      />

      <b-form-group label="Member notice">
        <b-form-select
          :model-value="store.notice"
          :options="noticeOptions"
          data-testid="lockdown-notice-select"
          @update:model-value="onNotice"
        />
      </b-form-group>

      <b-form-group
        label="Incident phrases (one per line, saved as you leave the box)"
      >
        <b-form-textarea
          :model-value="phrasesText"
          rows="3"
          data-testid="lockdown-phrases"
          @update:model-value="onPhrasesInput"
          @change="onPhrasesSave"
        />
      </b-form-group>

      <ModSupportLockdownStats
        :stats="store.stats"
        @markspam="onMarkSpam"
        @releaseclass="onReleaseClass"
      />

      <ModSupportLockdownTakingEffect :stats="store.stats" />

      <b-button
        variant="warning"
        class="mt-3"
        data-testid="lockdown-liftall-button"
        @click="liftAllModal?.show?.()"
      >
        Lift everything
      </b-button>

      <b-form-group label="Closing note (goes on the history row)" class="mt-3">
        <b-form-textarea
          v-model="endNote"
          rows="2"
          data-testid="lockdown-close-note"
        />
      </b-form-group>
      <b-button
        variant="secondary"
        data-testid="lockdown-close-button"
        @click="closeModal?.show?.()"
      >
        Close
      </b-button>

      <ModSupportLockdownHistory :history="store.history" />

      <ConfirmModal
        ref="liftAllModal"
        title="Lift everything?"
        @confirm="onLiftAll"
      >
        <div data-testid="lockdown-liftall-confirm">
          <p>
            Lifting releases held messages at a paced rate, and moderators will
            see the risky ones in their queues.
          </p>
        </div>
      </ConfirmModal>

      <ConfirmModal
        ref="closeModal"
        title="Close the lockdown?"
        @confirm="onClose"
      >
        <div data-testid="lockdown-close-confirm">
          <p>Closing ends the incident and clears the incident phrases.</p>
        </div>
      </ConfirmModal>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useLockdownStore } from '~/stores/lockdown'
import { timeago } from '~/composables/useTimeFormat'

// plans/active/2026-09-27-lockdown-switch.md sections 10.9, 10.10, 11.2, 11.6
// - the Support Lockdown tab's orchestrator. Not active: just the press
// form. Active: status line, the lift-order surface switches, notice choice
// (including "Things are back to normal" once closed), incident phrases,
// live stats, a "Taking effect" list, "Lift everything" and "Close" with an
// end note, and history. Every write goes through store.patch(), which
// refetches the moderator state itself (stores/lockdown.js) - so the page
// updates for the presser immediately, without waiting for any poll - and
// this component additionally refreshes stats/history right after every
// patch, so the "Taking effect" list reflects the change at once too.
//
// Stats poll cadence (11.6): every 5 seconds while any batch loop hasn't
// caught up with the latest change, then every 60 seconds once they all
// have. A tick runs every 5 seconds regardless; whether it actually
// refetches depends on how long it's been since the last refresh.
const store = useLockdownStore()

const POLL_TICK_MS = 5000
const POLL_IDLE_MS = 60000

let refreshTimer = null
let lastRefreshAt = 0

const allCaughtUp = computed(() => {
  if (!store.active) return true
  const acks = store.stats?.acks
  if (!acks || !acks.length) return false
  return acks.every((a) => a.caughtup)
})

onMounted(async () => {
  await refreshAll()
  refreshTimer = setInterval(tick, POLL_TICK_MS)
})

onUnmounted(() => {
  if (refreshTimer) {
    clearInterval(refreshTimer)
    refreshTimer = null
  }
})

async function tick() {
  const due = allCaughtUp.value ? POLL_IDLE_MS : POLL_TICK_MS
  if (Date.now() - lastRefreshAt >= due) {
    await refreshStats()
  }
}

async function refreshAll() {
  await store.fetchMod()
  await refreshStats()
}

async function refreshStats() {
  lastRefreshAt = Date.now()
  await store.fetchStats()
  await store.fetchHistory()
}

// Every action that changes lockdown state re-fetches stats/history right
// away, so the "Taking effect" list doesn't wait for the next poll tick.
async function patchAndRefresh(data) {
  await store.patch(data)
  await refreshStats()
}

const noticeOptions = [
  { value: null, text: 'None' },
  { value: 'delay', text: 'Delay: "Freegle is running slowly today..."' },
  {
    value: 'security',
    text: 'Security: "We\'re dealing with a spam attack..."',
  },
  { value: 'normal', text: 'Things are back to normal' },
]

async function onNotice(value) {
  await patchAndRefresh({ action: 'notice', notice: value })
}

// Phrases are typed as free text, one per line, and saved on blur/change
// (b-form-textarea's @change) rather than on every keystroke - a phrase list
// is read by the batch on its next run (11.1), so there's no reason to spam
// the API mid-sentence.
const phrasesText = computed(() => (store.phrases ?? []).join('\n'))
const pendingPhrases = ref(null)

function onPhrasesInput(value) {
  pendingPhrases.value = value
}

async function onPhrasesSave() {
  const raw = pendingPhrases.value ?? phrasesText.value
  const phrases = raw
    .split('\n')
    .map((p) => p.trim().toLowerCase())
    .filter(Boolean)
  await patchAndRefresh({ action: 'phrases', phrases })
}

async function onToggleSurface(key, value) {
  await patchAndRefresh({ action: 'surfaces', surfaces: { [key]: value } })
}

async function onSetChatMode(mode) {
  await patchAndRefresh({ action: 'surfaces', surfaces: {}, chat_mode: mode })
}

async function onMarkSpam() {
  await patchAndRefresh({ action: 'markspam' })
}

async function onReleaseClass({ kind, risk, decision }) {
  await patchAndRefresh({ action: 'releaseclass', kind, risk, decision })
}

const liftAllModal = ref(null)
async function onLiftAll() {
  await patchAndRefresh({ action: 'liftall' })
}

const closeModal = ref(null)
const endNote = ref('')
async function onClose() {
  await patchAndRefresh({ action: 'close', endnote: endNote.value.trim() })
}

defineExpose({
  onToggleSurface,
  onSetChatMode,
  onNotice,
  onPhrasesInput,
  onPhrasesSave,
  onMarkSpam,
  onReleaseClass,
  onLiftAll,
  onClose,
})
</script>
