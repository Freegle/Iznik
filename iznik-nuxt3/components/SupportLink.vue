<template>
  <client-only>
    <ExternalLink class="fw-bold text-dark" :href="href">{{
      text
    }}</ExternalLink>
  </client-only>
</template>
<script setup>
import { computed } from 'vue'
import { useAuthStore } from '~/stores/auth'

const props = defineProps({
  text: {
    type: String,
    default: 'support@ilovefreegle.org',
  },
  email: {
    type: String,
    default: 'support@ilovefreegle.org',
  },
  info: {
    type: String,
    required: false,
    default: '',
  },
  // The page the mail is sent from, as it reads after "sent from", e.g. "the National Reuse Day
  // page". When set, the note at the foot of the mail names it, for whoever reads that inbox.
  from: {
    type: String,
    required: false,
    default: '',
  },
})

const authStore = useAuthStore()
const myid = authStore.user?.id

const href = computed(() => {
  const infostr = props.info ? '%0D%0A%0D%0A' + props.info : ''
  let note

  if (props.from) {
    note =
      'Note to Freegle: this was sent from ' +
      encodeURIComponent(props.from) +
      '. ' +
      (myid
        ? 'This freegler was logged in as user id: #' + myid + '.'
        : 'This freegler was not logged in.')
  } else if (myid) {
    note =
      'Note to support: this freegler was logged in as user id: #' + myid + '.'
  } else {
    note =
      'Note to support: this freegler was not logged in when contacting Support to send this mail.'
  }

  return 'mailto:' + props.email + '?body=' + infostr + '%0D%0A%0D%0A' + note
})
</script>
