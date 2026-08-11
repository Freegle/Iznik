<template>
  <div class="firstreply-effectiveness">
    <p class="text-muted">
      Many posts never get a reply, and from the poster's side a post that is
      quietly working looks exactly like one that has failed. This page asks
      whether the first-reply levers actually change that - do treated posts
      get replies, and get Taken, more often than untreated ones? Each lever is
      switchable on its own, so one that does nothing here should be turned off
      rather than left to add mail.
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
      <!-- 0. The overall KPI ----------------------------------------------->
      <h3 class="ms-2 mt-2">Did it work?</h3>
      <p class="text-muted small ms-2">
        Rippled posts that got the treatment, against the ones that did not.
        This table is the verdict; the sections below it only show each lever's
        own activity (mail sent, prompts answered), which can all look busy
        without any more items actually being rehomed. If the rates here do not
        beat the holdout, the levers are not working, however active they look.
        <strong>Taken</strong> is the one that matters.
      </p>
      <p v-if="stats.armsfrom" class="text-muted small ms-2">
        Counting rippled posts since <strong>{{ stats.armsfrom }}</strong> —
        when the trial went live. Earlier posts never had the treatment, so
        including them would credit (or blame) the feature for replies it had
        nothing to do with.
      </p>
      <div v-if="armRates.length" class="arm-chart ms-2 mb-3" role="img" aria-label="Reply and Taken rates, trial versus holdout">
        <div class="arm-legend small">
          <span class="arm-swatch arm-swatch-trial" /> Trial
          <span class="arm-swatch arm-swatch-holdout ms-3" /> Holdout
        </div>
        <div v-for="row in armRates" :key="row.label" class="arm-row">
          <div class="arm-measure small fw-bold">{{ row.label }}</div>
          <div
            v-for="bar in row.bars"
            :key="bar.arm"
            class="arm-bar-line"
            :title="`${bar.arm}: ${bar.count} of ${bar.posts} posts (${bar.pct})`"
          >
            <span class="arm-name small text-muted text-capitalize">{{ bar.arm }}</span>
            <span class="arm-track">
              <span
                class="arm-fill"
                :class="'arm-fill-' + bar.arm"
                :style="{ width: bar.width + '%' }"
              />
            </span>
            <span class="arm-value small">
              {{ bar.pct }} <span class="text-muted">of {{ bar.posts }}</span>
            </span>
          </div>
        </div>
      </div>
      <b-table-simple hover responsive small class="mb-2">
        <b-thead>
          <b-tr>
            <b-th>Arm</b-th>
            <b-th>Posts</b-th>
            <b-th>Got a reply</b-th>
            <b-th>Taken</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="a in stats.arms" :key="a.arm">
            <b-td class="text-capitalize">{{ a.arm }}</b-td>
            <b-td>{{ a.posts }}</b-td>
            <b-td>{{ a.replied }} ({{ rate(a.replied, a.posts) }})</b-td>
            <b-td class="fw-bold">{{ a.taken }} ({{ rate(a.taken, a.posts) }})</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>
      <NoticeMessage v-if="!comparable" variant="warning" class="mb-4 ms-2 me-2">
        The rollout is at {{ stats.rolloutpercent }}%, so one side of the split is
        empty and there is nothing to compare against. These numbers describe the
        whole network, not the effect of the feature.
      </NoticeMessage>
      <p v-else class="text-muted small ms-2 mb-4">
        Rollout is at {{ stats.rolloutpercent }}%. Posts are assigned by a
        stable hash of the post id, so the arms are comparable, but they are
        not equal in size - read the percentages, not the counts. Taken also
        depends on posters coming back to record an outcome, which is itself
        something the feature may change.
      </p>

      <!-- 1. Passthrough --------------------------------------------------->
      <h3 class="ms-2 mt-2">Passthrough</h3>
      <p class="text-muted small ms-2">
        First replies delivered straight away instead of being held until the
        ripple reached the replier. The value of each one is the wait it avoided,
        so that is measured per reply: for that replier, at that location, when
        <em>would</em> the reach have got to them?
      </p>
      <p class="text-muted small ms-2">
        Right now these mostly arrive from TrashNothing, plus Freegle members
        who have changed their setting to see posts they cannot yet reply to —
        most Freegle members never see an out-of-reach post, so they cannot be
        its first reply. If first replies prove their worth, the reach
        algorithm itself may change to produce more of them.
      </p>
      <b-table-simple hover responsive small class="mb-4">
        <b-tbody>
          <b-tr>
            <b-td>Let through, replying on Freegle (web or app)</b-td>
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

      <!-- 2. Match mail ---------------------------------------------------->
      <h3 class="ms-2">Matches</h3>
      <p class="text-muted small ms-2">
        Members told individually about a post nobody has replied to, because
        they asked for that item. The two signals are shown separately on
        purpose: <strong>wanted</strong> is an outstanding post of theirs on the
        other side of the trade, <strong>search</strong> is something they
        searched for in the last six months. Both are requests, which is what
        justifies an extra mail rather than their digest arriving sooner. A
        <strong>frequent</strong> row is propensity data from an earlier trial -
        it converted 3 times in 7,902 mails, so it is no longer a signal and no
        new rows are written under it.
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
          <b-tr v-for="s in stats.matches" :key="s.reason">
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
          <b-tr v-if="!stats.matches.length">
            <b-td colspan="7" class="text-muted">
              No match mail sent in this window.
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

// A comparison needs both sides. At 0% or 100% the split still returns a row,
// and that row looks exactly as authoritative as a real result - so say plainly
// that it is not one rather than letting it be read as the effect of the feature.
const comparable = computed(() => {
  const arms = stats.value?.arms ?? []
  return arms.filter((a) => a.posts > 0).length === 2
})

// The "Did it work?" bars: replied-rate and taken-rate per arm, scaled to the
// larger rate in each pair so the comparison fills the row. Rates, never raw
// counts - the arms are deliberately different sizes (rollout percent), so
// counts always "blow out" on the holdout side and say nothing.
const armRates = computed(() => {
  const arms = stats.value?.arms ?? []
  if (arms.filter((a) => a.posts > 0).length !== 2) {
    return []
  }
  const ordered = ['trial', 'holdout']
    .map((name) => arms.find((a) => a.arm === name))
    .filter(Boolean)

  return [
    { label: 'Got a reply', key: 'replied' },
    { label: 'Taken', key: 'taken' },
  ].map(({ label, key }) => {
    const rates = ordered.map((a) => (a.posts ? a[key] / a.posts : 0))
    const max = Math.max(...rates, 0.0001)
    return {
      label,
      bars: ordered.map((a, i) => ({
        arm: a.arm,
        count: a[key],
        posts: a.posts,
        pct: ((a.posts ? a[key] / a.posts : 0) * 100).toFixed(1) + '%',
        width: Math.round((rates[i] / max) * 100),
      })),
    }
  })
})

const passthroughTotal = computed(() =>
  stats.value ? stats.value.passthrough.web + stats.value.passthrough.email : 0
)

const passthroughUsed = computed(() => passthroughTotal.value > 0)

// Counted server-side from the passthrough rows themselves, not by subtracting
// sized from the daily counters: those are a different table and can legitimately
// diverge, which would put a wrong number in front of the reader.
const unsizedPassthroughs = computed(() =>
  stats.value ? stats.value.passthrough.unsized : 0
)

// "Recent search", not "saved search": nobody saves anything. Every search a
// logged-in member runs writes a users_searches row (message.go's Search
// handler), and SearchMatchesForPost matches against those, bounded to the last
// six months. Labelled "saved search" this row read as a feature few people use,
// so the page looked like it ignored what members had actually been searching
// for - when that is exactly what this signal is.
const SIGNAL_LABELS = {
  wanted: 'Matching their WANTED or OFFER',
  search: 'Matching a recent search',
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

<style scoped>
/* The "Did it work?" comparison. Thin marks, rounded data ends, values as
   ink-coloured text beside the bar (never text in the series colour). Trial
   and holdout hues validated for CVD separation and contrast on white. */
.arm-chart {
  max-width: 34rem;
}
.arm-legend {
  margin-bottom: 0.25rem;
}
.arm-swatch {
  display: inline-block;
  width: 0.75rem;
  height: 0.75rem;
  border-radius: 2px;
  vertical-align: -1px;
}
.arm-swatch-trial,
.arm-fill-trial {
  background-color: #2a78d6;
}
.arm-swatch-holdout,
.arm-fill-holdout {
  background-color: #eb6834;
}
.arm-row {
  margin-bottom: 0.5rem;
}
.arm-bar-line {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 2px;
}
.arm-name {
  flex: 0 0 4rem;
}
.arm-track {
  flex: 1 1 auto;
  display: block;
  height: 14px;
  background: rgba(0, 0, 0, 0.05);
  border-radius: 4px;
  overflow: hidden;
}
.arm-fill {
  display: block;
  height: 100%;
  border-radius: 0 4px 4px 0;
  min-width: 2px;
}
.arm-value {
  flex: 0 0 9rem;
  white-space: nowrap;
}
</style>
