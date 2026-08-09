<template>
  <div>
    <h3>Council statistics</h3>
    <p class="text-muted">
      The quarterly spreadsheet councils receive. It takes a few minutes to
      build, so ask for it here and come back for the download - you don't have
      to keep this page open.
    </p>

    <b-row class="align-items-end g-2 mb-2">
      <b-col cols="12" md="5">
        <label class="small mb-0">Councils</label>
        <b-form-select v-model="selected" :options="councilOptions" multiple />
        <p class="small text-muted mb-0">
          Hold Ctrl (or Cmd) to pick more than one.
        </p>
      </b-col>
      <b-col cols="12" md="4">
        <label class="small mb-0">Quarter</label>
        <b-form-select v-model="quarter" :options="quarterOptions" />
      </b-col>
      <b-col cols="12" md="3">
        <SpinButton
          variant="primary"
          icon-name="file-excel"
          label="Generate"
          spinclass="text-white"
          :disabled="!selected.length"
          @handle="generate"
        />
      </b-col>
    </b-row>

    <NoticeMessage v-if="downloadError" variant="danger" class="mb-2">
      {{ downloadError }}
    </NoticeMessage>

    <NoticeMessage v-if="!councilOptions.length" variant="info">
      Add a partnership first - the councils you have deals with are the ones
      you can generate statistics for here.
    </NoticeMessage>

    <table v-if="jobs.length" class="table table-sm">
      <thead>
        <tr>
          <th>Requested</th>
          <th>Councils</th>
          <th>Quarter</th>
          <th>Status</th>
          <th>Spreadsheets</th>
          <th />
        </tr>
      </thead>
      <tbody>
        <tr v-for="job in jobs" :key="'job-' + job.id">
          <td>{{ job.requested }}</td>
          <td>{{ councilNames(job.authorityids) }}</td>
          <td>{{ job.quarter }}</td>
          <td>
            <b-badge :variant="statusVariant(job.status)">
              {{ job.status }}
            </b-badge>
            <div v-if="job.error" class="small text-danger">
              {{ job.error }}
            </div>
          </td>
          <td>
            <div v-for="file in job.files" :key="'file-' + file.id">
              <b-button variant="link" size="sm" @click="download(file)">
                {{ file.filename }}
              </b-button>
              <span class="text-muted small">
                ({{ Math.round(file.size / 1024) }}KB)
              </span>
            </div>
            <span
              v-if="!job.files.length && job.status !== 'Failed'"
              class="text-muted small"
            >
              Building...
            </span>
          </td>
          <td class="text-end">
            <b-button
              variant="link"
              size="sm"
              class="text-danger p-0"
              @click="remove(job)"
            >
              Delete
            </b-button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRuntimeConfig } from '#imports'
import { usePartnershipsStore } from '~/stores/partnerships'
import { useAuthStore } from '~/stores/auth'

const props = defineProps({
  // The councils we have deals with: [{ authorityid, authorityname }, ...].
  partnerships: {
    type: Array,
    required: true,
  },
})

const partnershipsStore = usePartnershipsStore()
const runtimeConfig = useRuntimeConfig()

const selected = ref([])
const quarter = ref('3 months ago')
const timer = ref(null)
const downloadError = ref(null)

const jobs = computed(() => partnershipsStore.statsJobs)

// One entry per council, however many deals we have had with it.
const councilOptions = computed(() => {
  const seen = new Map()

  props.partnerships.forEach((p) => {
    if (!seen.has(p.authorityid)) {
      seen.set(p.authorityid, { value: p.authorityid, text: p.authorityname })
    }
  })

  return [...seen.values()].sort((a, b) => a.text.localeCompare(b.text))
})

// The last four completed quarters, plus the command's own default.
const quarterOptions = computed(() => {
  const options = [{ value: '3 months ago', text: 'Last full quarter' }]
  const now = new Date()

  for (let i = 1; i <= 4; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() - i * 3, 1)
    const q = Math.floor(d.getMonth() / 3) + 1
    const start = new Date(d.getFullYear(), (q - 1) * 3, 1)
    options.push({
      value: start.toISOString().substring(0, 10),
      text: `${d.getFullYear()} Q${q}`,
    })
  }

  return options
})

function statusVariant(status) {
  if (status === 'Ready') {
    return 'success'
  }

  if (status === 'Failed') {
    return 'danger'
  }

  return 'info'
}

function councilNames(ids) {
  return String(ids)
    .split(',')
    .map((id) => {
      const match = councilOptions.value.find(
        (o) => String(o.value) === id.trim()
      )
      return match ? match.text : id
    })
    .join(', ')
}

// Fetched rather than linked, so the spreadsheet is authenticated by the usual headers
// instead of putting a token in the URL (and therefore in browser history).
async function download(file) {
  const authStore = useAuthStore()
  const headers = {}

  if (authStore?.auth?.jwt) {
    headers.Authorization = JSON.stringify(authStore.auth.jwt)
  }

  if (authStore?.auth?.persistent) {
    headers.Authorization2 = JSON.stringify(authStore.auth.persistent)
  }

  const response = await fetch(
    runtimeConfig.public.APIv2 + '/partnership/statsfile/' + file.id,
    { headers }
  )

  if (!response.ok) {
    // Without this an error response saves as a .xlsx that Excel then refuses to open,
    // which looks like a broken spreadsheet rather than a failed download.
    downloadError.value = `Could not download ${file.filename} (${response.status}).`
    return
  }

  downloadError.value = null
  const url = URL.createObjectURL(await response.blob())

  try {
    const link = document.createElement('a')
    link.href = url
    link.download = file.filename
    link.click()
  } finally {
    URL.revokeObjectURL(url)
  }
}

async function generate(callback) {
  await partnershipsStore.addStatsJob({
    authorityids: selected.value,
    quarter: quarter.value,
  })

  // The job we just queued is Pending, so start the poll back up if it had stopped.
  if (!timer.value) {
    poll()
  }

  callback?.()
}

async function remove(job) {
  await partnershipsStore.removeStatsJob(job.id)
}

// Poll while anything is building. Generation takes minutes, so a slow tick is plenty and
// keeps this off the API's back when the page is left open.
async function poll() {
  timer.value = null
  await partnershipsStore.fetchStatsJobs()

  if (partnershipsStore.statsRunning) {
    timer.value = setTimeout(poll, 10000)
  }
}

onMounted(poll)

onBeforeUnmount(() => {
  if (timer.value) {
    clearTimeout(timer.value)
  }
})
</script>
