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
        <strong>Vague post:</strong> {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'PhoneNumber'">
        <strong>Phone number:</strong> This group restricts personal info in
        posts. {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'EmailAddress'">
        <strong>Email address:</strong> This group restricts personal info in
        posts. {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'MessagingLink'">
        <strong>Messaging app link:</strong> {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'WorryWord'">
        <strong>Flagged word:</strong> {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'ConcernKeyword'">
        <strong>Concern keyword:</strong> {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'SpamKeyword'">
        <strong>Spam keyword:</strong> {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'Review'">
        <strong>Flagged for review:</strong> {{ reason.detail }}
      </span>
      <span v-else>
        <strong>Flagged:</strong> {{ reason.detail }}
      </span>
    </NoticeMessage>

    <!-- Live worry-word matches (computed on fetch, shown when no stored reasons) -->
    <template v-if="contentcheckReasons.length === 0">
      <NoticeMessage
        v-for="word in message.worry"
        :key="'worry-' + message.id + '-' + word.worryword.id"
        variant="warning"
        class="mb-1"
      >
        <p>
          Flagged for review: "<span class="text-danger fw-bold">{{
            word.worryword.keyword
          }}</span
          >".
          <b-button
            v-if="!expand"
            variant="link"
            class="p-0 align-top"
            @click="expand = true"
          >
            Click for more info
          </b-button>
          <b-button
            v-else
            variant="link"
            class="p-0 align-top"
            @click="expand = false"
          >
            Hide more info
          </b-button>
        </p>
        <div v-if="expand">
          <p v-if="word.worryword.type === 'Review'">
            This post contains a keyword which means it's flagged up for review.
            If you can't see anything wrong with it, then it's fine.
          </p>
          <p v-if="word.worryword.type === 'Regulated'">
            This post looks as though it might contain a regulated substance.
            These are not legal on Freegle. If in doubt please check on
            <ExternalLink href="https://discourse.ilovefreegle.org/">
              Central
            </ExternalLink>
            first.
          </p>
          <p v-if="word.worryword.type === 'Reportable'">
            This post looks as though it might contain a reportable substance.
            These may need to be reported to the police. Please ask the member
            about it to see what their reason is, and if in doubt discuss on
            <ExternalLink href="https://discourse.ilovefreegle.org/">
              Central </ExternalLink
            >.
          </p>
          <p v-if="word.worryword.type === 'Medicine'">
            This post looks as though it might contain a drug, medicine or
            supplement. These are not legal on Freegle. Please do not approve
            this without checking on
            <ExternalLink href="https://discourse.ilovefreegle.org/">
              Central
            </ExternalLink>
            first.
          </p>
          <p>
            You can find more information
            <ExternalLink href="https://wiki.ilovefreegle.org/Worry_Words">
              here </ExternalLink
            >.
          </p>
        </div>
      </NoticeMessage>
    </template>
  </div>
</template>
<script setup>
import { ref, computed, watch } from 'vue'
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

const expand = ref(false)

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
