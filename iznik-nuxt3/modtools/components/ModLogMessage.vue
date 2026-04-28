<template>
  <span v-if="log && log.msgid">
    <span v-if="message || log.msgsubject">
      <a
        :href="'https://www.ilovefreegle.org/message/' + log.msgid"
        target="_blank"
      >
        <v-icon icon="hashtag" class="text-muted" scale="0.75" />{{ log.msgid }}
        <em>{{ messagesubject }}</em>
      </a>
      <span v-if="!notext && log.text && log.text.length > 0">
        with <em>{{ log.text }} </em></span
      >
      <ModLogStdMsg v-if="!nostdmsg" :logid="logid" /> <ModLogGroup :logid="logid" :tag="tag" />
    </span>
    <span v-else>
      <v-icon icon="hashtag" class="text-muted" scale="0.75" />{{ log.msgid }}
      (no info available)
    </span>
  </span>
</template>
<script setup>
import { computed } from 'vue'
import { useLogsStore } from '~/stores/logs'
import { useMessageStore } from '~/stores/message'

const props = defineProps({
  logid: {
    type: Number,
    required: true,
  },
  notext: {
    type: Boolean,
    required: false,
    default: false,
  },
  nostdmsg: {
    type: Boolean,
    required: false,
    default: false,
  },
  tag: {
    type: String,
    required: false,
    default: 'on',
  },
})

const logsStore = useLogsStore()
const messageStore = useMessageStore()

const log = computed(() => logsStore.byId(props.logid))

// V2: message is fetched into store by ModLog.vue via msgid
// V1: message is embedded in the log object
const message = computed(() => {
  if (!log.value) return null
  const mid = log.value.msgid
  if (mid) {
    return messageStore.byId(mid) || log.value.message || null
  }
  return log.value.message || null
})

const messagesubject = computed(() => {
  // Prefer the subject stored at the time of the log event (historical accuracy).
  // Falls back to the current message subject for pre-migration log entries.
  if (log.value?.msgsubject) {
    return log.value.msgsubject
  }
  if (message.value) {
    return message.value.subject
      ? message.value.subject
      : '(Blank subject line)'
  }
  return '(Message now deleted)'
})
</script>
