<template>
  <div>
    <p class="text-muted">
      Royal Mail address data (PAF) is used for the address picker. A new
      release comes out every three months. Applying it takes a few hours and
      runs on the server, so you can close this once it has started.
    </p>

    <b-card v-if="!active" class="mb-3">
      <p class="mb-3 latest-release">
        <template v-if="latest?.published">
          Latest release on AFD: published
          <strong>{{ dateText(latest.published) }}</strong>
          <span v-if="latest.size" class="text-muted">
            ({{ Math.round(latest.size / 1e6) }} MB)</span
          >.
          <span v-if="alreadyApplied" class="text-success">
            It has already been applied.
          </span>
        </template>
        <template v-else-if="latest">
          Could not check AFD for the latest release
          <span v-if="latest.error" class="text-muted"
            >({{ latest.error }})</span
          >. The newest one there is used.
        </template>
      </p>
      <b-form @submit.prevent="start">
        <b-form-group
          label="Unlock key"
          label-for="paf-key"
          class="mb-3"
          :invalid-feedback="keyFeedback"
          :state="keyState"
        >
          <template #description>
            AFD email the board when a release is ready, with the subject "PAF
            address data" and the month. Copy the unlock key from that email. It
            is eight groups of three letters and numbers, for example AB1 / CD2
            / EF3 / GH4 / JK5 / LM6 / NP7 / QR8.
          </template>
          <b-form-input
            id="paf-key"
            v-model="key"
            autocomplete="off"
            spellcheck="false"
            :state="keyState"
          />
        </b-form-group>
        <b-button
          type="submit"
          variant="primary"
          class="mt-1"
          :disabled="starting || !keyValid"
        >
          <b-spinner v-if="starting" small class="me-1" />
          Download and apply
        </b-button>
        <div v-if="startError" class="text-danger mt-2">
          {{ startError }}
        </div>
      </b-form>
    </b-card>

    <div v-if="loading" class="text-center py-3">
      <b-spinner />
    </div>
    <div v-else-if="error" class="text-danger">Failed to load: {{ error }}</div>
    <div v-else>
      <b-card v-if="active" class="mb-3 current-import">
        <h5>
          {{ stepLabel(active.status) }}
          <span
            v-if="active.release_published || active.edition"
            class="text-muted"
          >
            ({{ releaseText(active) }})
          </span>
        </h5>
        <div class="steps mb-2">
          <span
            v-for="(step, i) in steps"
            :key="step.status"
            :class="stepClass(active.status, i)"
          >
            {{ step.label }}
          </span>
        </div>
        <b-progress
          :value="active.progress"
          max="100"
          show-progress
          animated
          class="mb-2"
        />
        <div v-if="active.detail" class="text-muted">{{ active.detail }}</div>
      </b-card>

      <h5 v-if="imports.length">Recent imports</h5>
      <b-table-simple v-if="imports.length" hover responsive small>
        <b-thead>
          <b-tr>
            <b-th>Requested</b-th>
            <b-th>Release</b-th>
            <b-th>Status</b-th>
            <b-th>Lines</b-th>
            <b-th>New</b-th>
            <b-th>Changed</b-th>
            <b-th>Waiting</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr
            v-for="imp in imports"
            :key="imp.id"
            :class="{ 'table-danger': imp.status === 'failed' }"
          >
            <b-td class="text-nowrap">{{ timeago(imp.created_at) }}</b-td>
            <b-td class="text-nowrap">{{ releaseText(imp) }}</b-td>
            <b-td>
              {{ stepLabel(imp.status) }}
              <div v-if="imp.error" class="small">{{ imp.error }}</div>
            </b-td>
            <b-td>
              <span v-if="imp.lines_to_apply !== null">
                {{ imp.lines_applied.toLocaleString() }} of
                {{ imp.lines_to_apply.toLocaleString() }}
              </span>
            </b-td>
            <b-td>{{ imp.inserted.toLocaleString() }}</b-td>
            <b-td>{{ imp.updated.toLocaleString() }}</b-td>
            <b-td :title="waitingHelp">{{ imp.skipped.toLocaleString() }}</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>
      <p v-else class="text-muted">No imports yet.</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '~/api'
import { timeago } from '~/composables/useTimeFormat'

// Polled while an import is running, so the progress bar moves.
const POLL_MS = 5000

const steps = [
  { status: 'queued', label: 'Waiting' },
  { status: 'downloading', label: 'Download' },
  { status: 'extracting', label: 'Unlock' },
  { status: 'comparing', label: 'Compare' },
  { status: 'fetching', label: 'Fetch' },
  { status: 'applying', label: 'Apply' },
]

const labels = {
  queued: 'Waiting to start',
  downloading: 'Downloading from AFD',
  extracting: 'Unlocking and extracting',
  comparing: 'Finding what has changed',
  fetching: 'Fetching the changes',
  applying: 'Applying to the database',
  done: 'Done',
  failed: 'Failed',
}

const waitingHelp =
  'Addresses whose postcode we do not have yet. They are tried again with the next release.'

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const loading = ref(true)
const error = ref(null)
const imports = ref([])
const key = ref('')
const latest = ref(null)
const starting = ref(false)
const startError = ref(null)
let timer = null

// An AFD unlock key is 24 letters and numbers, written in their email as eight groups of three.
// Spaces and slashes are ignored, as the API does.
const KEY_LENGTH = 24
const keyChars = computed(() =>
  key.value.toUpperCase().replace(/[^A-Z0-9]/g, '')
)
const keyValid = computed(() => keyChars.value.length === KEY_LENGTH)
// Not marked until something has been typed.
const keyState = computed(() => (key.value.trim() ? keyValid.value : null))
const keyFeedback = computed(
  () =>
    `The key has ${KEY_LENGTH} letters and numbers. This has ${keyChars.value.length}.`
)

const alreadyApplied = computed(
  () =>
    !!latest.value?.published &&
    imports.value.some(
      (i) =>
        i.status === 'done' && i.release_published === latest.value.published
    )
)

function dateText(ymd) {
  return new Date(ymd + 'T12:00:00Z').toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

function releaseText(imp) {
  if (imp.release_published) return dateText(imp.release_published)
  return imp.edition || '-'
}

const active = computed(() =>
  imports.value.find((i) => i.status !== 'done' && i.status !== 'failed')
)

function stepLabel(status) {
  return labels[status] || status
}

function stepClass(status, i) {
  const current = steps.findIndex((s) => s.status === status)
  if (i < current) return 'step step-done'
  if (i === current) return 'step step-current'
  return 'step'
}

async function fetchImports(quiet = false) {
  if (!quiet) loading.value = true
  error.value = null

  try {
    const [list, last] = await Promise.all([
      apiInstance.paf.fetchImports(),
      quiet ? Promise.resolve(latest.value) : apiInstance.paf.fetchLatest(),
    ])
    imports.value = list || []
    latest.value = last || null
  } catch (e) {
    error.value = e.message || 'Unknown error'
  } finally {
    loading.value = false
  }
}

async function start() {
  starting.value = true
  startError.value = null

  try {
    await apiInstance.paf.startImport(key.value)
    key.value = ''
    await fetchImports(true)
  } catch (e) {
    startError.value =
      e.response?.data?.error || e.message || 'Could not start the import'
  } finally {
    starting.value = false
  }
}

onMounted(() => {
  fetchImports()
  timer = setInterval(() => {
    if (active.value) fetchImports(true)
  }, POLL_MS)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<style scoped>
.latest-release {
  font-size: 1.05rem;
}

.steps {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 1rem;
}

.step {
  color: #6c757d;
}

.step-done {
  color: #2e7d32;
}

.step-current {
  color: #212529;
  font-weight: 600;
}
</style>
