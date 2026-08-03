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
      <p v-if="substanceCopy(WORRY_TYPE_CATEGORY[match.worryword.type])">
        <strong
          >{{
            substanceCopy(WORRY_TYPE_CATEGORY[match.worryword.type]).label
          }}:</strong
        >
        {{ substanceCopy(WORRY_TYPE_CATEGORY[match.worryword.type]).body }} If
        you have questions, ask on
        <ExternalLink href="https://discourse.ilovefreegle.org/c/central/9"
          >Central</ExternalLink
        >.
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
      :variant="reasonVariant(reason)"
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
        <span v-if="substanceCopy(reason.category)">
          <strong>{{ substanceCopy(reason.category).label }}:</strong>
          {{ substanceCopy(reason.category).body }} If you have questions, ask
          on
          <ExternalLink href="https://discourse.ilovefreegle.org/c/central/9"
            >Central</ExternalLink
          >.
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
        <!-- Name the word that did it. A paint post flagged as a Medicine gave
             no clue it was "mineral", so the category looked arbitrary and the
             answer had to be typed out by hand on Central (Discourse #9988).
             Only for the categorised messages above - the generic one already
             quotes the word in its detail text. -->
        <span v-if="reason.category && flaggedWord(reason)" class="d-block">
          Triggered by the word "<span class="text-danger fw-bold">{{
            flaggedWord(reason)
          }}</span
          >".
        </span>
      </span>
      <span v-else-if="reason.check === 'NotAnItem'">
        <strong>Possibly not an item:</strong> {{ reason.detail }}. This may be
        a service, rental, job, or help/advice request rather than a physical
        item — please check before approving.
      </span>
      <!-- Held by a setting rather than by anything in the post. Nothing is
           wrong with these, so they must not read like a flag - a queue that
           says nothing at all is what prompted Discourse #9987. -->
      <span v-else-if="reason.check === 'GroupModerated'">
        <strong>Group setting:</strong> This group moderates all posts, whatever
        the member's setting, so this one needs approving.
      </span>
      <span v-else-if="reason.check === 'MemberModerated'">
        <strong>Member setting:</strong> This member's posts are moderated, so
        this one needs approving.
      </span>
      <span v-else-if="reason.check === 'NoLocation'">
        <strong>No location:</strong> We couldn't work out where this post is.
        Please click <strong>Edit</strong> and add a postcode so members can
        find it.
      </span>
      <!-- Same lead-in as every other flag above. It used to say just "Flagged",
           so one post could carry both wordings for the same kind of thing. -->
      <span v-else>
        <strong>Flagged for review:</strong> {{ reason.detail }}
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
  /* The group this copy is being moderated on (ModMessage's currentGroupid).
     When set, only that group's stored flags are shown, so a per-group worry
     word configured on one group doesn't appear when moderating another. 0 =
     no group context (e.g. legacy callers) -> fall back to first group with
     reasons. */
  groupid: {
    type: Number,
    default: 0,
  },
  /* Check names the parent is already explaining with its own notices, which know
     the live state (where the post resolved to now, the member's current posting
     status) where a stored reason may be out of date. Their stored copies are
     dropped here so a moderator isn't told the same thing twice - a post with two
     flags was showing four notices (Discourse #9989). */
  covered: {
    type: Array,
    default: () => [],
  },
})

/* Guidance for the substance categories, in one place because the same three
   cases arrive by two routes - a real-time worry word (typed Regulated /
   Reportable / Medicine) and a stored content check (categorised) - and used to
   be worded differently in each, so the same post read differently depending on
   which pass caught it. Every one ends with the same "If you have questions, ask on
   Central", added by the template. */
const SUBSTANCE_COPY = {
  substance_regulated: {
    label: 'Regulated substance',
    body: "This post might contain a regulated substance, which isn't allowed on Freegle.",
  },
  substance_reportable: {
    label: 'Reportable substance',
    body: 'This post might contain a reportable substance, which may need to be reported to the police. Please ask the member about it.',
  },
  substance_medicine: {
    label: 'Medicine or drug',
    body: "This post might contain a drug, medicine or supplement, which isn't allowed on Freegle.",
  },
}

/* worrywords.type names the same three cases as the stored categories. */
const WORRY_TYPE_CATEGORY = {
  Regulated: 'substance_regulated',
  Reportable: 'substance_reportable',
  Medicine: 'substance_medicine',
}

function substanceCopy(category) {
  return SUBSTANCE_COPY[category] || null
}

/* Checks that mean "held by a setting", not "something looks wrong with this
   post" - so they show as information rather than as a warning. */
const SETTING_CHECKS = ['GroupModerated', 'MemberModerated']

function reasonVariant(reason) {
  return SETTING_CHECKS.includes(reason?.check) ? 'info' : 'warning'
}

const messageStore = useMessageStore()
const message = computed(() => messageStore.byId(props.messageid))

watch(
  () => props.messageid,
  (id) => {
    if (id && !messageStore.byId(id)) messageStore.fetch(id)
  },
  { immediate: true }
)

/* The word that triggered a keyword flag, as a moderator should see it. New
   reasons carry an explicit `keyword`; older stored ones only have it quoted
   inside the detail text. */
function flaggedWord(reason) {
  if (reason?.keyword) return String(reason.keyword)
  const m = /'([^']+)'/.exec(reason?.detail || '')
  return m ? m[1] : null
}

/* The keyword a flag is about, for de-duplication. Only the keyword-based
   checks carry one; other checks (Vague, PhoneNumber, …) return null and are
   never de-duplicated. */
function reasonKeyword(reason) {
  if (
    !reason ||
    (reason.check !== 'ConcernKeyword' && reason.check !== 'PerGroupWorryWord')
  ) {
    return null
  }
  const word = flaggedWord(reason)
  return word ? word.toLowerCase() : null
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

/* The API sends contentcheck_reasons as a JSON array, but tolerate a string in
   case a caller passes the column through unparsed - and never let a malformed
   value take the whole message card down. */
function parseReasons(raw) {
  if (!raw) return []

  let parsed = raw

  if (typeof raw === 'string') {
    try {
      parsed = JSON.parse(raw)
    } catch (e) {
      console.error('Could not parse contentcheck_reasons', e)
      return []
    }
  }

  return Array.isArray(parsed) ? parsed : []
}

/* Keywords flagged by the stored content checks on ANY group the post is on.
   Used to suppress the redundant real-time worry box once the batch has stored
   a richer reason for that keyword somewhere. */
const allStoredKeywords = computed(() => {
  const set = new Set()
  for (const mg of message.value?.groups ?? []) {
    for (const reason of parseReasons(mg.contentcheck_reasons)) {
      const kw = reasonKeyword(reason)
      if (kw) set.add(kw)
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
    reasons = row ? parseReasons(row.contentcheck_reasons) : []
  } else {
    /* No group context: fall back to the first group that has reasons. */
    for (const mg of groups) {
      const parsed = parseReasons(mg.contentcheck_reasons)
      if (parsed.length) {
        reasons = parsed
        break
      }
    }
  }
  return dedupeByKeyword(reasons).filter(
    (reason) => !props.covered.includes(reason?.check)
  )
})

/* A "£" or "$" worry word and the stored Money check are the same symbol found
   by two different passes. dedupeByKeyword can't see the overlap because the
   Money reason names no keyword, so it needs matching on the symbol itself. */
const MONEY_KEYWORDS = new Set(['£', '$'])

const showingMoneyCheck = computed(() =>
  displayedReasons.value.some((reason) => reason?.check === 'Money')
)

/* Real-time worry words from the Go API (message.worry), minus any keyword the
   stored content check has already flagged (shown via displayedReasons). */
const displayedWorry = computed(() => {
  const stored = allStoredKeywords.value
  return (message.value?.worry ?? []).filter((match) => {
    const kw = match?.worryword?.keyword
      ? String(match.worryword.keyword).toLowerCase()
      : null
    if (!kw) return true
    if (MONEY_KEYWORDS.has(kw) && showingMoneyCheck.value) return false
    return !stored.has(kw)
  })
})
</script>
