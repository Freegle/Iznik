<template>
  <b-card no-body class="mb-2">
    <b-card-body>
      <h2 class="h5">Record a donor's answer for them</h2>
      <p>
        If a donor tells you by email that we can't claim Gift Aid, or you have
        asked them and they have not replied, record it here. You don't need to
        sign in as them. Use their user ID from Support Tools.
      </p>
      <b-form-input
        v-model="userid"
        type="number"
        placeholder="User's ID from Support Tools"
        class="mb-2"
      />
      <div class="d-flex flex-wrap gap-2">
        <SpinButton
          variant="white"
          icon-name="times"
          label="They declined"
          :disabled="!userid"
          confirm
          @handle="(cb) => record(userid, 'Declined', cb)"
        />
        <SpinButton
          variant="white"
          icon-name="envelope"
          label="Asked, no reply yet"
          :disabled="!userid"
          @handle="(cb) => record(userid, 'NoResponse', cb)"
        />
      </div>
      <NoticeMessage v-if="error" variant="danger" class="mt-2">
        {{ error }}
      </NoticeMessage>
      <NoticeMessage v-else-if="done" variant="success" class="mt-2">
        {{ done }}
      </NoticeMessage>

      <h2 class="h5 mt-4">Asked, no reply</h2>
      <p v-if="!donors.length" class="text-muted">
        Nobody is waiting for a reply.
      </p>
      <div
        v-for="donor in donors"
        :key="'noresponse-' + donor.userid"
        class="noresponse border-top pt-2 mt-2"
      >
        <div class="d-flex flex-wrap justify-content-between">
          <div>
            <strong>{{ donor.fullname || 'No name' }}</strong>
            <span v-if="donor.email">
              (<ExternalLink
                :href="
                  'mailto:' +
                  donor.email +
                  '?subject=A question about Gift Aid on your donation'
                "
                class="small"
                >{{ donor.email }}</ExternalLink
              >)
            </span>
            user ID <v-icon icon="hashtag" class="text-muted" scale="0.75" />{{
              donor.userid
            }}
            <div class="small">
              <span class="badge bg-warning text-dark me-1">No response</span>
              {{ askedText(donor) }}
              <span v-if="donor.donations">
                &bull; &pound;{{ donor.donations }} donated</span
              >
            </div>
          </div>
          <div class="d-flex flex-wrap gap-2 align-items-start">
            <SpinButton
              variant="white"
              size="sm"
              icon-name="envelope"
              label="Asked again"
              @handle="(cb) => record(donor.userid, 'NoResponse', cb)"
            />
            <SpinButton
              variant="white"
              size="sm"
              icon-name="times"
              label="They declined"
              confirm
              @handle="(cb) => record(donor.userid, 'Declined', cb)"
            />
          </div>
        </div>
      </div>
    </b-card-body>
  </b-card>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { useNuxtApp } from '#app'
import { dateonly } from '~/composables/useTimeFormat'

const { $api } = useNuxtApp()

const userid = ref(null)
const donors = ref([])
const error = ref(null)
const done = ref(null)

async function fetchDonors() {
  donors.value = (await $api.giftaid.noResponse()) || []
}

function askedText(donor) {
  const who = donor.askedby
    ? 'by volunteer #' + donor.askedby
    : 'by the reminder email'
  const when = dateonly(donor.lastasked)

  return donor.asks > 1
    ? 'Asked ' + donor.asks + ' times, last on ' + when + ' ' + who
    : 'Asked on ' + when + ' ' + who
}

async function record(id, outcome, callback) {
  error.value = null
  done.value = null

  try {
    await $api.giftaid.record(parseInt(id), outcome)
    done.value =
      outcome === 'Declined'
        ? 'Recorded that user #' + id + ' declined Gift Aid.'
        : 'Recorded that user #' + id + ' has not replied.'
    await fetchDonors()
  } catch (e) {
    error.value =
      e?.response?.data?.message ||
      'That could not be recorded. Please try again.'
  }

  callback()
}

onMounted(async () => {
  await fetchDonors()
})
</script>
