<template>
  <div>
    <h4>Triage</h4>
    <table class="table table-sm w-auto" data-testid="lockdown-triage">
      <thead>
        <tr>
          <th>Kind</th>
          <th class="text-danger">Spam</th>
          <th class="text-warning">Risky</th>
          <th class="text-muted">Low</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="kind in kinds" :key="kind">
          <td>{{ kindLabel(kind) }}</td>
          <td>{{ triageCount(kind, 'spam') }}</td>
          <td>{{ triageCount(kind, 'risky') }}</td>
          <td>{{ triageCount(kind, 'low') }}</td>
        </tr>
      </tbody>
    </table>

    <!-- Samples: spam and risky only - never low (section 10.6: "Support
         sees counts and samples of the spam and risky sets. Never of the
         low set."). Release/reject a class sits right beside its samples,
         one button per kind that actually has anything held in that risk. -->
    <div v-for="risk in ['spam', 'risky']" :key="risk" class="mb-3">
      <h4 class="text-capitalize">{{ risk }} samples</h4>
      <div :data-testid="'lockdown-samples-' + risk">
        <p
          v-for="sample in stats?.samples?.[risk] ?? []"
          :key="risk + '-' + sample.kind + '-' + sample.refid"
          class="mb-1"
        >
          <b-badge variant="secondary">{{ sample.kind }}</b-badge>
          <span class="ms-2">{{ (sample.text || '').slice(0, 200) }}</span>
          <span class="text-muted ms-2">member #{{ sample.userid }}</span>
        </p>
        <p v-if="!(stats?.samples?.[risk] ?? []).length" class="text-muted">
          None held.
        </p>
      </div>

      <div
        v-if="risk === 'spam'"
        class="mb-2"
        data-testid="lockdown-markspam-button"
      >
        <b-button
          variant="danger"
          size="sm"
          :disabled="!totalHeld('spam')"
          @click="showMarkSpamModal = true"
        >
          Mark spam set ({{ totalHeld('spam') }})
        </b-button>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <template v-for="kind in kinds" :key="kind">
          <template v-if="triageCount(kind, risk) > 0">
            <b-button
              size="sm"
              variant="outline-success"
              :data-testid="
                'lockdown-releaseclass-' + kind + '-' + risk + '-release'
              "
              @click="askReleaseClass(kind, risk, 'release')"
            >
              Release {{ kindLabel(kind) }} {{ risk }} ({{
                triageCount(kind, risk)
              }})
            </b-button>
            <b-button
              size="sm"
              variant="outline-danger"
              :data-testid="
                'lockdown-releaseclass-' + kind + '-' + risk + '-reject'
              "
              @click="askReleaseClass(kind, risk, 'reject')"
            >
              Reject {{ kindLabel(kind) }} {{ risk }} ({{
                triageCount(kind, risk)
              }})
            </b-button>
          </template>
        </template>
      </div>
    </div>

    <h4>Clusters</h4>
    <ul data-testid="lockdown-clusters">
      <li v-for="(c, i) in stats?.clusters ?? []" :key="i">
        "{{ c.text }}" - {{ c.count }}
      </li>
      <li v-if="!(stats?.clusters ?? []).length" class="text-muted">None.</li>
    </ul>

    <p data-testid="lockdown-accounts-created">
      Accounts created since the press: {{ stats?.accountscreated ?? 0 }}
    </p>

    <h4>Not sent, and moderator actions</h4>
    <div data-testid="lockdown-counters">
      <div data-testid="lockdown-email-paused">
        <p>
          Email paused<span v-if="stats?.pressedat">
            since {{ timeago(stats.pressedat) }}</span
          >. Digests and notifications will be generated when email is resumed,
          without anything removed in the meantime.
        </p>
        <p>
          In the send queue: {{ emailQueueTotal }}
          <span
            v-for="(count, type) in emailQueueBreakdown"
            :key="type"
            class="me-2"
          >
            {{ type }}: {{ count }}
          </span>
        </p>
        <p>
          Removed from the queue: {{ emailRemovedTotal }}
          <span
            v-for="(count, type) in emailRemovedBreakdown"
            :key="type"
            class="me-2"
          >
            {{ type }}: {{ count }}
          </span>
        </p>
        <p>Mail runs deferred: {{ mailRunsDeferredTotal }}</p>
      </div>
      <p>Push held: {{ stats?.counters?.push ?? 0 }}</p>
      <p>Export refused: {{ stats?.counters?.export ?? 0 }}</p>
      <p>
        Refused (moderator actions blocked):
        <span
          v-for="r in stats?.counters?.refused ?? []"
          :key="r.userid"
          class="me-2"
        >
          {{ r.name }}: {{ r.count }}
        </span>
        <span v-if="!(stats?.counters?.refused ?? []).length">none</span>
      </p>
      <p>
        Approved while mods held:
        <span
          v-for="a in stats?.counters?.approved ?? []"
          :key="a.userid"
          class="me-2"
        >
          {{ a.name }}: {{ a.count }}
        </span>
        <span v-if="!(stats?.counters?.approved ?? []).length">none</span>
      </p>
    </div>

    <h4>Outcomes</h4>
    <table class="table table-sm w-auto" data-testid="lockdown-outcomes">
      <tbody>
        <tr>
          <td>Released, no person read it</td>
          <td>{{ stats?.outcomes?.released ?? 0 }}</td>
        </tr>
        <tr>
          <td>Sent to review</td>
          <td>{{ stats?.outcomes?.review ?? 0 }}</td>
        </tr>
        <tr>
          <td>Reviewed and approved</td>
          <td>{{ stats?.outcomes?.approved ?? 0 }}</td>
        </tr>
        <tr>
          <td>Reviewed and rejected</td>
          <td>{{ stats?.outcomes?.rejected ?? 0 }}</td>
        </tr>
        <tr>
          <td>Sender marked, dropped</td>
          <td>{{ stats?.outcomes?.spam_marked ?? 0 }}</td>
        </tr>
      </tbody>
    </table>

    <ConfirmModal
      v-if="showMarkSpamModal"
      ref="confirmMarkSpamModal"
      title="Mark the spam set?"
      message="<p>Every spam-classed hold's sender goes into spam_users and is
        rejected. Do this once the spam samples above look right.</p>"
      @confirm="confirmMarkSpam"
      @hidden="showMarkSpamModal = false"
    />

    <ConfirmModal
      v-if="showReleaseClassModal"
      ref="confirmReleaseClassModal"
      :title="releaseClassTitle"
      message="<p>Look at the samples above before confirming - this acts on
        the whole class at once.</p>"
      @confirm="confirmReleaseClass"
      @hidden="showReleaseClassModal = false"
    />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { timeago } from '~/composables/useTimeFormat'

// plans/active/2026-09-27-lockdown-switch.md sections 10.6, 10.9 step 2-3,
// 10.10, 11.2, 11.7/11.8 (GET /modtools/lockdown/stats, PATCH
// markspam/releaseclass). Shapes confirmed against the real Go handler
// (iznik-server-go/lockdown/handlers.go): `triage` is a flat array of
// {kind,risk,count} (every hold, any outcome) and `samples` entries use
// `refid`/`userid`, not `id`/`senderid`; `clusters` entries use `text`, not
// `line`. `pressedat`, `counters` (push/export/refused/approved) and
// `waiting.email` (with its `queued`/`removed`/`deferred` sub-objects) match
// too. `counters.email` is a real field but no code path currently writes a
// bare "email:*" counter - every mail counter that exists lands in
// `waiting.email` or `leaked` instead - so it is deliberately not shown here;
// it would always read empty. Every read below is still defensive (optional
// chaining, falls back to 0/empty) for when stats itself hasn't loaded yet.
const props = defineProps({
  stats: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['markspam', 'releaseclass'])

const kinds = ['chat', 'post', 'chitchat']

const kindLabels = { chat: 'Chat', post: 'Posts', chitchat: 'ChitChat' }
function kindLabel(kind) {
  return kindLabels[kind] || kind
}

// `triage` arrives as a flat array of {kind,risk,count} rows, one per
// kind/risk combination - build a kind->risk->count lookup once rather than
// scanning the array on every read.
const triageMap = computed(() => {
  const map = {}
  for (const t of props.stats?.triage ?? []) {
    if (!map[t.kind]) map[t.kind] = {}
    map[t.kind][t.risk] = t.count
  }
  return map
})

function triageCount(kind, risk) {
  return triageMap.value[kind]?.[risk] ?? 0
}

function totalHeld(risk) {
  return kinds.reduce((n, k) => n + triageCount(k, risk), 0)
}

// plans/active/2026-09-27-lockdown-switch.md section 11.7 (rewritten):
// member email is not generated while held, so there is no big "waiting"
// pile - just the small send queue the batch already had before the loops
// saw the press, exposed as stats.waiting.email.queued
// (iznik-server-go/lockdown/handlers.go).
const emailQueueBreakdown = computed(
  () => props.stats?.waiting?.email?.queued ?? {}
)

const emailQueueTotal = computed(() =>
  Object.values(emailQueueBreakdown.value).reduce((n, v) => n + (v ?? 0), 0)
)

// Section 11.8: lockdown:filter-spool removes queued mail whose `about`
// names content Support has since removed, before email resumes - exposed as
// stats.waiting.email.removed.
const emailRemovedBreakdown = computed(
  () => props.stats?.waiting?.email?.removed ?? {}
)

const emailRemovedTotal = computed(() =>
  Object.values(emailRemovedBreakdown.value).reduce((n, v) => n + (v ?? 0), 0)
)

// Section 11.7: every mail loop checks the switch before each unit of work
// and, while email is held, stops without advancing its watermark - exposed
// as stats.waiting.email.deferred, keyed by loop.
const mailRunsDeferredTotal = computed(() =>
  Object.values(props.stats?.waiting?.email?.deferred ?? {}).reduce(
    (n, v) => n + (v ?? 0),
    0
  )
)

// Both ConfirmModals below are v-if-gated (house pattern - see
// ModSupportLockdownPress.vue's showConfirmModal): useOurModal() defaults
// autoShow to true, so an always-mounted ConfirmModal pops open the instant
// this component mounts instead of waiting for its button.
const confirmMarkSpamModal = ref(null)
const showMarkSpamModal = ref(false)
function confirmMarkSpam() {
  emit('markspam')
}

const pendingReleaseClass = ref(null)
const confirmReleaseClassModal = ref(null)
const showReleaseClassModal = ref(false)

const releaseClassTitle = computed(() => {
  const p = pendingReleaseClass.value
  if (!p) return 'Release or reject this class?'
  return `${p.decision === 'release' ? 'Release' : 'Reject'} ${kindLabel(
    p.kind
  )} ${p.risk}?`
})

function askReleaseClass(kind, risk, decision) {
  pendingReleaseClass.value = { kind, risk, decision }
  showReleaseClassModal.value = true
}

function confirmReleaseClass() {
  if (pendingReleaseClass.value) {
    emit('releaseclass', { ...pendingReleaseClass.value })
  }
  pendingReleaseClass.value = null
}

defineExpose({
  confirmMarkSpam,
  askReleaseClass,
  confirmReleaseClass,
})
</script>
