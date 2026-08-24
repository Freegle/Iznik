<template>
  <div class="rippling-density">
    <h3 class="ms-2 mt-2">Reach budget by local density</h3>
    <p class="text-muted small ms-2">
      A post's reach budget is chosen from how thinly freeglers are spread
      around it: shorter in cities, longer in the country. Every question that
      choice raises is a comparison <em>between</em> these rows, never a single
      figure. Read them in this order:
    </p>
    <ul class="text-muted small ms-2">
      <li>
        <strong>Did the cap bind?</strong> Compare <em>Cap asked</em> with
        <em>Drive time reached</em>. A band whose reach sits well under its cap
        was never held back by it, so changing the cap will not move anything
        else in that row.
      </li>
      <li>
        <strong>Demand.</strong> <em>Replied</em> is what a shorter cap risks:
        fewer people told, so fewer replies.
      </li>
      <li>
        <strong>Outcome.</strong> <em>Rehomed</em> is the only one that matters
        on its own. A longer rural cap that lifts replies but not rehoming has
        bought mail, not reuse.
      </li>
      <li>
        <strong>Cost.</strong> <em>Held</em> replies came from outside the
        reach. A cap that is too short shows up here first, as held replies
        rising while rehoming does not.
      </li>
    </ul>

    <div class="d-flex align-items-center gap-2 ms-2 mb-3">
      <label for="density-days" class="small text-muted mb-0">Window</label>
      <b-form-select
        id="density-days"
        v-model="days"
        :options="dayOptions"
        size="sm"
        style="width: 10rem"
        @change="fetch"
      />
      <b-button
        size="sm"
        variant="secondary"
        :disabled="loading"
        @click="fetch"
      >
        Fetch
      </b-button>
    </div>

    <NoticeMessage v-if="error" variant="danger" class="mb-3 ms-2 me-2">
      {{ error }}
    </NoticeMessage>

    <div v-if="loading" class="text-center py-4">
      <b-spinner small class="me-2" />
      Loading density bands...
    </div>

    <template v-else-if="bands.length">
      <b-table-simple hover responsive small class="mb-2">
        <b-thead>
          <b-tr>
            <b-th>Band</b-th>
            <b-th>Posts</b-th>
            <b-th>Cap asked</b-th>
            <b-th>Drive time reached</b-th>
            <b-th>Nearest 400 within</b-th>
            <b-th>Audience</b-th>
            <b-th>Got a reply</b-th>
            <b-th>Rehomed</b-th>
            <b-th>Replies held</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="b in bands" :key="b.band">
            <b-td class="text-capitalize fw-bold">{{ bandLabel(b.band) }}</b-td>
            <b-td>{{ b.posts.toLocaleString() }}</b-td>
            <b-td>{{ minutes(b.capminutes) }}</b-td>
            <b-td :class="{ 'text-muted': !bound(b) }">
              {{ minutes(b.avgdrivemin) }}
            </b-td>
            <b-td>{{ miles(b.avgradiusmiles) }}</b-td>
            <b-td>{{ Math.round(b.avgaudience).toLocaleString() }}</b-td>
            <b-td
              >{{ b.replied.toLocaleString() }} ({{
                rate(b.replied, b.posts)
              }})</b-td
            >
            <b-td class="fw-bold">
              {{ b.taken.toLocaleString() }} ({{ rate(b.taken, b.posts) }})
            </b-td>
            <b-td>
              {{ b.held.toLocaleString() }}
              <span class="text-muted"
                >({{ b.released.toLocaleString() }} since sent)</span
              >
            </b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>

      <NoticeMessage
        v-if="unknownBand"
        variant="warning"
        class="mb-3 ms-2 me-2"
      >
        {{ unknownBand.posts.toLocaleString() }} posts could not be measured and
        ran on the flat cap. That is the spatial service being unreachable, or
        density sizing being switched off. It is not a fourth kind of place, and
        a growing number here means the measurement is failing rather than that
        those posts did badly.
      </NoticeMessage>

      <p class="text-muted small ms-2">
        A withdrawn or deleted post loses its reach row, so it drops out of
        every column here. Rehomed is therefore an overestimate in all bands,
        which is why these rows are only safe to read against each other.
      </p>
    </template>

    <NoticeMessage v-else-if="fetched" variant="info" class="mb-3 ms-2 me-2">
      No posts started rippling in this window.
    </NoticeMessage>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRuntimeConfig } from '#imports'
import api from '~/api'

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const days = ref(30)
const bands = ref([])
const loading = ref(false)
const fetched = ref(false)
const error = ref(null)

const dayOptions = [
  { value: 7, text: 'Last 7 days' },
  { value: 30, text: 'Last 30 days' },
  { value: 90, text: 'Last 90 days' },
]

const BAND_LABELS = {
  dense: 'Dense (city)',
  medium: 'Medium (suburban)',
  sparse: 'Sparse (rural)',
  unknown: 'Not measured',
}

function bandLabel(band) {
  return BAND_LABELS[band] ?? band
}

// Kept out of the main table and called out on its own: an unmeasured post ran
// on the flat cap, so its row says nothing about any band's budget.
const unknownBand = computed(() =>
  bands.value.find((b) => b.band === 'unknown' && b.posts > 0)
)

// Whether the cap was actually the constraint. Within a minute of the budget
// counts as binding; well under it means the road network or the audience
// governor stopped the ripple first, and the cap is not what is shaping the row.
function bound(b) {
  return b.capminutes > 0 && b.avgdrivemin >= b.capminutes - 1
}

function minutes(v) {
  return v ? Math.round(v) + ' min' : '-'
}

function miles(v) {
  return v ? v.toFixed(1) + ' mi' : '-'
}

function rate(part, whole) {
  if (!whole) {
    return '-'
  }

  return Math.round((100 * part) / whole) + '%'
}

async function fetch() {
  loading.value = true
  error.value = null

  try {
    const ret = await apiInstance.rippling.fetchDensity(days.value)
    bands.value = ret?.bands ?? []
    fetched.value = true
  } catch (e) {
    error.value = e.message ?? String(e)
  } finally {
    loading.value = false
  }
}

fetch()
</script>
