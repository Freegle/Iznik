<template>
  <div v-if="message">
    <!-- Content check failure reasons (stored, from batch processing) -->
    <NoticeMessage
      v-for="(reason, i) in contentcheckReasons"
      :key="'contentcheck-' + message.id + '-' + i"
      variant="warning"
      class="mb-1"
    >
      <span v-if="reason.check === 'Vague'">
        <strong>Vague post:</strong> {{ reason.detail }}. Please ask the member
        to describe the item more specifically.
      </span>
      <span v-else-if="reason.check === 'PhoneNumber'">
        <strong>Phone number:</strong> This group restricts personal info in
        posts. Please ask the member to remove their phone number.
      </span>
      <span v-else-if="reason.check === 'EmailAddress'">
        <strong>Email address:</strong> This group restricts personal info in
        posts. Please ask the member to remove their email address.
      </span>
      <span v-else-if="reason.check === 'MessagingLink'">
        <strong>Messaging app link:</strong> {{ reason.detail }}. Please ask
        the member to remove the link.
      </span>
      <span v-else-if="reason.check === 'ConcernKeyword'">
        <span v-if="reason.category === 'substance_regulated'">
          <strong>Regulated substance:</strong> This post might contain a
          regulated substance. These are not legal on Freegle. If in doubt
          please check on
          <ExternalLink href="https://discourse.ilovefreegle.org/">
            Central
          </ExternalLink>
          first.
        </span>
        <span v-else-if="reason.category === 'substance_reportable'">
          <strong>Reportable substance:</strong> This post might contain a
          reportable substance which may need to be reported to the police.
          Please ask the member about it, and if in doubt discuss on
          <ExternalLink href="https://discourse.ilovefreegle.org/">
            Central </ExternalLink
          >.
        </span>
        <span v-else-if="reason.category === 'substance_medicine'">
          <strong>Medicine or drug:</strong> This post might contain a drug,
          medicine or supplement. These are not legal on Freegle. Please do not
          approve this without checking on
          <ExternalLink href="https://discourse.ilovefreegle.org/">
            Central
          </ExternalLink>
          first.
        </span>
        <span v-else-if="reason.category === 'scam'">
          <strong>Possible scam:</strong> This post has been flagged as a
          possible scam or containing items that are not permitted. If you
          can't see anything wrong, it's fine to approve.
        </span>
        <span v-else>
          <strong>Flagged for review:</strong> {{ reason.detail }}. If you
          can't see anything wrong, it's fine to approve.
        </span>
      </span>
      <span v-else-if="reason.check === 'IpAbuse'">
        <strong>IP abuse:</strong> {{ reason.detail }}.
        <span v-if="reason.users && reason.users.length">
          Recent sender user IDs from this IP: {{ reason.users.join(', ') }}
        </span>
        <span v-else-if="reason.groups && reason.groups.length">
          Group IDs posted to from this IP: {{ reason.groups.join(', ') }}
        </span>
      </span>
      <span v-else>
        <strong>Flagged:</strong> {{ reason.detail }}
      </span>
    </NoticeMessage>

  </div>
</template>
<script setup>
import { computed, watch } from 'vue'
import { useMessageStore } from '~/stores/message'

const props = defineProps({
  messageid: {
    type: Number,
    required: true,
  },
})

const messageStore = useMessageStore()
const message = computed(() => messageStore.byId(props.messageid))

watch(
  () => props.messageid,
  (id) => {
    if (id && !messageStore.byId(id)) messageStore.fetch(id)
  },
  { immediate: true }
)

/* Extract contentcheck_reasons from the first message group that has them. */
const contentcheckReasons = computed(() => {
  if (!message.value?.messagegroups) return []
  for (const mg of message.value.messagegroups) {
    const reasons = mg.contentcheck_reasons
    if (reasons && Array.isArray(reasons) && reasons.length > 0) {
      return reasons
    }
  }
  return []
})
</script>
