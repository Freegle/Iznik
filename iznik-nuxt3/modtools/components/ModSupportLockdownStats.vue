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
          <td>{{ stats?.triage?.[kind]?.spam ?? 0 }}</td>
          <td>{{ stats?.triage?.[kind]?.risky ?? 0 }}</td>
          <td>{{ stats?.triage?.[kind]?.low ?? 0 }}</td>
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
          :key="risk + '-' + sample.kind + '-' + sample.id"
          class="mb-1"
        >
          <b-badge variant="secondary">{{ sample.kind }}</b-badge>
          <span class="ms-2">{{ (sample.text || '').slice(0, 200) }}</span>
          <span class="text-muted ms-2">member #{{ sample.senderid }}</span>
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
          @click="confirmMarkSpamModal?.show()"
        >
          Mark spam set ({{ totalHeld('spam') }})
        </b-button>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <template v-for="kind in kinds" :key="kind">
          <template v-if="(stats?.triage?.[kind]?.[risk] ?? 0) > 0">
            <b-button
              size="sm"
              variant="outline-success"
              :data-testid="
                'lockdown-releaseclass-' + kind + '-' + risk + '-release'
              "
              @click="askReleaseClass(kind, risk, 'release')"
            >
              Release {{ kindLabel(kind) }} {{ risk }} ({{
                stats.triage[kind][risk]
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
                stats.triage[kind][risk]
              }})
            </b-button>
          </template>
        </template>
      </div>
    </div>

    <h4>Clusters</h4>
    <ul data-testid="lockdown-clusters">
      <li v-for="(c, i) in stats?.clusters ?? []" :key="i">
        "{{ c.line }}" - {{ c.count }}
      </li>
      <li v-if="!(stats?.clusters ?? []).length" class="text-muted">None.</li>
    </ul>

    <p data-testid="lockdown-accounts-created">
      Accounts created since the press: {{ stats?.accountscreated ?? 0 }}
    </p>

    <h4>Not sent, and moderator actions</h4>
    <div data-testid="lockdown-counters">
      <p>
        Email held by type:
        <span
          v-for="(count, type) in stats?.counters?.email ?? {}"
          :key="type"
          class="me-2"
        >
          {{ type }}: {{ count }}
        </span>
        <span v-if="!Object.keys(stats?.counters?.email ?? {}).length">
          none
        </span>
      </p>
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
      ref="confirmMarkSpamModal"
      title="Mark the spam set?"
      message="<p>Every spam-classed hold's sender goes into spam_users and is
        rejected. Do this once the spam samples above look right.</p>"
      @confirm="confirmMarkSpam"
    />

    <ConfirmModal
      ref="confirmReleaseClassModal"
      :title="releaseClassTitle"
      message="<p>Look at the samples above before confirming - this acts on
        the whole class at once.</p>"
      @confirm="confirmReleaseClass"
    />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'

// plans/active/2026-09-27-lockdown-switch.md sections 10.6, 10.9 step 2-3,
// 10.10, 11.2 (GET /modtools/lockdown/stats, PATCH markspam/releaseclass).
// The exact JSON field names are this agent's own proposal - the Go handler
// isn't built yet (see .claude-agent-status/ui-lockdown.md and the message
// sent to go-lockdown2) - so every read here is defensive (optional
// chaining, falls back to 0/empty) rather than assuming the shape holds.
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

function totalHeld(risk) {
  return kinds.reduce((n, k) => n + (props.stats?.triage?.[k]?.[risk] ?? 0), 0)
}

const confirmMarkSpamModal = ref(null)
function confirmMarkSpam() {
  emit('markspam')
}

const pendingReleaseClass = ref(null)
const confirmReleaseClassModal = ref(null)

const releaseClassTitle = computed(() => {
  const p = pendingReleaseClass.value
  if (!p) return 'Release or reject this class?'
  return `${p.decision === 'release' ? 'Release' : 'Reject'} ${kindLabel(
    p.kind
  )} ${p.risk}?`
})

function askReleaseClass(kind, risk, decision) {
  pendingReleaseClass.value = { kind, risk, decision }
  confirmReleaseClassModal.value?.show?.()
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
