<template>
  <div>
    <div v-if="!store.active">
      <!-- After a close, what was held keeps draining through the usual checks
           and the send queue empties; show it until that is done. -->
      <ModSupportLockdownRelease
        v-if="showDrain"
        class="mb-4"
        :stats="store.stats"
      />
      <ModSupportLockdownPress @pressed="refreshAll" />
      <!-- Only once there has been a lockdown: before the first one, the
           press form's own notice box is the only one that means anything. -->
      <ModSupportLockdownNotice
        v-if="store.history?.length"
        class="mt-4"
        :notice="store.notice"
        :active="false"
        :busy="busy"
        @save="onNotice"
      />
      <ModSupportLockdownHistory class="mt-4" :history="store.history" />
    </div>

    <div v-else>
      <NoticeMessage
        variant="danger"
        class="mb-3"
        data-testid="lockdown-active-banner"
      >
        <strong>Lockdown on.</strong>
        <span data-testid="lockdown-status-line">
          Pressed {{ timeago(store.startedat, true) }} by
          {{ store.startedbyname }}{{ store.reason ? ': ' + store.reason : '' }}
        </span>
      </NoticeMessage>

      <b-tabs v-model="subTab" content-class="mt-3">
        <b-tab>
          <template #title>
            <span data-testid="lockdown-subtab-controls">Controls</span>
          </template>

          <ModSupportLockdownTakingEffect :stats="store.stats" />

          <ModSupportLockdownSurfaces
            class="mt-3"
            :surfaces="store.surfaces"
            :busy="busy"
            @set-surface="onSetSurface"
          />

          <div class="mt-3 d-flex flex-wrap gap-2">
            <b-button
              variant="primary"
              :disabled="busy || !anyHeld"
              data-testid="lockdown-liftall-button"
              @click="showLiftAllModal = true"
            >
              Lift everything
            </b-button>
          </div>

          <ModSupportLockdownStats class="mt-4" :stats="store.stats" />

          <ModSupportLockdownRelease class="mt-4" :stats="store.stats" />

          <ModSupportLockdownNotice
            class="mt-4"
            :notice="store.notice"
            :active="true"
            :busy="busy"
            @save="onNotice"
          />

          <h4 class="mt-4">Close the lockdown</h4>
          <p class="small text-muted">
            Closing lifts anything still held, ends the lockdown and removes the
            member notice.
          </p>
          <b-form-group label="Closing note (goes on the history)">
            <b-form-textarea
              v-model="endNote"
              rows="2"
              data-testid="lockdown-close-note"
            />
          </b-form-group>
          <b-button
            variant="secondary"
            :disabled="busy"
            data-testid="lockdown-close-button"
            @click="showCloseModal = true"
          >
            Close
          </b-button>

          <ModSupportLockdownHistory class="mt-4" :history="store.history" />
        </b-tab>

        <b-tab lazy>
          <template #title>
            <span data-testid="lockdown-subtab-held">What is held</span>
          </template>
          <ModSupportLockdownHeld />
        </b-tab>
      </b-tabs>

      <ConfirmModal
        v-if="showLiftAllModal"
        ref="liftAllModal"
        title="Lift everything?"
        @confirm="onLiftAll"
        @hidden="showLiftAllModal = false"
      >
        <div data-testid="lockdown-liftall-confirm">
          <p>
            Everything held is released straight away, through the usual checks.
            The lockdown stays open until you close it, so any area can be held
            again.
          </p>
        </div>
      </ConfirmModal>

      <ConfirmModal
        v-if="showCloseModal"
        ref="closeModal"
        title="Close the lockdown?"
        @confirm="onClose"
        @hidden="showCloseModal = false"
      >
        <div data-testid="lockdown-close-confirm">
          <p>
            Anything still held is released straight away, through the usual
            checks, and the member notice is removed.
          </p>
        </div>
      </ConfirmModal>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useLockdownStore } from '~/stores/lockdown'
import { timeago } from '~/composables/useTimeFormat'

// plans/active/2026-09-27-lockdown-switch.md sections 11.6 and 11.11 - the
// Support Lockdown tab. Not active: the press form with what will happen,
// the member notice (for "Things are back to normal" after close) and the
// history. Active: two subtabs. Controls has "Taking effect", one row per
// area with Lift or Hold again, Lift everything, the counts held so far, the
// notice and Close. "What is held" browses held items, fetched only when it
// is opened. Every write goes through store.patch(), which refetches the
// state itself, and then refreshes stats and history so the page reflects
// the change at once.
//
// Stats poll cadence (11.6): every 5 seconds while any batch loop hasn't
// caught up with the latest change, or while anything is still draining,
// then every 60 seconds. A tick runs every 5 seconds regardless; whether it actually
// refetches depends on how long it's been since the last refresh.
const store = useLockdownStore()

const POLL_TICK_MS = 5000
const POLL_IDLE_MS = 60000

let refreshTimer = null
let lastRefreshAt = 0

const subTab = ref(0)
const busy = ref(false)

const allCaughtUp = computed(() => {
  if (!store.active) return true
  const acks = store.stats?.acks
  if (!acks || !acks.length) return false
  return acks.every((a) => a.caughtup)
})

const DAY_MS = 24 * 60 * 60 * 1000

// Something the last lockdown held is still waiting, or email is still queued.
const draining = computed(() => {
  const release = store.stats?.release ?? {}
  const held = Object.values(release).some((r) => (r?.held ?? 0) > 0)
  return held || (store.stats?.queue?.email ?? 0) > 0
})

// Once closed, the release view stays up while anything is draining, and for
// a day after the close so the finished result can be seen.
const closedRecently = computed(() => {
  const last = store.history?.[0]
  if (!last || last.active) return false
  const at = new Date(last.created).getTime()
  return !Number.isNaN(at) && Date.now() - at < DAY_MS
})

const showDrain = computed(
  () => !!store.stats && (draining.value || closedRecently.value)
)

const anyHeld = computed(() =>
  Object.values(store.surfaces ?? {}).some(Boolean)
)

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
  const due = allCaughtUp.value && !draining.value ? POLL_IDLE_MS : POLL_TICK_MS
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
  await store.fetchHistory()
  // Stats are for the latest lockdown, so they are worth fetching after a
  // close too - that is how the drain is watched.
  if (store.active || store.history?.length) {
    await store.fetchStats()
  }
}

async function patchAndRefresh(data) {
  busy.value = true
  try {
    await store.patch(data)
    await refreshStats()
  } finally {
    busy.value = false
  }
}

function onSetSurface(key, held) {
  return patchAndRefresh({ action: 'surfaces', surfaces: { [key]: held } })
}

function onNotice(text) {
  return patchAndRefresh({ action: 'notice', notice: text })
}

const liftAllModal = ref(null)
// ConfirmModal is v-if-gated (house pattern - see ModSupportLockdownPress.vue's
// showConfirmModal): useOurModal() defaults autoShow to true, so an
// always-mounted ConfirmModal pops open the instant the active-state page
// mounts instead of waiting for its button.
const showLiftAllModal = ref(false)
function onLiftAll() {
  return patchAndRefresh({ action: 'liftall' })
}

const closeModal = ref(null)
const showCloseModal = ref(false)
const endNote = ref('')
async function onClose() {
  await patchAndRefresh({ action: 'close', endnote: endNote.value.trim() })
  endNote.value = ''
}

defineExpose({
  onSetSurface,
  onNotice,
  onLiftAll,
  onClose,
})
</script>
