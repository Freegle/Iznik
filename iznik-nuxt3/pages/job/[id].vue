<template>
  <client-only>
    <b-row class="m-0 mt-4">
      <b-col cols="12" lg="6" class="p-0 mt-5" offset-lg="3">
        <NoticeMessage v-if="appshown">
          <p>The job ad should have opened in your browser</p>
          <b-button to="/jobs" variant="primary" size="lg">
            View more jobs
          </b-button>
        </NoticeMessage>
        <NoticeMessage v-else-if="invalid" class="mt-5">
          <p>Sorry, that job is no longer available.</p>
          <b-button to="/jobs" variant="primary" size="lg">
            View more jobs
          </b-button>
        </NoticeMessage>
        <NoticeMessage v-else-if="alreadyOpened" class="mt-5">
          <p>You've already looked at this job.</p>
          <div class="d-flex flex-wrap gap-2">
            <b-button to="/jobs" variant="primary" size="lg">
              View more jobs
            </b-button>
            <b-button variant="secondary" size="lg" @click="openJob">
              Open it again
            </b-button>
          </div>
        </NoticeMessage>
        <NoticeMessage v-else-if="gated" class="mt-5">
          <h1 v-if="title" class="h5 mb-1">{{ title }}</h1>
          <p v-if="location" class="mb-2">{{ location }}</p>
          <p>Tap to open this job.</p>
          <div class="d-flex flex-wrap gap-2">
            <b-button variant="primary" size="lg" @click="openJob">
              Open this job
            </b-button>
            <b-button to="/jobs" variant="secondary" size="lg">
              View more jobs
            </b-button>
          </div>
        </NoticeMessage>
        <div v-else class="d-flex justify-content-around">
          <Spinner :size="50" />
        </div>
      </b-col>
    </b-row>
  </client-only>
</template>
<script setup>
import { computed, ref, onMounted, definePageMeta, useRoute } from '#imports'
import { useJobStore } from '~/stores/job'
import { useMiscStore } from '~/stores/misc'
import { useClientLog } from '~/composables/useClientLog'
import { filterNonsense, jobLocation } from '~/composables/useJobText'
import NoticeMessage from '~/components/NoticeMessage'

definePageMeta({
  layout: 'empty',
})

const jobStore = useJobStore()
const miscStore = useMiscStore()
const { action } = useClientLog()
const route = useRoute()
const id = ref(parseInt(route.params.id))
const invalid = ref(false)
const appshown = ref(false)
const alreadyOpened = ref(false)
const gated = ref(false)

// Read tracking params from URL (added by email links).
const source = route.query.source || 'direct'
const campaign = route.query.campaign || null
const position = route.query.position ? parseInt(route.query.position) : null
const listLength = route.query.list_length
  ? parseInt(route.query.list_length)
  : null

// The browser's time zone, such as Europe/London, or undefined when the browser will not say. The
// API cannot see a clock, and a browser whose clock is in the UK is never asked for a tap.
function browserTimeZone() {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || undefined
  } catch (e) {
    return undefined
  }
}

const job = ref(await jobStore.fetchOne(id.value, browserTimeZone()))

// The title and place as the job tiles show them.
const title = computed(() => filterNonsense(job.value?.title || ''))
const location = computed(() => jobLocation(job.value?.location))

function openJob() {
  // A tap on the card is remembered on this device, so the member is asked once, for any job.
  if (gated.value) {
    jobStore.recordGateTap()
  }

  // Tag the billable click with its placement (this redirect page is reached
  // from digest-email job links) and source, so email-driven clicks are
  // attributable in logs_jobs alongside the website placements.
  jobStore.log({
    id: job.value.id,
    link: job.value.url,
    placement: 'email_redirect',
    source,
  })

  // Log to Loki for analytics. A tap on the card says so; the automatic open and
  // "Open it again" send no flag.
  action('job_ad_click', {
    job_id: job.value.id,
    job_reference: job.value.job_reference,
    job_category: job.value.category,
    cpc: job.value.cpc,
    source,
    campaign,
    position,
    list_length: listLength,
    context: 'email_redirect',
    ...(gated.value ? { gated: true } : {}),
  })

  // No swap delay: nothing on this page shows the advert.
  jobStore.recordOpened(job.value.id, 0)

  // Leaving the page cancels whatever it is still loading. Safari reports that as a failed
  // module import, and the reload that error.vue and app.vue do for one would bring this page
  // back and send the member to the advert a second time - a click WhatJobs does not pay for.
  miscStore.unloading = true
  window.addEventListener(
    'pageshow',
    (event) => {
      // Back from the advert with the page restored as it was: we are not leaving any more.
      if (event.persisted) {
        miscStore.unloading = false
      }
    },
    { once: true }
  )
  window.location = job.value.url
  appshown.value = true
}

onMounted(() => {
  if (!id.value || job.value?.id !== id.value) {
    invalid.value = true
  } else if (jobStore.openedRecently(job.value.id)) {
    // This device opened the advert within the last day: the email link tapped again, or this
    // page loading twice. WhatJobs does not pay for a repeat and counts it against the first
    // click, so offer other jobs and leave opening it again to the member.
    alreadyOpened.value = true
  } else if (job.value.autoopen === false && !jobStore.hasTappedGate()) {
    // The API asks for a tap first (the visitor is abroad or on Microsoft's network, where mail
    // scanners open links), unless this device has tapped the card before. Nothing is logged
    // against the partner, recorded or opened until the member taps the button, which is not a
    // link: a browser that only follows links gets no address to follow.
    gated.value = true
    action('job_ad_gated', {
      job_id: job.value.id,
      reason: job.value.autoopen_reason,
      source,
      campaign,
    })
  } else {
    openJob()
  }
})
</script>
