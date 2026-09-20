<template>
  <div>
    <NoticeMessage variant="info" class="mb-3">
      <p class="mb-0">
        <strong>Why email is running late.</strong> Two different things delay
        mail and they need opposite responses. A provider can refuse our mail
        outright, in which case we pause generating email for everyone there
        rather than pile up mail that can't be delivered. Or mail can simply be
        queued, waiting its turn, because we send to that provider at a
        deliberately limited rate - nothing has gone wrong, but the member is
        still waiting. Both are below.
      </p>
    </NoticeMessage>

    <div v-if="loading" class="text-center p-4">
      <v-icon icon="sync" class="fa-spin" /> Loading...
    </div>

    <NoticeMessage v-else-if="error" variant="danger">
      {{ error }}
    </NoticeMessage>

    <template v-else>
      <NoticeMessage
        v-if="!suppressions.length && !queues.length && !members.length"
        variant="success"
        class="mb-3"
      >
        Nothing is waiting and nothing is being deferred. Every provider is
        accepting our mail as fast as we're sending it.
      </NoticeMessage>

      <!-- The queue comes first because it answers the question people
           actually arrive with - "is mail to this member late?" - whoever's
           fault it is. A suppression is the answer to a narrower question. -->
      <template v-if="queues.length">
        <h3 class="mb-2">
          In the sending queue
          <b-badge :variant="worstQueueVariant">{{
            totalQueued.toLocaleString()
          }}</b-badge>
        </h3>
        <p class="text-muted small mb-2">
          One row per recipient domain, worst first.
          <strong>Waiting</strong> is mail nothing has refused - it's queued
          behind the rate we send to that provider at.
          <strong>Refused</strong> is mail they've turned away. Depth on its own
          doesn't tell you much: a big queue draining fast is fine, a small one
          that isn't draining is not.
        </p>
        <b-table-simple responsive striped small class="mb-4">
          <b-thead>
            <b-tr>
              <b-th>Domain</b-th>
              <b-th class="text-end">Waiting</b-th>
              <b-th class="text-end">Refused</b-th>
              <b-th>Oldest</b-th>
              <b-th class="text-end">Sending</b-th>
              <b-th>Clears in</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="q in queues" :key="'q-' + q.domain">
              <b-td>
                <code>{{ q.domain }}</code>
              </b-td>
              <b-td class="text-end">{{
                (q.waiting || 0).toLocaleString()
              }}</b-td>
              <b-td class="text-end">{{
                (q.deferred || 0).toLocaleString()
              }}</b-td>
              <b-td>
                <span v-if="q.oldest" :title="q.oldest">{{
                  timeago(q.oldest)
                }}</span>
                <span v-else class="text-muted">-</span>
              </b-td>
              <b-td class="text-end">
                <span v-if="q.deliveredperhour"
                  >{{ q.deliveredperhour.toLocaleString() }}/hr</span
                >
                <span v-else class="text-muted">-</span>
              </b-td>
              <b-td :class="clearsClass(q)">{{ clearsIn(q) }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </template>

      <h3 class="mb-2">
        Providers refusing our mail
        <b-badge v-if="suppressions.length" variant="danger">{{
          suppressions.length
        }}</b-badge>
      </h3>

      <p v-if="!suppressions.length" class="text-muted">
        Nobody is refusing us. Anything in the queue above is waiting on the
        rate we send at, not on a provider turning us away.
      </p>

      <template v-else>
        <p class="text-muted small mb-2">
          One row per recipient domain, worst backlog first. Individual full
          mailboxes are not listed - those are that member's inbox, not a
          provider refusing us.
        </p>
        <b-table-simple responsive striped class="mb-4">
          <b-thead>
            <b-tr>
              <b-th>Provider</b-th>
              <b-th>Domain</b-th>
              <b-th>Delayed since</b-th>
              <b-th class="text-end">Queued</b-th>
              <b-th>Why</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="s in suppressions" :key="'sup-' + s.id">
              <b-td>{{ s.provider || 'Unknown' }}</b-td>
              <b-td>
                <code>{{ s.value }}</code>
              </b-td>
              <b-td>
                <span :title="s.deferredsince">{{
                  dateshort(s.deferredsince)
                }}</span>
              </b-td>
              <b-td class="text-end">{{ s.messagecount }}</b-td>
              <b-td class="small text-muted">{{ s.reason }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </template>

      <h3 class="mb-2 mt-4">Members we've stopped emailing for now</h3>

      <p class="text-muted small mb-3">
        Nothing here is a punishment or a setting anyone chose. When mail to
        someone can't be delivered, we stop generating more of it rather than
        pile up email that can't arrive, and we send a catch-up once it clears.
        The count is how many emails we didn't generate while that was true -
        not a number of emails sitting somewhere waiting. An immediate digest is
        generated per matching post, so an active member on several communities
        reaches thousands within days.
      </p>

      <h4 class="mb-2 h5">
        Waiting on a provider
        <b-badge v-if="waitingOnProvider.length" variant="info">{{
          waitingOnProvider.length
        }}</b-badge>
      </h4>

      <p class="text-muted small mb-2">
        Our sending reputation with their provider. Ours to fix, and nothing the
        member can do.
      </p>

      <p v-if="!waitingOnProvider.length" class="text-muted">
        Nobody. Mail queued behind our own sending rate has already been
        generated and is waiting to go out, so it's in the queue above rather
        than here.
      </p>

      <ModSupportMailHeldTable
        v-else
        :members="waitingOnProvider"
        :limit="memberLimit"
      />

      <h4 class="mb-2 mt-4 h5">
        Their own mailbox
        <b-badge v-if="ownMailbox.length" variant="secondary">{{
          ownMailbox.length
        }}</b-badge>
      </h4>

      <p class="text-muted small mb-2">
        Their inbox is full, or their address doesn't resolve. That's theirs to
        fix, not our sending reputation, which is why they aren't in the table
        of providers refusing us above. Nothing here means anything is wrong
        with our mail.
      </p>

      <p v-if="!ownMailbox.length" class="text-muted">Nobody.</p>

      <ModSupportMailHeldTable
        v-else
        :members="ownMailbox"
        :limit="memberLimit"
      />
    </template>
  </div>
</template>
<script setup>
import { computed, onMounted } from 'vue'
import { useEmailTrackingStore } from '~/modtools/stores/emailtracking'
import { dateshort, timeago } from '~/composables/useTimeFormat'

const store = useEmailTrackingStore()

const loading = computed(() => store.deferralsLoading)
const error = computed(() => store.deferralsError)
const suppressions = computed(() => store.deferralSuppressions)
const members = computed(() => store.deferralMembers)
const memberLimit = computed(() => store.deferralMemberLimit)
const queues = computed(() => store.deferralQueues)

// Two different problems, so two tables. A member whose own inbox is full is
// not waiting on anything we can fix, and listing them together is what let
// this page say "every provider is accepting our mail" directly above 194
// people it described as having mail held.
const waitingOnProvider = computed(() =>
  members.value.filter((m) => !m.permailbox)
)
const ownMailbox = computed(() => members.value.filter((m) => m.permailbox))

const totalQueued = computed(() =>
  queues.value.reduce((n, q) => n + (q.waiting || 0) + (q.deferred || 0), 0)
)

// Colour the headline on the worst row, not on the total. A hundred thousand
// messages spread over domains that are all draining is a normal busy evening;
// a thousand that aren't moving is the problem.
const worstQueueVariant = computed(() => {
  const stuck = queues.value.some((q) => q.waiting > 0 && !q.deliveredperhour)

  return stuck ? 'danger' : 'warning'
})

// Depth divided by drain rate. Stated in the units the reader thinks in, and
// refusing to guess when there is nothing to divide by: "not draining" is a
// far more useful answer than a made-up number, and it is the row that wants
// acting on.
function clearsIn(q) {
  const waiting = q.waiting || 0

  if (!waiting) {
    return '-'
  }

  const rate = q.deliveredperhour || 0

  if (!rate) {
    return 'not draining'
  }

  const hours = waiting / rate

  if (hours < 1) {
    return `${Math.max(1, Math.round(hours * 60))} min`
  }

  return `${hours.toFixed(1)} hours`
}

function clearsClass(q) {
  if (q.waiting > 0 && !q.deliveredperhour) {
    return 'text-danger fw-bold'
  }

  return q.waiting / (q.deliveredperhour || 1) > 4 ? 'text-warning' : ''
}

onMounted(() => {
  store.fetchDeferrals()
})
</script>
