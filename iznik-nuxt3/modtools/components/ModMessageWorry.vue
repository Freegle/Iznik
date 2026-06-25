<template>
  <div v-if="message">
    <!-- Worry words flagged by the Go API (real-time, per-request check) -->
    <NoticeMessage
      v-for="(match, i) in displayedWorry"
      :key="'worry-' + message.id + '-' + i"
      variant="warning"
      class="mb-1"
    >
      <p>
        Flagged for review: "<span class="text-danger fw-bold">{{
          match.worryword.keyword
        }}</span
        >".
      </p>
      <p v-if="match.worryword.type === 'Regulated'">
        This post looks as though it might contain a regulated substance. These
        are not legal on Freegle. If in doubt please check on
        <ExternalLink href="https://discourse.ilovefreegle.org/"
          >Central</ExternalLink
        >
        first.
      </p>
      <p v-else-if="match.worryword.type === 'Reportable'">
        This post looks as though it might contain a reportable substance. These
        may need to be reported to the police. Please ask the member about it,
        and if in doubt discuss on
        <ExternalLink href="https://discourse.ilovefreegle.org/"
          >Central</ExternalLink
        >.
      </p>
      <p v-else-if="match.worryword.type === 'Medicine'">
        This post looks as though it might contain a drug, medicine or
        supplement. These are not legal on Freegle. Please do not approve this
        without checking on
        <ExternalLink href="https://discourse.ilovefreegle.org/"
          >Central</ExternalLink
        >
        first.
      </p>
      <p v-else>
        This post contains a keyword which means it's flagged up for review. If
        you can't see anything wrong with it, then it's fine to approve.
      </p>
    </NoticeMessage>

    <!-- Content check failure reasons (stored, from batch processing) -->
    <NoticeMessage
      v-for="(reason, i) in displayedReasons"
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
        <strong>Messaging app link:</strong> {{ reason.detail }}. Please ask the
        member to remove the link.
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
          possible scam or containing items that are not permitted. If you can't
          see anything wrong, it's fine to approve.
        </span>
        <span v-else>
          <strong>Flagged for review:</strong> {{ reason.detail }}. If you can't
          see anything wrong, it's fine to approve.
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
        <strong>Possibly not an item:</strong> {{ reason.detail }}. This may be
        a service, rental, job, or help/advice request rather than a physical
        item — please check before approving.
      </span>
      <span v-else> <strong>Flagged:</strong> {{ reason.detail }} </span>
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
  /* The group this copy is being moderated on (ModMessage's currentGroupid).
     When set, only that group's stored flags are shown, so a per-group worry
     word configured on one group doesn't appear when moderating another. 0 =
     no group context (e.g. legacy callers) -> fall back to first group with
     reasons. */
  groupid: {
    type: Number,
    default: 0,
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

/* The keyword a flag is about, for de-duplication. Only the keyword-based
   checks carry one; other checks (Vague, PhoneNumber, …) return null and are
   never de-duplicated. New reasons carry an explicit `keyword`; older stored
   reasons are parsed from the quoted word in the detail text. */
function reasonKeyword(reason) {
  if (
    !reason ||
    (reason.check !== 'ConcernKeyword' && reason.check !== 'PerGroupWorryWord')
  ) {
    return null
  }
  if (reason.keyword) return String(reason.keyword).toLowerCase()
  const m = /'([^']+)'/.exec(reason.detail || '')
  return m ? m[1].toLowerCase() : null
}

/* Collapse reasons that name the same keyword, preferring the richer
   ConcernKeyword (it carries a category that drives the guidance shown). */
function dedupeByKeyword(reasons) {
  const indexByKeyword = {}
  const out = []
  for (const reason of reasons) {
    const kw = reasonKeyword(reason)
    if (!kw) {
      out.push(reason)
      continue
    }
    if (!(kw in indexByKeyword)) {
      indexByKeyword[kw] = out.length
      out.push(reason)
      continue
    }
    const i = indexByKeyword[kw]
    if (
      reason.check === 'ConcernKeyword' &&
      out[i].check !== 'ConcernKeyword'
    ) {
      out[i] = reason
    }
  }
  return out
}

/* Keywords flagged by the stored content checks on ANY group the post is on.
   Used to suppress the redundant real-time worry box once the batch has stored
   a richer reason for that keyword somewhere. */
const allStoredKeywords = computed(() => {
  const set = new Set()
  for (const mg of message.value?.groups ?? []) {
    if (Array.isArray(mg.contentcheck_reasons)) {
      for (const reason of mg.contentcheck_reasons) {
        const kw = reasonKeyword(reason)
        if (kw) set.add(kw)
      }
    }
  }
  return set
})

/* Stored contentcheck_reasons to show, scoped to the group being moderated. */
const displayedReasons = computed(() => {
  const groups = message.value?.groups
  if (!groups || !groups.length) return []

  let reasons = []
  if (props.groupid) {
    const row = groups.find(
      (mg) => parseInt(mg.groupid) === parseInt(props.groupid)
    )
    reasons =
      row && Array.isArray(row.contentcheck_reasons)
        ? row.contentcheck_reasons
        : []
  } else {
    /* No group context: fall back to the first group that has reasons. */
    for (const mg of groups) {
      if (
        Array.isArray(mg.contentcheck_reasons) &&
        mg.contentcheck_reasons.length
      ) {
        reasons = mg.contentcheck_reasons
        break
      }
    }
  }
  return dedupeByKeyword(reasons)
})

/* Real-time worry words from the Go API (message.worry), minus any keyword the
   stored content check has already flagged (shown via displayedReasons). */
const displayedWorry = computed(() => {
  const stored = allStoredKeywords.value
  return (message.value?.worry ?? []).filter((match) => {
    const kw = match?.worryword?.keyword
      ? String(match.worryword.keyword).toLowerCase()
      : null
    return !kw || !stored.has(kw)
  })
})
</script>
