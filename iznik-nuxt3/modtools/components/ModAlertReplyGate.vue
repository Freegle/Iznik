<template>
  <b-modal
    v-if="current"
    :model-value="true"
    size="lg"
    no-stacking
    no-close-on-backdrop
    no-close-on-esc
    hide-header-close
    hide-footer
    :title="'The ' + current.groupname + ' volunteers need a reply'"
  >
    <NoticeMessage variant="warning" class="mb-3">
      Support has asked the volunteers of {{ current.groupname }} to reply to
      this. ModTools will keep asking until you do.
    </NoticeMessage>
    <h5 class="alert-subject">{{ current.subject }}</h5>
    <!-- eslint-disable-next-line vue/no-v-html -->
    <div v-if="current.html" class="alert-body mb-3" v-html="safeHtml" />
    <p v-else class="alert-body mb-3" style="white-space: pre-wrap">
      {{ current.text }}
    </p>
    <div v-if="current.quoted" class="mb-3">
      <div class="small text-muted">The member's original email:</div>
      <blockquote class="alert-quoted border-start ps-3 text-muted">
        {{ current.quoted }}
      </blockquote>
    </div>
    <label for="alert-reply" class="fw-bold">Your reply</label>
    <b-form-textarea
      id="alert-reply"
      v-model="reply"
      rows="5"
      placeholder="Tell Support what you will do, or what you know."
    />
    <NoticeMessage v-if="error" variant="danger" class="mt-2">
      {{ error }}
    </NoticeMessage>
    <div class="d-flex justify-content-between mt-3">
      <b-button
        v-if="current.snoozeavailable"
        variant="secondary"
        :disabled="busy"
        @click="snooze"
      >
        Sleep for 30 minutes (once only)
      </b-button>
      <span v-else class="text-muted small align-self-center">
        You have used your 30 minutes for this message.
      </span>
      <b-button
        variant="primary"
        :disabled="busy || !reply.trim()"
        @click="send"
      >
        Send reply
      </b-button>
    </div>
  </b-modal>
</template>
<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import DOMPurify from 'isomorphic-dompurify'
import { useAlertStore } from '~/stores/alert'

// A moderator of a community has to answer a message that Support sent to that community's
// volunteers with "require a reply" set. This sits in the ModTools layout, so it covers every page,
// and shows one message at a time. Support or Admin cancelling the message, the 14 day limit and the
// 30 minute snooze all take effect at the next poll.
const POLL_MS = 60000

const alertStore = useAlertStore()
const reply = ref('')
const busy = ref(false)
const error = ref(null)
let timer = null

const current = computed(() => alertStore.pending?.[0] || null)
const safeHtml = computed(() => DOMPurify.sanitize(current.value?.html || ''))

async function poll() {
  try {
    await alertStore.fetchPending()
  } catch (e) {
    // A failed poll must never block a moderator: they are shown nothing new.
  }
}

async function send() {
  busy.value = true
  error.value = null
  try {
    await alertStore.reply(current.value.id, reply.value.trim())
    reply.value = ''
  } catch (e) {
    error.value = 'Sorry, that did not send. Please try again.'
  }
  busy.value = false
}

async function snooze() {
  busy.value = true
  error.value = null
  try {
    await alertStore.snooze(current.value.id)
  } catch (e) {
    error.value = 'Sorry, that did not work. Please try again.'
  }
  busy.value = false
}

onMounted(() => {
  poll()
  timer = setInterval(poll, POLL_MS)
})

onBeforeUnmount(() => {
  if (timer) {
    clearInterval(timer)
  }
})
</script>
