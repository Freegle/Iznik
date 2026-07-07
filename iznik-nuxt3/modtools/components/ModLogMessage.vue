<template>
  <span v-if="log && log.msgid">
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
  // Prefer historical subject from API (msgsubject preserves the subject as it was at
  // log time, not the current post-edit value — fixes retrospective rename bug).
  if (log.value?.msgsubject) {
    return log.value.msgsubject
  }
  if (message.value?.subject) {
    return message.value.subject
  }
  return message.value ? '(Blank subject line)' : '(Message now deleted)'
})
</script>
