<template>
  <div>
    <div class="d-flex flex-wrap gap-3 mb-3">
      <b-form-group label="Period" class="mb-0">
        <b-form-select
          v-model="days"
          :options="dayOptions"
          style="width: auto"
          @change="fetchStats"
        />
      </b-form-group>
      <b-form-group label="Community" class="mb-0">
        <b-form-select
          v-model="groupid"
          :options="groupOptions"
          style="width: auto"
          data-testid="automod-group-filter"
          @change="fetchStats"
        />
      </b-form-group>
    </div>
    <NoticeMessage v-if="error" variant="danger" class="mb-3">
      {{ error }}
    </NoticeMessage>
    <b-spinner v-if="loading" small />
    <div v-else-if="stats">
      <b-card class="mb-3" header="Automated review totals">
        <b-table-simple small responsive>
          <b-tbody>
            <b-tr>
              <b-td>Posts reviewed</b-td>
              <b-td class="text-end">{{ stats.total }}</b-td>
            </b-tr>
            <b-tr>
              <b-td>Shadow mode (not yet acting)</b-td>
              <b-td class="text-end">{{ stats.shadow?.count || 0 }}</b-td>
            </b-tr>
            <b-tr>
              <b-td>Acting (auto-approving)</b-td>
              <b-td class="text-end">{{ stats.approve?.count || 0 }}</b-td>
            </b-tr>
            <b-tr>
              <b-td>
                Could not run (service unavailable; the post waited for a
                moderator)
              </b-td>
              <b-td class="text-end" data-testid="automod-unavailable">
                {{ stats.unavailable || 0 }}
                <span class="text-muted">{{
                  pct(stats.unavailable || 0, stats.total)
                }}</span>
              </b-td>
            </b-tr>
            <b-tr>
              <b-td>
                Decided late (after the wait had already run out, so the post
                published with no countdown shown)
              </b-td>
              <b-td class="text-end" data-testid="automod-late">
                {{ stats.late || 0 }}
                <span class="text-muted">{{
                  pct(stats.late || 0, stats.total)
                }}</span>
              </b-td>
            </b-tr>
            <b-tr>
              <b-td>Time from content check to decision</b-td>
              <b-td class="text-end" data-testid="automod-latency">
                <span v-if="stats.latency">
                  average {{ duration(stats.latency.avgSeconds) }}, longest
                  {{ duration(stats.latency.maxSeconds) }}
                </span>
                <span v-else class="text-muted">-</span>
              </b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </b-card>
      <b-card class="mb-3" header="By decision">
        <p class="small text-muted mb-2">
          "Moderator disagreed" is a hold a moderator of that community later
          approved, or an approval they later rejected, deleted or pulled back.
          "Neighbour disagreed" is a copy of an approved post that a
          neighbouring community's moderator rejected after it rippled in.
        </p>
        <b-table-simple small responsive>
          <b-thead>
            <b-tr>
              <b-th>Where it ended</b-th>
              <b-th>Verdict</b-th>
              <b-th class="text-end">Posts</b-th>
              <b-th class="text-end">Moderator disagreed</b-th>
              <b-th class="text-end">%</b-th>
              <b-th class="text-end">Neighbour disagreed</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="row in stats.byEnd" :key="row.end + '-' + row.verdict">
              <b-td>{{ row.end }}</b-td>
              <b-td>{{ row.verdict }}</b-td>
              <b-td class="text-end">{{ row.count }}</b-td>
              <b-td class="text-end">{{ row.modDisagreed }}</b-td>
              <b-td class="text-end text-muted">
                {{ endPct(row) }}
              </b-td>
              <b-td class="text-end">{{ row.neighbourDisagreed || 0 }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </b-card>
      <b-card
        v-if="stats.pulledBack !== undefined"
        class="mb-3"
        header="Pulled back from Check"
        data-testid="automod-pulled-back"
      >
        <p class="small text-muted mb-2">
          Posts that published themselves and a moderator then pulled back from
          Check. Approved afterwards means the pull back was a false alarm;
          rejected or deleted afterwards means the review let through a post a
          moderator was right to stop.
        </p>
        <b-table-simple small responsive>
          <b-tbody>
            <b-tr>
              <b-td>Pulled back</b-td>
              <b-td class="text-end fw-bold">{{ stats.pulledBack }}</b-td>
            </b-tr>
            <b-tr>
              <b-td>...then approved (false alarms)</b-td>
              <b-td class="text-end">{{ stats.pulledBackApproved || 0 }}</b-td>
            </b-tr>
            <b-tr>
              <b-td>...then rejected or deleted (real catches)</b-td>
              <b-td class="text-end">{{ stats.pulledBackRejected || 0 }}</b-td>
            </b-tr>
            <b-tr>
              <b-td>...still waiting for a decision</b-td>
              <b-td
                class="text-end"
                data-testid="automod-pulled-back-waiting"
                >{{ pulledBackWaiting }}</b-td
              >
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </b-card>
      <b-card class="mb-3" header="By community">
        <p class="small text-muted mb-2">
          A community that ran in shadow and then in approve mode has a row for
          each. In shadow a moderator decides every post, so disagreement is a
          fair measure. In approve mode only a post somebody opens in Check
          meets a moderator, so its figures are a lower bound and are not to be
          averaged into the shadow ones.
        </p>
        <b-table-simple
          v-if="stats.byGroup?.length"
          small
          responsive
          data-testid="automod-by-group"
        >
          <b-thead>
            <b-tr>
              <b-th>Community</b-th>
              <b-th>Mode</b-th>
              <b-th class="text-end">Decisions</b-th>
              <b-th class="text-end">Approves</b-th>
              <b-th class="text-end">Holds</b-th>
              <b-th class="text-end">Could not run</b-th>
              <b-th class="text-end">Late</b-th>
              <b-th class="text-end">Moderator disagreed</b-th>
              <b-th class="text-end">Neighbour disagreed</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr
              v-for="row in stats.byGroup"
              :key="row.groupid + '-' + (row.mode || '')"
            >
              <b-td>{{ row.nameshort || 'Group ' + row.groupid }}</b-td>
              <b-td>{{ row.mode || '-' }}</b-td>
              <b-td class="text-end">{{ row.decisions }}</b-td>
              <b-td class="text-end">{{ row.approves }}</b-td>
              <b-td class="text-end">{{ row.holds }}</b-td>
              <b-td class="text-end">{{ row.unavailable }}</b-td>
              <b-td class="text-end">{{ row.late }}</b-td>
              <b-td class="text-end">
                {{ row.modDisagreed }}
                <span class="text-muted">{{
                  pct(row.modDisagreed, decidedOf(row))
                }}</span>
              </b-td>
              <b-td class="text-end">{{ row.neighbourDisagreed }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
        <p v-else class="text-muted mb-0">No decisions in this period.</p>
      </b-card>
      <b-card class="mb-3" header="By chart version">
        <p class="small text-muted mb-2">
          A new chart or model shows here as its own row, so a change in the
          disagreement rate can be put down to it rather than averaged away.
          Decisions with no version are ones the service could not run.
        </p>
        <b-table-simple
          v-if="stats.byVersion?.length"
          small
          responsive
          data-testid="automod-by-version"
        >
          <b-thead>
            <b-tr>
              <b-th>Version</b-th>
              <b-th class="text-end">Decisions</b-th>
              <b-th class="text-end">Approves</b-th>
              <b-th class="text-end">Holds</b-th>
              <b-th class="text-end">Could not run</b-th>
              <b-th class="text-end">Late</b-th>
              <b-th class="text-end">Moderator disagreed</b-th>
              <b-th class="text-end">Neighbour disagreed</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="row in stats.byVersion" :key="'v' + row.version">
              <b-td>{{ row.version || '(none)' }}</b-td>
              <b-td class="text-end">{{ row.decisions }}</b-td>
              <b-td class="text-end">{{ row.approves }}</b-td>
              <b-td class="text-end">{{ row.holds }}</b-td>
              <b-td class="text-end">{{ row.unavailable }}</b-td>
              <b-td class="text-end">{{ row.late }}</b-td>
              <b-td class="text-end">
                {{ row.modDisagreed }}
                <span class="text-muted">{{
                  pct(row.modDisagreed, decidedOf(row))
                }}</span>
              </b-td>
              <b-td class="text-end">{{ row.neighbourDisagreed }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
        <p v-else class="text-muted mb-0">No decisions in this period.</p>
      </b-card>
      <b-card class="mb-3" header="By day">
        <p class="small text-muted mb-2">
          The trend. A backlog, an outage or a regression shows as a day unlike
          its neighbours: a jump in "could not run" or "late", or in either
          disagreement count.
        </p>
        <b-table-simple
          v-if="stats.byDay?.length"
          small
          responsive
          data-testid="automod-by-day"
        >
          <b-thead>
            <b-tr>
              <b-th>Day</b-th>
              <b-th class="text-end">Decisions</b-th>
              <b-th class="text-end">Approves</b-th>
              <b-th class="text-end">Holds</b-th>
              <b-th class="text-end">Could not run</b-th>
              <b-th class="text-end">Late</b-th>
              <b-th class="text-end">Moderator disagreed</b-th>
              <b-th class="text-end">Neighbour disagreed</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="row in stats.byDay" :key="row.day">
              <b-td>{{ row.day }}</b-td>
              <b-td class="text-end">{{ row.decisions }}</b-td>
              <b-td class="text-end">{{ row.approves }}</b-td>
              <b-td class="text-end">{{ row.holds }}</b-td>
              <b-td class="text-end">{{ row.unavailable }}</b-td>
              <b-td class="text-end">{{ row.late }}</b-td>
              <b-td class="text-end">
                {{ row.modDisagreed }}
                <span class="text-muted">{{
                  pct(row.modDisagreed, decidedOf(row))
                }}</span>
              </b-td>
              <b-td class="text-end">{{ row.neighbourDisagreed }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
        <p v-else class="text-muted mb-0">No decisions in this period.</p>
      </b-card>
      <b-card header="Steps flagged as wrong by moderators">
        <template v-if="stats.feedback?.length">
          <p class="small text-muted mb-2">
            Posts is how many decisions had the step marked wrong. Marks counts
            each moderator's mark, so a post several moderators marked adds more
            than one.
          </p>
          <b-table-simple small responsive data-testid="automod-feedback">
            <b-thead>
              <b-tr>
                <b-th>Step</b-th>
                <b-th class="text-end">Posts</b-th>
                <b-th class="text-end">Marks</b-th>
              </b-tr>
            </b-thead>
            <b-tbody>
              <b-tr v-for="row in stats.feedback" :key="row.node">
                <b-td>{{ row.node }}</b-td>
                <b-td class="text-end">{{ row.posts ?? '-' }}</b-td>
                <b-td class="text-end">{{ row.count }}</b-td>
              </b-tr>
            </b-tbody>
          </b-table-simple>
        </template>
        <p v-else class="text-muted mb-0">No feedback in this period.</p>
      </b-card>
    </div>
  </div>
</template>
<script setup>
// SysAdmin panel showing how well the automated review flowchart's verdicts
// match what moderators actually decide - mounted next to the moderation
// stats panel it complements.
import { ref, computed, onMounted } from 'vue'
import { useMessageStore } from '@/stores/message'

const messageStore = useMessageStore()

const days = ref(30)
const dayOptions = [
  { value: 7, text: 'Last 7 days' },
  { value: 30, text: 'Last 30 days' },
  { value: 90, text: 'Last 90 days' },
]

// 0 = every community. The choices come from the last unfiltered fetch, so narrowing to
// one community does not empty the list of the others.
const groupid = ref(0)
const knownGroups = ref([])
// A community that ran in two modes has a row for each, but is one choice.
const groupOptions = computed(() => {
  const seen = new Set()
  const options = []
  for (const g of knownGroups.value) {
    if (seen.has(g.groupid)) continue
    seen.add(g.groupid)
    options.push({
      value: g.groupid,
      text: g.nameshort || 'Group ' + g.groupid,
    })
  }
  return [{ value: 0, text: 'All communities' }, ...options]
})

const stats = ref(null)
const loading = ref(false)
const error = ref(null)

// Of the posts pulled back from Check, the ones nobody has approved, rejected or deleted yet.
const pulledBackWaiting = computed(() =>
  Math.max(
    0,
    (stats.value?.pulledBack || 0) -
      (stats.value?.pulledBackApproved || 0) -
      (stats.value?.pulledBackRejected || 0)
  )
)

function pct(num, denom) {
  if (!denom) return '-'
  return ((100 * num) / denom).toFixed(1) + '%'
}

// A disagreement is only counted against a decision about the post. A review that could not
// run, or a community that has every post checked, is not one, so the rate is over the rows
// that were decisions (decided), not every row written; an API that does not say gets every
// row.
function decidedOf(row) {
  return row.decided ?? row.decisions
}

const UNDECIDED_ENDS = ['UNAVAILABLE', 'GROUP_MODERATES_ALL']

function endPct(row) {
  return UNDECIDED_ENDS.includes(row.end)
    ? '-'
    : pct(row.modDisagreed, row.count)
}

function duration(secs) {
  if (!secs || secs <= 0) return '-'
  if (secs < 60) return Math.round(secs) + 's'
  if (secs < 3600) return Math.round(secs / 60) + ' min'
  return (secs / 3600).toFixed(1) + ' h'
}

async function fetchStats() {
  loading.value = true
  error.value = null
  try {
    const params = { days: days.value }
    if (groupid.value) {
      params.groupid = groupid.value
    }
    stats.value = await messageStore.fetchAutomodAgreement(params)
    if (!groupid.value && stats.value?.byGroup) {
      knownGroups.value = stats.value.byGroup
    }
  } catch (e) {
    error.value = e?.message || 'request failed'
  } finally {
    loading.value = false
  }
}

onMounted(fetchStats)

defineExpose({
  days,
  dayOptions,
  groupid,
  groupOptions,
  stats,
  loading,
  error,
  fetchStats,
  pct,
  decidedOf,
  endPct,
  pulledBackWaiting,
  duration,
})
</script>
