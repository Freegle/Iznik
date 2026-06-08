<template>
  <div v-if="message">
    <!-- Worry words flagged by the Go API (real-time, per-request check) -->
    <NoticeMessage
      v-for="(match, i) in worryMatches"
      :key="'worry-' + message.id + '-' + i"
      variant="warning"
      class="mb-1"
    >
      <p>
        Flagged for review: "<span class="text-danger fw-bold">{{
          match.worryword.keyword
        }}</span>".
      </p>
      <p v-if="match.worryword.type === 'Regulated'">
        This post looks as though it might contain a regulated substance. These
        are not legal on Freegle. If in doubt please check on
        <ExternalLink href="https://discourse.ilovefreegle.org/">Central</ExternalLink>
        first.
      </p>
      <p v-else-if="match.worryword.type === 'Reportable'">
        This post looks as though it might contain a reportable substance. These
        may need to be reported to the police. Please ask the member about it,
        and if in doubt discuss on
        <ExternalLink href="https://discourse.ilovefreegle.org/">Central</ExternalLink>.
      </p>
      <p v-else-if="match.worryword.type === 'Medicine'">
        This post looks as though it might contain a drug, medicine or supplement.
        These are not legal on Freegle. Please do not approve this without checking on
        <ExternalLink href="https://discourse.ilovefreegle.org/">Central</ExternalLink>
        first.
      </p>
      <p v-else>
        This post contains a keyword which means it's flagged up for review. If
        you can't see anything wrong with it, then it's fine to approve.
      </p>
    </NoticeMessage>

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
        <strong>Phone number:</strong> This post contains a phone number.
        Please ask the member to remove it.
      </span>
      <span v-else-if="reason.check === 'EmailAddress'">
        <strong>Email address:</strong> This post contains an email address.
        Please ask the member to remove it.
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
      <span v-else-if="reason.check === 'NotAnItem'">
        <strong>Possibly not an item:</strong> {{ reason.detail }}. This may be a
        service, rental, job, or help/advice request rather than a physical item
        — please check before approving.
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

/* Worry word matches from the Go API (message.worry array). */
const worryMatches = computed(() => {
  return message.value?.worry ?? []
})

/* Extract contentcheck_reasons from the first message group that has them.
   The API field is `groups` (not `messagegroups`). */
const contentcheckReasons = computed(() => {
  if (!message.value?.groups) return []
  for (const mg of message.value.groups) {
    const reasons = mg.contentcheck_reasons
    if (reasons && Array.isArray(reasons) && reasons.length > 0) {
      return reasons
    }
  }
  return []
})
</script>
