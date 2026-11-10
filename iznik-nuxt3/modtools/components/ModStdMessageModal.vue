<template>
  <b-modal
    id="stdmsgmodal"
    ref="modal"
    :title="
      message ? message.subject : 'Message to ' + (user ? user.displayname : '')
    "
    no-stacking
    no-close-on-backdrop
    size="lg"
  >
    <template #default>
      <div v-if="!isEditAction" class="d-flex">
        <div>
          From:&nbsp;
          <br />
          To:&nbsp;
        </div>
        <div>
          {{ fromName }}
          <br />
          {{ user ? user.displayname : '' }}
          <span v-if="toEmail"> &lt;{{ toEmail }}&gt; </span>
        </div>
      </div>
      <div
        v-if="message && isEditAction && message.item && message.location"
        class="d-flex justify-content-start"
      >
        <!-- eslint-disable-next-line -->
        <b-form-select
          v-model="message.type"
          :options="typeOptions"
          class="type me-1"
          size="lg"
        />
        <!-- eslint-disable-next-line -->
        <b-form-input v-model="message.item.name" size="lg" class="me-1" />
        <b-input-group>
          <PostCode
            :value="message.location.name"
            :find="false"
            @selected="postcodeSelect"
          />
        </b-input-group>
      </div>
      <div v-else>
        <b-input-group>
          <b-form-input v-model="subject" class="mt-2" />
        </b-input-group>
      </div>
      <NoticeMessage
        v-if="severalCommunitiesVerb"
        variant="info"
        class="mt-2 mb-1"
        data-test="stdmsg-several-communities"
      >
        This will {{ severalCommunitiesVerb }} the post on
        {{ groupids.length }} of your communities. The member gets this message
        once, not once for each community.
      </NoticeMessage>
      <NoticeMessage v-if="warning" variant="warning" class="mt-1 mb-1">
        <p>Please check your message in case it needs updating:</p>
        <p>
          <strong>{{ warning }}</strong>
        </p>
      </NoticeMessage>
      <p v-if="isEditAndMessage" class="fw-bold mt-2 mb-0">Post will read</p>
      <!-- Plain textarea for messages with no directives. -->
      <AutoHeightTextarea
        v-if="!useSegmentedEditor"
        v-model="body"
        rows="10"
        max-rows="40"
        class="mt-2"
      />
      <!-- With directives, the wording is laid out in order with a box per
           <editthis> and a keep/remove control per <optional>. -->
      <ModStdMessageSegments
        v-else
        ref="postEditor"
        v-model="segments"
        :blocked="blockedSegments"
        :description="
          isEditAction
            ? 'This is what the post will say.'
            : 'This is the message the member will receive.'
        "
        class="mt-2"
      />
      <div
        v-if="isEditAndMessage"
        class="mt-3"
        data-test="stdmsg-reply-section"
      >
        <p class="fw-bold mb-1">
          Message to {{ user ? user.displayname : 'the poster' }}
        </p>
        <div class="d-flex">
          <div>
            From:&nbsp;
            <br />
            To:&nbsp;
          </div>
          <div>
            {{ fromName }}
            <br />
            {{ user ? user.displayname : '' }}
            <span v-if="toEmail"> &lt;{{ toEmail }}&gt; </span>
          </div>
        </div>
        <b-form-input
          v-model="replySubject"
          class="mt-2"
          data-test="stdmsg-reply-subject"
        />
        <AutoHeightTextarea
          v-if="!useReplySegmentedEditor"
          v-model="replyBody"
          rows="6"
          max-rows="30"
          class="mt-2"
          data-test="stdmsg-reply-body"
        />
        <!-- Laid out like the post text, so <optional> gets Keep and Remove here
             too rather than showing its tags. -->
        <ModStdMessageSegments
          v-else
          ref="replyEditor"
          v-model="replySegments"
          :blocked="replyBlockedSegments"
          description="This is the message the member will receive."
          class="mt-2"
          data-test="stdmsg-reply-segments"
        />
      </div>
      <NoticeMessage v-if="sendError" variant="danger" class="mt-1 mb-1">
        {{ sendError }}
      </NoticeMessage>
      <NoticeMessage v-if="heldError" variant="warning" class="mt-1 mb-1">
        {{ heldError }}
      </NoticeMessage>
      <NoticeMessage v-if="directiveWarning" variant="danger" class="mt-1 mb-1">
        {{ directiveWarning }}
      </NoticeMessage>
      <div
        v-if="stdmsg?.newdelstatus && stdmsg.newdelstatus !== 'UNCHANGED'"
        class="mt-1"
      >
        <v-icon
          v-if="changingNewDelStatus"
          icon="sync"
          class="text-success fa-spin"
        />
        <v-icon
          v-else-if="changedNewDelStatus"
          icon="check"
          class="text-success"
        />
        <v-icon v-else icon="cog" />
        Change email frequency to <em>{{ emailfrequency }}</em>
      </div>
      <div
        v-if="stdmsg?.newmodstatus && stdmsg.newmodstatus !== 'UNCHANGED'"
        class="mt-1"
      >
        <v-icon
          v-if="changingNewModStatus"
          icon="sync"
          class="text-success fa-spin"
        />
        <v-icon
          v-else-if="changedNewModStatus"
          icon="check"
          class="text-success"
        />
        <v-icon v-else icon="cog" />
        Change moderation status to <em>{{ modstatus }}</em>
      </div>
      <div v-if="stdmsg?.action === 'Hold Message'" class="mt-1 text-warning">
        <v-icon v-if="changingHold" icon="sync" class="text-success fa-spin" />
        <v-icon v-else-if="changedHold" icon="check" class="text-success" />
        <v-icon v-else icon="pause" />
        Hold message
      </div>
      <NoticeMessage v-if="replyTooShort" variant="danger" class="mt-1 mb-1">
        This is a very short reply, so it may come across as a bit abrupt and
        unhelpful. Please make it a bit longer.
      </NoticeMessage>
    </template>
    <template #footer>
      <div class="d-flex justify-content-between w-100">
        <div>
          <b-button variant="white" class="nohover" size="xs" @click="moveLeft">
            <v-icon icon="arrow-left" />
          </b-button>
          <b-button
            variant="white"
            class="nohover"
            size="xs"
            @click="moveRight"
          >
            <v-icon icon="arrow-right" />
          </b-button>
          <b-button variant="white" class="nohover" size="xs" @click="moveUp">
            <v-icon icon="arrow-up" />
          </b-button>
          <b-button variant="white" class="nohover" size="xs" @click="moveDown">
            <v-icon icon="arrow-down" />
          </b-button>
        </div>
        <div>
          <SpinButton
            ref="processButton"
            :label="processLabel"
            icon-name="envelope"
            spinclass="success"
            variant="primary"
            :flex="false"
            icon-class="pe-1"
            class="m-1 d-inline-block"
            @handle="send"
          />
          <b-button variant="white" @click="hide"> Cancel </b-button>
        </div>
      </div>
    </template>
  </b-modal>
</template>
<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import dayjs from 'dayjs'
import { setupKeywords } from '~/composables/useKeywords'
import { useUserStore } from '~/stores/user'
import { useModGroupStore } from '@/stores/modgroup'
import { useMemberStore } from '~/stores/member'
import { useHeldNotice } from '~/composables/useHeldNotice'
import { useMessageStore } from '~/stores/message'
import { useStdmsgStore } from '~/stores/stdmsg'
import { useOurModal } from '~/composables/useOurModal'
import { SUBJECT_REGEX } from '~/constants'
import { useMe } from '~/composables/useMe'
import { useModMe } from '~/composables/useModMe'
import {
  parseEditThis,
  stripDirectiveTags,
  sendBlockers,
  hasDirectives,
  parseSegments,
  assembleSegments,
  segmentsSendBlockers,
} from '~/modtools/utils/stdMessageDirectives'

const props = defineProps({
  messageid: {
    type: Number,
    required: false,
    default: null,
  },
  // membershipid - the membership record ID, NOT the user ID.
  membershipid: {
    type: Number,
    required: false,
    default: null,
  },
  // Use stdmsgid to look up a real standard message from the stdmsg store.
  stdmsgid: {
    type: Number,
    required: false,
    default: null,
  },
  // Use stdmsgaction for inline action-only cases (e.g. 'Reject', 'Leave Member')
  // where there is no stored standard message.
  stdmsgaction: {
    type: String,
    required: false,
    default: null,
  },
  autosend: {
    type: Boolean,
    required: false,
    default: false,
  },
  // The group this action applies to. Defaults to null → falls back to the
  // message's first group (legacy single-group behaviour). Passed in so a
  // Reject/Approve/Delete on a multi-group post hits the group being moderated,
  // not whichever group happens to be first on the post.
  groupid: {
    type: Number,
    required: false,
    default: null,
  },
  // Every community an Approve, Reject or Delete is to act on at once, groupid first; null
  // for groupid alone. The member gets this message once, not once per community.
  groupids: {
    type: Array,
    required: false,
    default: null,
  },
})

const { modal, show, hide } = useOurModal()
const modGroupStore = useModGroupStore()
const messageStore = useMessageStore()
const memberStore = useMemberStore()
const { heldError, guardHold } = useHeldNotice()
const userStore = useUserStore()
const stdmsgStore = useStdmsgStore()
const { typeOptions } = setupKeywords()
const { me } = useMe()
const { checkWorkDeferGetMessages } = useModMe()

// Resolve the stdmsg: either from the store by ID, or an inline action-only object.
const stdmsg = computed(() => {
  if (props.stdmsgid) {
    return stdmsgStore.byId(props.stdmsgid) || null
  }
  if (props.stdmsgaction) {
    return { action: props.stdmsgaction }
  }
  return null
})

// "Edit and Message" changes the post and messages the poster in one go. Both edit
// actions show the post's own fields and text rather than a quoted reply.
const isEditAndMessage = computed(
  () => stdmsg.value?.action === 'Edit and Message'
)
const isEditAction = computed(
  () => stdmsg.value?.action === 'Edit' || isEditAndMessage.value
)

const subject = ref(null)
const body = ref(null)
// The message to the poster, for Edit and Message.
const replySubject = ref(null)
const replyBody = ref(null)
const originalReplyEditThis = ref([])
const sendError = ref(null)
const bodyInitialLength = ref(0)
const replyTooShort = ref(false)
// Directive tags (<editthis>/<optional>) in the template, processed below.
const originalEditThis = ref([])
const directiveWarning = ref(null)

// ── Segmented inline editor ──────────────────────────────────────────────────
// When a standard message uses directives we don't edit the raw template in one
// textarea (you hunt through the tags and lose your place with several blocks).
// ModStdMessageSegments lays it out in flow instead: fixed prose, an inline box
// per <editthis>, and a keep/remove control per <optional>. The mod fills each box
// in order; the final text is reassembled from them on send. The post text gets
// one, and so does the message to the poster for Edit and Message.
const useSegmentedEditor = ref(false)
const segments = ref([])
const useReplySegmentedEditor = ref(false)
const replySegments = ref([])
const postEditor = ref(null)
const replyEditor = ref(null)

// Which segments are holding the send up, in each editor. Set when a send is
// refused so the message points at the actual boxes rather than just
// complaining, and kept in step as they're dealt with.
const blockedSegments = ref([])
const replyBlockedSegments = ref([])
watch(
  [segments, replySegments],
  () => {
    if (blockedSegments.value.length || replyBlockedSegments.value.length) {
      // Keep the wording honest as they work through it, rather than leaving it
      // asking for things they have already done.
      markBlocked()
    }
  },
  { deep: true }
)

// Live gate for each segmented editor: outstanding editthis boxes + undecided
// optionals. Mirrors sendBlockers() for the textarea path. An editor not in use
// has no segments, so it never blocks.
const segmentBlockers = computed(() => segmentsSendBlockers(segments.value))
const replySegmentBlockers = computed(() =>
  segmentsSendBlockers(replySegments.value)
)

function markBlocked() {
  const post = segmentBlockers.value
  const reply = replySegmentBlockers.value
  blockedSegments.value = [...post.unedited, ...post.undecided]
  replyBlockedSegments.value = [...reply.unedited, ...reply.undecided]
  directiveWarning.value =
    post.ok && reply.ok ? null : blockerMessage(post, reply)
}

// Say what's outstanding, and that nothing has gone out - mods hit Send, saw the
// button spin and had no idea a decision was missing.
function blockerMessage(post, reply) {
  const unedited = post.unedited.length + reply.unedited.length
  const undecided = post.undecided.length + reply.undecided.length
  const bits = []
  if (unedited) {
    bits.push(
      unedited === 1
        ? 'fill in the highlighted box'
        : `fill in the ${unedited} highlighted boxes`
    )
  }
  if (undecided) {
    bits.push(
      undecided === 1
        ? 'choose Keep or Remove for the highlighted optional wording'
        : `choose Keep or Remove for the ${undecided} highlighted optional sections`
    )
  }
  const nothingYet = isEditAndMessage.value
    ? 'Nothing has been saved or sent yet'
    : 'Nothing has been sent yet'
  // The message to the poster sits below the post, so say when that is where
  // everything outstanding is.
  const where = post.ok ? ' in the message to the poster' : ''
  return `${nothingYet} - please ${bits.join(' and ')}${where}.`
}

// Mark everything outstanding and bring the first of it into view.
async function showBlocked() {
  markBlocked()
  await nextTick()
  if (blockedSegments.value.length) {
    await postEditor.value?.scrollToSegment(blockedSegments.value[0])
  } else if (replyBlockedSegments.value.length) {
    await replyEditor.value?.scrollToSegment(replyBlockedSegments.value[0])
  }
}

const keywordList = ['Offer', 'Taken', 'Wanted', 'Received', 'Other']
const recentDays = 31
const changingNewModStatus = ref(false)
const changedNewModStatus = ref(false)
const changingNewDelStatus = ref(false)
const changedNewDelStatus = ref(false)
const changingHold = ref(false)
const changedHold = ref(false)
const margTop = ref(0)
const margLeft = ref(0)

// Fetch message from message store by ID.
const message = computed(() => {
  if (props.messageid) {
    return messageStore.byId(props.messageid) || null
  }
  return null
})

// Fetch membership from member store. member.id in that store is the membershipid.
const membership = computed(() => {
  if (props.membershipid) {
    return memberStore.get(props.membershipid) || null
  }
  return null
})

const groupid = computed(() => {
  if (props.groupid) {
    return props.groupid
  }
  if (membership.value) {
    return membership.value.groupid
  }
  if (message.value && message.value.groups && message.value.groups.length) {
    return message.value.groups[0].groupid
  }
  return null
})

// The target user — always fetched from the user store.
// For messages: message.fromuser is a uint64 user ID.
// For memberships: membership.userid is the user ID.
const user = computed(() => {
  let uid = null

  if (message.value) {
    uid =
      typeof message.value.fromuser === 'object'
        ? message.value.fromuser?.id
        : message.value.fromuser
  } else if (membership.value) {
    uid = membership.value.userid
  }

  if (uid) {
    return userStore.byId(uid) || { id: uid, displayname: '#' + uid }
  }
  return null
})

const userid = computed(() => {
  return user.value?.id || null
})

const fromName = computed(() => {
  return me.value.displayname
})

const toEmail = computed(() => {
  let ret = null
  if (user.value && user.value.emails) {
    user.value.emails.forEach((email) => {
      if (
        email.email &&
        !email.email.includes('users.ilovefreegle.org') &&
        (ret === null || email.preferred)
      ) {
        ret = email.email
      }
    })
  } else if (user.value && user.value.email) {
    ret = user.value.email
  }

  return ret
})

// True when the recipient is a TrashNothing member. They post via TN, so their
// own posts live on TN's site — identified by a tnuserid (or a trashnothing.com
// from-address as a fallback when the user record isn't fully loaded).
const isTnUser = computed(() => {
  if (user.value?.tnuserid) return true
  const from = message.value?.fromaddr
  return !!(from && from.includes('trashnothing.com'))
})

// Where the member goes to edit/repost their own posts ($editlink). TrashNothing
// members manage theirs on TN's "Your Posts" page (per TN's help docs), not on
// ilovefreegle.org, so sending them to /myposts would be a dead end for them.
const editLink = computed(() =>
  isTnUser.value
    ? 'https://trashnothing.com/user/posts'
    : 'https://www.ilovefreegle.org/myposts'
)

// The actions that act on every community in groupids, and the notice saying so.
const SEVERAL_COMMUNITY_ACTIONS = {
  Approve: 'approve',
  Reject: 'reject',
  Delete: 'delete',
  'Delete Approved Message': 'delete',
}

const severalCommunitiesVerb = computed(() => {
  if (!(props.groupids?.length > 1)) return null
  return SEVERAL_COMMUNITY_ACTIONS[stdmsg.value?.action] || null
})

// The extra argument the message store takes to act on several communities, or none, so a
// single-community action is called exactly as it always has been.
function severalCommunitiesArgs() {
  return severalCommunitiesVerb.value ? [props.groupids] : []
}

const processLabel = computed(() => {
  switch (stdmsg.value?.action) {
    case 'Approve':
    case 'Approve Member':
      return 'Send and Approve'
    case 'Reject':
    case 'Reject Member':
      return 'Send and Reject'
    case 'Leave':
    case 'Leave Member':
    case 'Leave Approved Message':
    case 'Leave Approved Member':
      return 'Send and Leave'
    case 'Delete':
    case 'Delete Approved Message':
    case 'Delete Approved Member':
      return 'Send and Delete'
    case 'Edit':
      return 'Save Edit'
    case 'Edit and Message':
      return 'Save Edit and Send'
    case 'Hold Message':
      return 'Send and Hold'
    default:
      return 'Send'
  }
})

const modstatus = computed(() => {
  switch (stdmsg.value?.newmodstatus) {
    case 'UNCHANGED':
      return 'Unchanged'
    case 'MODERATED':
      return 'Moderated'
    case 'DEFAULT':
      return 'Group Settings'
    case 'PROHIBITED':
      return "Can't Post"
    default:
      return null
  }
})

const emailfrequency = computed(() => {
  switch (stdmsg.value?.newdelstatus) {
    case 'DIGEST':
      return 24
    case 'NONE':
      return 0
    case 'SINGLE':
      return -1
    case 'ANNOUNCEMENT':
      return 0
    default:
      return 0
  }
})

const warning = computed(() => {
  let ret = null

  if (body.value) {
    const checks = {
      yahoo:
        'Yahoo Groups is no longer supported, so any mention of it is probably out of date.',
      republisher:
        'Republisher is old and any mention of it is probably not valid any more.',
      messagemaker:
        'The Message Maker is no longer a separate tool; please just refer people to www.ilovefreegle.org.',
      cafe: 'The ChitChat area of the site is the place for cafe-type requests now.',
      newsfeed: 'Newsfeed is now called ChitChat.',
      freegledirect:
        'Freegle Direct is no longer an active term; we just talk about "our website" or "www.ilovefreegle.org" now.',
      'www.freegle.in':
        "www.freegle.in should just be freegle.in - it won't work with the www.",
      'http://': "It's better to use https:// links rather than http:// links.",
    }

    // Remove groups.ilovefreegle.org, which is the volunteers address.
    let trimmed = body.value.replace(/\s/g, '').toLowerCase()

    // Remove any Yahoo email addresses, which would trigger a false match.
    trimmed = trimmed.replace('@yahoo', '')

    for (const keyword in checks) {
      if (trimmed.includes(keyword)) {
        ret = checks[keyword]
      }
    }
  }

  return ret
})

// Trigger fetch for the fromuser ID when the message is available.
// Fetch the target user from the user store when we know their ID.
// For messages: fromuser is a uint64. For memberships: userid is the user ID.
watch(
  () => {
    if (message.value) {
      const fu = message.value.fromuser
      return typeof fu === 'object' ? fu?.id : fu
    }
    if (membership.value) {
      return membership.value.userid
    }
    return null
  },
  (uid) => {
    if (uid && !userStore.byId(uid)) {
      userStore.fetch(uid)
    }
  },
  { immediate: true }
)

watch(body, () => {
  replyTooShort.value = false
})

async function fillin() {
  // Calculate initial subject.  Everything apart from Edits adds a Re:.
  const defpref = isEditAction.value ? '' : 'Re:'

  if (membership.value) {
    subject.value =
      (stdmsg.value.subjpref ? stdmsg.value.subjpref : defpref) +
      (stdmsg.value.subjsuff ? stdmsg.value.subjsuff : '')
  } else if (isEditAndMessage.value) {
    // The post keeps its own subject; the prefix and suffix belong to the message.
    subject.value = message.value.subject

    if (replySubject.value === null) {
      // Trailing colons dropped so a prefix of "Re:" doesn't give "Re:: ".
      const pref = (stdmsg.value.subjpref || 'Re').replace(/:\s*$/, '')
      replySubject.value = await substitutionStrings(
        pref +
          ': ' +
          message.value.subject +
          (stdmsg.value.subjsuff ? stdmsg.value.subjsuff : '')
      )
    }
  } else {
    subject.value =
      (stdmsg.value.subjpref ? stdmsg.value.subjpref : defpref) +
      (stdmsg.value.action === 'Edit' ? '' : ': ') +
      message.value.subject +
      (stdmsg.value.subjsuff ? stdmsg.value.subjsuff : '')
  }

  subject.value = await substitutionStrings(subject.value)

  // For Edit actions, do not overwrite body if it's already been set by the user
  if (isEditAction.value && body.value !== null && body.value !== undefined) {
    return
  }

  // Calculate initial body
  let msg = message.value ? message.value.textbody : ''
  msg = msg || ''

  if (msg || membership.value) {
    if (msg) {
      // We have an existing body to include.  Quote it, unless it's an edit.
      if (!isEditAction.value && msg) {
        msg = '> ' + (msg + '').replace(/((\r\n)|\r|\n)/gm, '\n> ')
      }

      bodyInitialLength.value = msg.length
    }

    if (stdmsg.value) {
      if (stdmsg.value.body) {
        // Text to insert.
        if (stdmsg.value.insert === 'Top') {
          msg = stdmsg.value.body.trim() + '\n\n' + msg
        } else {
          msg = msg + '\n\n' + stdmsg.value.body.trim()
        }
      } else if (!isEditAction.value && msg) {
        // No text to insert - add a couple of blank lines at the top for typing.
        msg = '\n\n' + msg
      }

      if (stdmsg.value.edittext === 'Correct Case') {
        // First the subject
        const matches = SUBJECT_REGEX.exec(subject.value)
        if (
          matches &&
          matches.length > 0 &&
          matches[0].length > 0 &&
          message.value.item
        ) {
          subject.value =
            matches[1] +
            ': ' +
            matches[2].toLowerCase().trim() +
            ' (' +
            matches[3] +
            ')'

          message.value.item.name = matches[2].toLowerCase().trim()
        } else {
          subject.value = subject.value.toLowerCase().trim()
        }

        // Now the textbody.
        msg = msg.toLowerCase()

        // Contentious choice of single space
        msg = msg.replace(/\.( |(&nbsp;))+/g, '. ')
        msg = msg.replace(/\.\n/g, '.[-<br>-]. ')
        msg = msg.replace(/\.\s\n/g, '. [-<br>-]. ')
        const wordSplit = '. '
        const wordArray = msg.split(wordSplit)
        const numWords = wordArray.length

        for (let x = 0; x < numWords; x++) {
          if (wordArray[x]) {
            wordArray[x] = wordArray[x].replace(
              wordArray[x].charAt(0),
              wordArray[x].charAt(0).toUpperCase()
            )

            if (x === 0) {
              msg = wordArray[x] + '. '
            } else if (x !== numWords - 1) {
              msg = msg + wordArray[x] + '. '
            } else if (x === numWords - 1) {
              msg = msg + wordArray[x]
            }
          }
        }

        msg = msg.replace(/\[-<br>-\]\.\s/g, '\n')
        msg = msg.replace(/\si\s/g, ' I ')
        msg = msg.replace(/(<p>.)/i, (a, b) => {
          return b.toUpperCase()
        })
      }
    }
  } else if (stdmsg.value) {
    // No existing body
    msg = '\n\n' + (stdmsg.value.body ? stdmsg.value.body : '')
  }

  body.value = (await substitutionStrings(msg)).trim()
  // Remember the <editthis> placeholders as loaded, so we can tell later whether
  // the mod has actually personalised them before allowing the message to send.
  originalEditThis.value = parseEditThis(body.value)

  // If the message uses directives, switch to the segmented inline editor: parse
  // the substituted body into ordered text/editthis/optional segments. The plain
  // textarea path (body) is kept for messages with no directives.
  useSegmentedEditor.value = hasDirectives(body.value)
  segments.value = useSegmentedEditor.value ? parseSegments(body.value) : []

  if (isEditAndMessage.value) {
    replyBody.value = (
      await substitutionStrings(stdmsg.value.replybody || '')
    ).trim()
    originalReplyEditThis.value = parseEditThis(replyBody.value)
    useReplySegmentedEditor.value = hasDirectives(replyBody.value)
    replySegments.value = useReplySegmentedEditor.value
      ? parseSegments(replyBody.value)
      : []
  }

  if (props.autosend && !warning.value) {
    // Start doing stuff.
    guardHold(() => process())
  }
}

async function substitutionStrings(text) {
  await modGroupStore.fetchIfNeedBeMT(groupid.value)
  const group = modGroupStore.get(groupid.value)
  if (!group) return text

  if (group && text) {
    text = text.replace(/\$networkname/g, 'Freegle')

    const re = new RegExp('Freegle', 'ig')
    text = text.replace(/\$groupnonetwork/g, group.namedisplay.replace(re, ''))

    text = text.replace(/\$groupname/g, group.namedisplay)
    text = text.replace(/\$owneremail/g, group.modsemail)
    text = text.replace(/\$volunteersemail/g, group.modsemail)
    text = text.replace(/\$volunteeremail/g, group.modsemail)
    text = text.replace(/\$groupemail/g, group.groupemail)
    text = text.replace(
      /\$groupurl/g,
      group.url ||
        'https://www.ilovefreegle.org/explore/' + (group.nameshort || '')
    )
    text = text.replace(/\$myname/g, me.value.displayname)
    text = text.replace(/\$nummembers/g, group.membercount)
    text = text.replace(/\$nummods/g, group.modcount)
    // Link the member to where they can edit/repost their own posts. TrashNothing
    // members are sent to TN's own posts page (see editLink).
    text = text.replace(/\$editlink/g, editLink.value)
    if (group.settings && group.settings.reposts) {
      text = text.replace(/\$repostoffer/g, group.settings.reposts.offer)
      text = text.replace(/\$repostwanted/g, group.settings.reposts.wanted)
      // Human-readable repost interval — replaces the old hand-typed
      // "*insert repeat wanted time*" placeholder.
      const days = (n) => `${n} ${Number(n) === 1 ? 'day' : 'days'}`
      text = text.replace(
        /\$repeatoffertime/g,
        days(group.settings.reposts.offer)
      )
      text = text.replace(
        /\$repeatwantedtime/g,
        days(group.settings.reposts.wanted)
      )
    }

    text = text.replace(
      /\$origsubj/g,
      message.value ? message.value.subject : ''
    )

    if (user.value.messagehistory) {
      const history = user.value.messagehistory
      let recentmsg = ''
      let count = 0
      if (history.length) {
        history.forEach((msg) => {
          if (msg.daysago < recentDays) {
            recentmsg +=
              dayjs(msg.postdate).format('lll') + ' - ' + msg.subject + '\n'
            count++
          }
        })
      }
      text = text.replace(/\$recentmsg/gim, recentmsg)
      text = text.replace(/\$numrecentmsg/gim, count)

      keywordList.forEach((keyword) => {
        let recentmsg = ''
        let count = 0

        if (history.length) {
          history.forEach((msg) => {
            const postdate = dayjs(msg.postdate)
            const daysago = dayjs().diff(postdate, 'day')

            if (msg.type === keyword && daysago < recentDays) {
              console.log('Add for', msg, msg.postdate, dayjs(msg.postdate))
              recentmsg +=
                dayjs(msg.postdate).format('dddd Do HH:mm a') +
                ' - ' +
                msg.subject +
                '\n'
              count++
            }
          })
        }

        text = text.replace(
          new RegExp('\\$recent' + keyword.toLowerCase(), 'gim'),
          recentmsg
        )
        text = text.replace(
          new RegExp('\\$numrecent' + keyword.toLowerCase(), 'gim'),
          count
        )
      })
    }

    if (membership.value) {
      text = text.replace(
        /\$memberreason/g,
        membership.value.joincomment ? membership.value.joincomment : ''
      )
    }

    if (user.value && user.value.joined) {
      text = text.replace(
        /\$membersubdate/g,

        new dayjs(user.value.joined).format('lll')
      )
    }

    text = text.replace(/\$membermail/g, toEmail.value)
    let from

    if (user.value && user.value.realemail) {
      from = user.value.realemail
    } else if (message.value) {
      from = message.value.fromaddr
    } else if (user.value && user.value.email) {
      from = user.value.email
    }

    const fromid = from
      ? from.substring(0, from.indexOf('@'))
      : user.value.displayname
    text = text.replace(/\$memberid/g, fromid)
    const membername = user.value.displayname || fromid
    text = text.replace(/\$membername/g, membername)

    let summ = ''

    if (message.value && message.value.duplicates) {
      message.value.duplicates.forEach((m) => {
        summ += new dayjs(m.date).format('lll') + ' - ' + m.subject + '\n'
      })

      const regex = new RegExp('\\$duplicatemessages', 'gim')
      text = text.replace(regex, summ)
    }
  }

  return text
}

// A refused send never reaches the end of process(), so stop the spinner here.
async function send(callback) {
  await guardHold(() => process(callback))
  if (heldError.value && callback) callback()
}

async function process(callback) {
  replyTooShort.value = false
  sendError.value = null

  // Enforce the directive tags: don't let a templated <editthis> go out
  // un-personalised, and don't send an <optional> section without a Keep/Remove
  // decision. Block here (like the too-short check) rather than send.
  directiveWarning.value = null
  blockedSegments.value = []
  replyBlockedSegments.value = []

  // Segmented editors: anything outstanding in the post text or the message to
  // the poster stops the send, and all of it is marked at once.
  if (!segmentBlockers.value.ok || !replySegmentBlockers.value.ok) {
    await showBlocked()
    // Stop the Send button spinning. Without this it span until SpinButton's
    // 20s "callback forgotten" timeout, which reads as the send having hung.
    if (callback) callback()
    return
  }

  if (useSegmentedEditor.value) {
    // The body the recipient sees is assembled from the segments.
    body.value = assembleSegments(segments.value)
  } else {
    const blockers = sendBlockers(body.value || '', originalEditThis.value)
    if (!blockers.ok) {
      directiveWarning.value = blockers.editThis.length
        ? 'Nothing has been sent yet - please personalise the highlighted “edit this” wording before sending.'
        : 'Nothing has been sent yet - please choose Keep or Remove for each optional section before sending.'
      if (callback) callback()
      return
    }
  }

  if (isEditAndMessage.value) {
    if (useReplySegmentedEditor.value) {
      replyBody.value = assembleSegments(replySegments.value)
    } else {
      const replyBlockers = sendBlockers(
        replyBody.value || '',
        originalReplyEditThis.value
      )
      if (!replyBlockers.ok) {
        directiveWarning.value = replyBlockers.editThis.length
          ? 'Nothing has been saved or sent yet - please personalise the “edit this” wording in the message to the poster.'
          : 'Nothing has been saved or sent yet - please remove the optional markers from the message to the poster, keeping the wording you want.'
        if (callback) callback()
        return
      }
    }
    if (!stripDirectiveTags(replyBody.value || '').trim()) {
      directiveWarning.value =
        'Nothing has been saved or sent yet - the message to the poster is empty.'
      if (callback) callback()
      return
    }
  }

  const msglen = body.value.length - bodyInitialLength.value

  if (!isEditAction.value && msglen >= 0 && msglen < 30) {
    replyTooShort.value = true
  } else {
    if (
      stdmsg.value.newdelstatus &&
      stdmsg.value.newdelstatus !== 'UNCHANGED'
    ) {
      changingNewDelStatus.value = true
      await memberStore.updateMembership({
        userid: userid.value,
        groupid: groupid.value,
        emailfrequency: emailfrequency.value,
      })
      changingNewDelStatus.value = false
      changedNewDelStatus.value = true
    }

    if (
      stdmsg.value.newmodstatus &&
      stdmsg.value.newmodstatus !== 'UNCHANGED'
    ) {
      changingNewModStatus.value = true
      await memberStore.updateMembership({
        userid: userid.value,
        groupid: groupid.value,
        ourPostingStatus: stdmsg.value.newmodstatus,
      })
      changingNewModStatus.value = false
      changedNewModStatus.value = true
    }

    const subj = subject.value.trim()
    // Strip the directive tags so <editthis>/<optional> markup never reaches the
    // recipient — only the (edited / kept) content goes out.
    const bodyText = stripDirectiveTags(body.value).trim()

    switch (stdmsg.value.action) {
      case 'Approve':
        await messageStore.approve(
          message.value.id,
          groupid.value,
          subj,
          stdmsg.value.id,
          bodyText,
          ...severalCommunitiesArgs()
        )
        break
      case 'Leave':
      case 'Leave Approved Message':
        await messageStore.reply({
          id: message.value.id,
          groupid: groupid.value,
          subject: subj,
          body: bodyText,
          stdmsgid: stdmsg.value.id,
        })
        break
      case 'Hold Message':
        changingHold.value = true

        // The community matters: without it the server holds every copy of a
        // rippled post (Discourse 10102/14).
        await messageStore.hold({
          id: message.value.id,
          groupid: groupid.value,
        })

        changingHold.value = false
        changedHold.value = true

        await messageStore.reply({
          id: message.value.id,
          groupid: groupid.value,
          subject: subj,
          body: bodyText,
          stdmsgid: stdmsg.value.id,
        })
        break
      case 'Approve Member':
        await memberStore.approve({
          id: membership.value.userid,
          groupid: groupid.value,
          subject: subj,
          body: bodyText,
          stdmsgid: stdmsg.value.id,
        })
        break
      case 'Reject Member':
        await memberStore.reject({
          id: membership.value.userid,
          groupid: groupid.value,
          subject: subj,
          body: bodyText,
          stdmsgid: stdmsg.value.id,
        })
        break
      case 'Leave Member':
      case 'Leave Approved Member':
        await memberStore.reply({
          id: membership.value.userid,
          groupid: groupid.value,
          subject: subj,
          body: bodyText,
          stdmsgid: stdmsg.value.id,
        })
        break
      case 'Reject':
        await messageStore.reject(
          message.value.id,
          groupid.value,
          subj,
          stdmsg.value.id,
          bodyText,
          ...severalCommunitiesArgs()
        )
        break
      case 'Delete':
      case 'Delete Approved Message': {
        const params = {
          id: message.value.id,
          groupid: groupid.value,
          subject: subj,
          body: bodyText,
          stdmsgid: stdmsg.value.id,
        }
        if (severalCommunitiesVerb.value) {
          params.groupids = props.groupids
        }
        await messageStore.delete(params)
        break
      }
      case 'Delete Member':
      case 'Delete Approved Member':
        await memberStore.delete({
          id: membership.value.userid,
          groupid: groupid.value,
          subject: subj,
          body: bodyText,
          stdmsgid: stdmsg.value.id,
        })
        break
      case 'Edit':
        if (message.value) {
          await messageStore.patch(editParams(subj, bodyText))
        }
        break
      case 'Edit and Message':
        // One call: the server checks the poster can be messaged before it changes
        // the post, so a refusal leaves both untouched.
        try {
          await messageStore.patch({
            ...editParams(subj, bodyText),
            groupid: groupid.value,
            replysubject: replySubject.value.trim(),
            replybody: stripDirectiveTags(replyBody.value).trim(),
            stdmsgid: stdmsg.value.id,
          })
        } catch (e) {
          sendError.value =
            e?.response?.data?.message ||
            'Nothing was saved or sent - please try again.'
          if (callback) callback()
          return
        }
        break
      default:
        console.error('Unknown stdmsg action', stdmsg.value.action)
    }
    checkWorkDeferGetMessages()

    // Refresh the user's info so offer/wanted counts and posting history are up to date.
    if (userid.value) {
      userStore.fetch(userid.value, true)
    }

    hide()
  }
  if (callback) callback()
}

// The fields an edit sends for the post itself.
function editParams(subj, bodyText) {
  if (message.value.item && message.value.location) {
    // Well-structured message. Pass the contextual group so the rebuilt
    // subject uses that group's keyword.
    return {
      id: message.value.id,
      groupid: groupid.value,
      msgtype: message.value.type,
      item: message.value.item.name,
      location: message.value.location.name,
      textbody: bodyText,
    }
  }

  return {
    id: message.value.id,
    subject: subj,
    textbody: bodyText,
  }
}

function postcodeSelect(newpc) {
  message.value.location = newpc
}

function moveLeft() {
  margLeft.value -= 10
  window.document.getElementById('stdmsgmodal').style.left =
    margLeft.value + 'px'
}

function moveRight() {
  margLeft.value += 10
  window.document.getElementById('stdmsgmodal').style.left =
    margLeft.value + 'px'
}

function moveUp() {
  margTop.value -= 10
  window.document.getElementById('stdmsgmodal').style.top = margTop.value + 'px'
}

function moveDown() {
  margTop.value += 10
  window.document.getElementById('stdmsgmodal').style.top = margTop.value + 'px'
}

defineExpose({ fillin, show, modal })
</script>
