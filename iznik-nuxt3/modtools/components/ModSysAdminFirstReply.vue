<template>
  <div class="firstreply-effectiveness">
    <p class="text-muted">
      44% of rippled posts get no reply at all. Three separate levers attack
      that, and this shows whether each is earning its keep - not just whether
      it is running. Each is switchable on its own, so a lever that does nothing
      here should be turned off rather than left to add mail.
    </p>

    <ModEmailDateFilter
      :loading="loading"
      fetch-label="Fetch"
      default-preset="30days"
      @fetch="onFetch"
    />

    <NoticeMessage v-if="error" variant="danger" class="mb-3">
      {{ error }}
    </NoticeMessage>

    <div v-if="loading" class="text-center py-4">
      <b-spinner small class="me-2" />
      Loading first-reply effectiveness...
    </div>

    <template v-else-if="stats">
      <!-- 1. Passthrough --------------------------------------------------->
      <h3 class="ms-2 mt-2">Passthrough</h3>
      <p class="text-muted small ms-2">
        First replies delivered straight away instead of being held until the
        ripple reached the replier. The value of each one is the wait it avoided,
        so that is measured per reply: for that replier, at that location, when
        <em>would</em> the reach have got to them?
      </p>
      <b-table-simple hover responsive small class="mb-4">
        <b-tbody>
          <b-tr>
            <b-td>Let through, in the app</b-td>
            <b-td class="fw-bold">{{ stats.passthrough.web }}</b-td>
          </b-tr>
          <b-tr>
            <b-td>Let through, by email or TrashNothing</b-td>
            <b-td class="fw-bold">{{ stats.passthrough.email }}</b-td>
          </b-tr>
          <b-tr>
            <b-td>How much earlier the poster heard, on average</b-td>
            <b-td class="fw-bold">
              {{ hours(stats.passthrough.avghoursearlier) }}
            </b-td>
          </b-tr>
          <b-tr>
            <b-td>The biggest single saving</b-td>
            <b-td>{{ hours(stats.passthrough.maxhoursearlier) }}</b-td>
          </b-tr>
          <b-tr>
            <b-td>
              Of those, replies that would have arrived within a day anyway
            </b-td>
            <b-td>
              {{ stats.passthrough.sameday }}
              ({{ rate(stats.passthrough.sameday, stats.passthrough.sized) }})
            </b-td>
          </b-tr>
          <b-tr>
            <b-td>Replies still held, and released later</b-td>
            <b-td>{{ stats.passthrough.heldreleased }}</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>
      <p v-if="unsizedPassthroughs > 0" class="text-muted small ms-2">
        {{ unsizedPassthroughs }} let through could not be sized - the post's
        reach schedule could not say which step would have covered the replier.
        Those are left out of the averages rather than counted as no saving.
      </p>
      <NoticeMessage
        v-if="!passthroughUsed"
        variant="info"
        class="mb-4 ms-2 me-2"
      >
        Nothing has been let through in this window. Either the lever is off, or
        <code>rippling_reach.max_polygon</code> has not been populated yet -
        without it every reply is held exactly as before.
      </NoticeMessage>

      <!-- 2. Scouts -------------------------------------------------------->
      <h3 class="ms-2">Scouting</h3>
      <p class="text-muted small ms-2">
        A handful of likely-interested members told early about a post nobody
        has replied to. The three signals are shown separately on purpose:
        <strong>wanted</strong> and <strong>search</strong> are things a member
        asked for, <strong>frequent</strong> is only a guess, and if it does not
        convert it should not be spending mail. Each scout is counted under the
        <em>strongest</em> signal that picked them, so the frequent row means
        "frequent and nothing else" - which is the right denominator for deciding
        whether propensity on its own is worth anything.
      </p>
      <b-table-simple hover responsive small class="mb-4">
        <b-thead>
          <b-tr>
            <b-th>Signal</b-th>
            <b-th>Mailed</b-th>
            <b-th>Replied</b-th>
            <b-th>Reply rate</b-th>
            <b-th>Typical time to reply</b-th>
            <b-th>Posts</b-th>
            <b-th>Rehomed</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="s in stats.scouts" :key="s.reason">
            <b-td>{{ signalLabel(s.reason) }}</b-td>
            <b-td>{{ s.mailed }}</b-td>
            <b-td>{{ s.replied }}</b-td>
            <b-td :class="rateClass(s.replied, s.mailed)">
              {{ rate(s.replied, s.mailed) }}
            </b-td>
            <b-td>{{ hours(s.medianhours) }}</b-td>
            <b-td>{{ s.posts }}</b-td>
            <b-td>{{ s.taken }} ({{ rate(s.taken, s.posts) }})</b-td>
          </b-tr>
          <b-tr v-if="!stats.scouts.length">
            <b-td colspan="7" class="text-muted">
              No scouts sent in this window.
            </b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <!-- 3. Freegle chat -------------------------------------------------->
      <h3 class="ms-2">Freegle chat</h3>
      <p class="text-muted small ms-2">
        Questions Freegle asks the poster when nothing is happening.
        <strong>Answered</strong> is whether the question was worth the trip to
        the chat at all; <strong>changed the post</strong> is whether the answer
        did anything - "collection only" and "no rush" are real answers that
        leave the post exactly as it was.
        <span v-if="stats.postsengaged">
          {{ stats.postsengaged }} posts were spoken to in this window.
        </span>
      </p>
      <b-table-simple hover responsive small class="mb-4">
        <b-thead>
          <b-tr>
            <b-th>Question</b-th>
            <b-th>Asked</b-th>
            <b-th>Answered</b-th>
            <b-th>Answer rate</b-th>
            <b-th>Changed the post</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="p in stats.prompts" :key="p.kind">
            <b-td>{{ promptLabel(p.kind) }}</b-td>
            <b-td>{{ p.sent }}</b-td>
            <b-td>{{ p.answered }}</b-td>
            <b-td :class="rateClass(p.answered, p.sent)">
              {{ rate(p.answered, p.sent) }}
            </b-td>
            <b-td>{{ p.acted }} ({{ rate(p.acted, p.sent) }})</b-td>
          </b-tr>
          <b-tr v-if="!stats.prompts.length">
            <b-td colspan="5" class="text-muted">
              No prompts sent in this window.
            </b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <!-- Raw counters ----------------------------------------------------->
      <h3 class="ms-2">Daily counters</h3>
      <b-table-simple hover responsive small class="mb-2">
        <b-thead>
          <b-tr>
            <b-th>Day</b-th>
            <b-th>Event</b-th>
            <b-th>Count</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="(row, ix) in stats.daily" :key="ix">
            <b-td>{{ row.day }}</b-td>
            <b-td><code>{{ row.event }}</code></b-td>
            <b-td>{{ row.count }}</b-td>
          </b-tr>
          <b-tr v-if="!stats.daily.length">
            <b-td colspan="3" class="text-muted">
              Nothing recorded in this window.
            </b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>
    </template>
  </div>
</template>
<script setup>
import { computed, ref } from 'vue'
import { useRuntimeConfig } from '#imports'
import ModEmailDateFilter from '~/modtools/components/ModEmailDateFilter.vue'
import api from '~/api'

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const stats = ref(null)
const loading = ref(false)
const error = ref(null)

const passthroughTotal = computed(() =>
  stats.value ? stats.value.passthrough.web + stats.value.passthrough.email : 0
)

const passthroughUsed = computed(() => passthroughTotal.value > 0)

// Sized covers both doors, so anything unsized is a passthrough whose saving we
// could not work out - worth showing rather than quietly averaging over.
const unsizedPassthroughs = computed(() =>
  stats.value ? Math.max(0, passthroughTotal.value - stats.value.passthrough.sized) : 0
)

const SIGNAL_LABELS = {
  wanted: 'Matching WANTED or OFFER',
  search: 'Matching saved search',
  frequent: 'Frequent replier nearby',
}

const PROMPT_LABELS = {
  photo: 'Could you add a photo?',
  delivery: 'Could you deliver?',
  deadline: 'When does it need to go?',
  views: 'People are looking',
}

function signalLabel(reason) {
  return SIGNAL_LABELS[reason] ?? reason
}

function promptLabel(kind) {
  return PROMPT_LABELS[kind] ?? kind
}

function rate(part, whole) {
  if (!whole) {
    return '-'
  }

  return Math.round((100 * part) / whole) + '%'
}

// Colour only where a rate is actually interpretable. An unhelpful red on three
// data points would be worse than no colour at all.
function rateClass(part, whole) {
  if (!whole || whole < 20) {
    return ''
  }

  const pct = (100 * part) / whole

  if (pct >= 25) {
    return 'text-success fw-bold'
  }

  return pct < 5 ? 'text-danger' : ''
}

function hours(value) {
  if (value === null || value === undefined) {
    return '-'
  }

  const v = Number(value)

  return v < 1 ? Math.round(v * 60) + ' min' : v.toFixed(1) + ' hours'
}

async function onFetch({ start, end }) {
  loading.value = true
  error.value = null

  try {
    stats.value = await apiInstance.firstreply.fetchMetrics(start, end)
  } catch (e) {
    error.value = e?.message ?? 'Could not load first-reply metrics.'
  } finally {
    loading.value = false
  }
}
</script>
