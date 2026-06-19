<template>
  <NoticeMessage v-if="shouldWarn" variant="warning" class="mt-2">
    This group restricts personal info in posts. Please remove any phone numbers
    or email addresses before posting.
  </NoticeMessage>
</template>
<script setup>
import { computed } from 'vue'
import {
  groupRestrictsPersonalInfo,
  detectPersonalInfo,
} from '~/composables/usePersonalInfoWarning'

const props = defineProps({
  group: {
    type: Object,
    default: null,
  },
  text: {
    type: String,
    default: '',
  },
})

const shouldWarn = computed(() => {
  if (!groupRestrictsPersonalInfo(props.group)) return false
  const { hasPhone, hasEmail } = detectPersonalInfo(props.text)
  return hasPhone || hasEmail
})
</script>
