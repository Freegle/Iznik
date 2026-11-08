<template>
  <div class="p-2 support-case">
    <NoticeMessage v-if="!allowed" variant="warning">
      You don't have access to support cases.
    </NoticeMessage>

    <!-- The list, for Support and Admin. -->
    <div v-else-if="!caseId">
      <div v-if="!supportOrAdmin">
        <NoticeMessage variant="info">
          The link to a case is in the email that Support's reply sends. The
          case also opens from the notice you see right after you refer it.
        </NoticeMessage>
      </div>
      <ModSupportCaseList v-else />
    </div>

    <!-- One case. -->
    <div v-else>
      <div v-if="loading && !item" class="text-center p-4"><b-spinner /></div>
      <NoticeMessage
        v-else-if="notfound"
        variant="warning"
        data-testid="case-notfound"
      >
        That case was not found.
      </NoticeMessage>
      <NoticeMessage v-else-if="error" variant="danger">{{
        error
      }}</NoticeMessage>

      <div
        v-else-if="item && item.viewer === 'Support'"
        data-testid="case-support"
      >
        <!-- A case is its own page; this takes Support back to the Cases tab of Support Tools. -->
        <nuxt-link
          to="/support?tab=cases"
          class="small d-inline-block mb-1"
          data-testid="case-back-to-list"
          >&lsaquo; Support cases</nuxt-link
        >
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
          <h2 class="mb-0">Case {{ item.id }}</h2>
          <b-badge
            :variant="statusVariant(item.status)"
            data-testid="case-status"
          >
            {{ item.status }}
          </b-badge>
          <b-badge
            v-if="brief?.classification"
            variant="info"
            data-testid="case-classification"
          >
            {{ brief.classification }}
          </b-badge>
        </div>
        <p class="small text-muted">
          {{ referredLine }}
          <template v-if="item.handledby">
            Handled by {{ item.handledby.displayname }}
            <template v-if="item.handled_at">
              {{ timeago(item.handled_at) }} </template
            >.
          </template>
        </p>
        <p v-if="item.reason" class="case-reason" data-testid="case-reason">
          <strong>Their question:</strong> {{ item.reason }}
        </p>
        <NoticeMessage
          v-if="actionError"
          variant="danger"
          data-testid="case-action-error"
        >
          {{ actionError }}
        </NoticeMessage>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <b-button
            v-if="item.chatid"
            variant="outline-secondary"
            data-testid="case-view-chat"
            @click="viewChat"
          >
            View chat
          </b-button>
          <b-button
            v-if="item.status === 'Open'"
            variant="primary"
            :disabled="busy"
            data-testid="case-handle"
            @click="setStatus('Handled')"
          >
            Mark handled
          </b-button>
          <b-button
            v-else
            variant="outline-primary"
            :disabled="busy"
            data-testid="case-reopen"
            @click="setStatus('Open')"
          >
            Reopen
          </b-button>
        </div>

        <NoticeMessage
          v-if="
            item.firstpass_status === 'Pending' ||
            item.firstpass_status === 'Running'
          "
          variant="info"
          data-testid="case-firstpass-waiting"
        >
          <template v-if="item.firstpass_status === 'Pending'">
            The first pass is waiting to run. This page does not update by
            itself.
          </template>
          <template v-else>The first pass is running now.</template>
        </NoticeMessage>
        <NoticeMessage
          v-else-if="
            item.firstpass_status === 'Failed' ||
            item.firstpass_status === 'Skipped'
          "
          variant="warning"
          data-testid="case-firstpass-failed"
        >
          There is no first pass for this case<template
            v-if="item.firstpass_error"
            >: {{ item.firstpass_error }}</template
          >.
        </NoticeMessage>

        <div v-if="brief" data-testid="case-brief">
          <h3 class="case-question" data-testid="case-question">
            {{ brief.question }}
          </h3>
          <!-- eslint-disable vue/no-v-html -->
          <div
            class="mb-2"
            data-testid="case-answer"
            v-html="renderMarkdown(brief.answer)"
          />
          <!-- eslint-enable vue/no-v-html -->
          <p
            v-if="brief.confidence"
            class="small"
            data-testid="case-confidence"
          >
            <strong>Confidence: {{ brief.confidence.level }}.</strong>
            {{ brief.confidence.reason }}
          </p>

          <div
            v-if="brief.open_questions?.length"
            class="mb-3"
            data-testid="case-openquestions"
          >
            <h4>Still open</h4>
            <ul>
              <li v-for="(q, i) in brief.open_questions" :key="'q' + i">
                {{ q }}
              </li>
            </ul>
          </div>

          <div
            v-if="brief.related_cases?.length"
            class="mb-3"
            data-testid="case-related"
          >
            <h4>Related cases</h4>
            <ul class="list-inline">
              <li
                v-for="rid in brief.related_cases"
                :key="'r' + rid"
                class="list-inline-item"
              >
                <nuxt-link :to="'/support/cases/' + rid"
                  >Case {{ rid }}</nuxt-link
                >
              </li>
            </ul>
          </div>

          <div v-if="brief.draft" class="draft mb-3" data-testid="case-draft">
            <h4>Draft reply to the referring volunteer</h4>
            <p class="small text-muted">
              Not sent. Check it before you use it.
            </p>
            <div class="draft__text" data-testid="draft-text">
              {{ brief.draft.text }}
            </div>
            <div
              v-if="brief.draft.link_warnings?.length"
              data-testid="case-linkwarnings"
            >
              <strong>Check before sending</strong>
              <ul>
                <li v-for="(w, i) in brief.draft.link_warnings" :key="i">
                  {{ w }}
                </li>
              </ul>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <b-button
                variant="outline-secondary"
                data-testid="draft-copy"
                @click="copyDraft"
              >
                {{ copied ? 'Copied' : 'Copy' }}
              </b-button>
              <b-button
                variant="outline-primary"
                data-testid="draft-use"
                @click="fillReplyFromDraft"
              >
                Use as reply
              </b-button>
            </div>
          </div>

          <details
            v-if="brief.checks?.length"
            class="mb-3"
            data-testid="case-checks"
          >
            <summary>What was checked</summary>
            <ul class="small mb-0">
              <li v-for="(c, i) in brief.checks" :key="'c' + i">{{ c }}</li>
            </ul>
          </details>

          <p v-if="poolText" class="small text-muted" data-testid="case-pool">
            {{ poolText }}
          </p>
          <p
            v-if="tokensText"
            class="small text-muted"
            data-testid="case-tokens"
          >
            {{ tokensText }}
          </p>
          <ModSupportAIRating
            v-if="item.firstpass_runid"
            :key="'rating-' + item.firstpass_runid + ':' + (run?.rating ?? '')"
            :run-id="item.firstpass_runid"
            :rating="run?.rating ?? null"
            :comment="run?.rating_comment || ''"
          />
        </div>
      </div>

      <div
        v-else-if="item && item.viewer === 'Referrer'"
        data-testid="case-referrer"
      >
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
          <h2 class="mb-0">Your referral to Support</h2>
          <b-badge
            :variant="statusVariant(item.status)"
            data-testid="case-status"
          >
            {{ item.status }}
          </b-badge>
        </div>
        <p class="small text-muted">Referred {{ timeago(item.created) }}.</p>
        <p class="case-intro" data-testid="case-referrer-intro">
          You sent this chat to Support with Refer to Support. Support will
          reply to you here, and we'll email you when they do. You can reply
          here too. This is between you and Support: nothing here goes to the
          member.
        </p>
        <p v-if="item.reason" class="case-reason" data-testid="case-reason">
          <strong>What you asked:</strong> {{ item.reason }}
        </p>
      </div>

      <div v-if="item" class="mt-3" data-testid="case-thread">
        <h3>Replies</h3>
        <p v-if="!item.messages?.length" class="text-muted">No replies yet.</p>
        <div
          v-for="m in item.messages || []"
          :key="m.id"
          class="reply p-2 mb-2"
          :class="'reply--' + m.role.toLowerCase()"
          data-testid="case-message"
        >
          <div class="small text-muted">
            {{ m.displayname || m.role }} ({{ m.role }}),
            {{ timeago(m.created) }}
          </div>
          <div class="reply__body">{{ m.body }}</div>
        </div>

        <div class="mt-3">
          <label for="case-reply" class="form-label">Your reply</label>
          <b-form-textarea
            id="case-reply"
            v-model="reply"
            rows="5"
            maxlength="10000"
            autocapitalize="none"
            data-testid="case-reply"
          />
          <NoticeMessage v-if="sendError" variant="danger" class="mt-2">
            {{ sendError }}
          </NoticeMessage>
          <b-button
            variant="primary"
            class="mt-2"
            :disabled="sending || !reply.trim()"
            data-testid="case-send"
            @click="send"
          >
            Send
          </b-button>
        </div>
      </div>

      <ModChatModal
        v-if="showChat && chatReady"
        :id="item.chatid"
        :pov="pov"
        @hidden="showChat = false"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { marked } from 'marked'
import DOMPurify from 'isomorphic-dompurify'
import { useRoute } from '#imports'
import api from '~/api'
import { useChatStore } from '~/stores/chat'
import { useMe } from '~/composables/useMe'
import { timeago } from '~/composables/useTimeFormat'

const route = useRoute()
const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)
const chatStore = useChatStore()
const { supportOrAdmin, mod, myid } = useMe()

const caseId = ref(
  route.params && route.params.id ? parseInt(route.params.id) || 0 : 0
)
const allowed = computed(() => !!(supportOrAdmin.value || mod.value))

const loading = ref(false)
const error = ref('')
const actionError = ref('')
const notfound = ref(false)

// One case.
const item = ref(null)
const run = ref(null)
const reply = ref('')
const draftText = ref(null)
const copied = ref(false)
const sending = ref(false)
const sendError = ref('')
const busy = ref(false)
const showChat = ref(false)
const chatReady = ref(false)
const pov = ref(0)

const brief = computed(() => (item.value ? item.value.brief : null) || null)

// "Referred 5 minutes ago by A about B (#1), from a Referral." Built here rather than in the
// template, where the formatter's line breaks decide the spacing between the parts.
const referredLine = computed(() => {
  const c = item.value
  if (!c) return ''
  let line = `Referred ${timeago(c.created)}`
  if (c.referrer) line += ` by ${c.referrer.displayname}`
  if (c.member) line += ` about ${c.member.displayname} (#${c.member.id})`
  if (c.source) line += `, from a ${c.source}`
  return line + '.'
})

const poolText = computed(() => {
  const pool = brief.value?.pool
  if (!pool) return ''
  const parts = []
  if (pool.fiveHour !== null && pool.fiveHour !== undefined) {
    parts.push(`${pool.fiveHour}% of the 5-hour allowance`)
  }
  if (pool.sevenDay !== null && pool.sevenDay !== undefined) {
    parts.push(`${pool.sevenDay}% of the weekly allowance`)
  }
  return parts.length
    ? `AI credit in use when this ran: ${parts.join(', ')}.`
    : ''
})

const tokensText = computed(() => {
  const n = (run.value?.input_tokens || 0) + (run.value?.output_tokens || 0)
  if (!n) return ''
  return n >= 1000
    ? `This first pass used ${(n / 1000).toFixed(1)}k tokens.`
    : `This first pass used ${n} tokens.`
})

function statusVariant(status) {
  return status === 'Open' ? 'warning' : 'success'
}

// The first pass can quote member text, so sanitise it as the helper page does.
// Nothing in it may load a third-party URL when the page opens, and every link opens safely.
const FORBID_TAGS = [
  'img',
  'picture',
  'source',
  'video',
  'audio',
  'iframe',
  'style',
]
const FORBID_ATTR = ['style', 'srcset']

function addLinkRel(node) {
  if (node.tagName === 'A') {
    node.setAttribute('rel', 'noopener noreferrer')
    node.setAttribute('target', '_blank')
  }
}

function renderMarkdown(text) {
  DOMPurify.addHook('afterSanitizeAttributes', addLinkRel)
  try {
    return DOMPurify.sanitize(marked(text || ''), { FORBID_TAGS, FORBID_ATTR })
  } finally {
    DOMPurify.removeHook('afterSanitizeAttributes')
  }
}

function errorStatus(e) {
  return e?.response?.status || e?.status || e?.statusCode || 0
}

async function loadCase() {
  loading.value = true
  error.value = ''
  notfound.value = false
  try {
    item.value = await apiInstance.supportcases.fetchCase(caseId.value)
  } catch (e) {
    item.value = null
    if (
      e?.response?.status === 404 ||
      e?.status === 404 ||
      e?.statusCode === 404
    ) {
      notfound.value = true
    } else {
      error.value = 'Could not load the case.'
    }
  } finally {
    loading.value = false
  }
  if (item.value?.viewer === 'Support' && item.value.firstpass_runid) {
    try {
      run.value = await apiInstance.supportai.fetchRun(
        item.value.firstpass_runid
      )
    } catch (e) {
      run.value = null
    }
  }
}

async function send() {
  const body = reply.value.trim()
  if (!body || sending.value) return
  sending.value = true
  sendError.value = ''
  let draftused
  if (item.value?.viewer === 'Support') {
    if (draftText.value === null) draftused = 'No'
    else draftused = body === draftText.value.trim() ? 'Unchanged' : 'Edited'
  }
  try {
    await apiInstance.supportcases.sendMessage(caseId.value, body, draftused)
    reply.value = ''
    draftText.value = null
    await loadCase()
  } catch (e) {
    const status = errorStatus(e)
    if (status === 429) {
      sendError.value =
        'You have sent a lot of replies. Please wait a little, then try again.'
    } else if (status === 409) {
      sendError.value = 'Another case for this chat is already open.'
    } else {
      sendError.value = 'Sorry, that reply was not sent. Please try again.'
    }
  } finally {
    sending.value = false
  }
}

async function setStatus(status) {
  busy.value = true
  actionError.value = ''
  try {
    await apiInstance.supportcases.setStatus(caseId.value, status)
    await loadCase()
  } catch (e) {
    actionError.value = 'Could not change the status.'
  } finally {
    busy.value = false
  }
}

function fillReplyFromDraft() {
  draftText.value = brief.value?.draft?.text || ''
  reply.value = draftText.value
}

async function copyDraft() {
  try {
    await navigator.clipboard.writeText(brief.value?.draft?.text || '')
    copied.value = true
  } catch (e) {
    copied.value = false
  }
}

async function viewChat() {
  chatReady.value = false
  actionError.value = ''
  showChat.value = true
  try {
    await chatStore.fetchChat(item.value.chatid)
    const chat = chatStore.byChatId(item.value.chatid)
    if (chat) {
      pov.value = typeof chat.user1 === 'object' ? chat.user1.id : chat.user1
      chatReady.value = true
    } else {
      showChat.value = false
      actionError.value = 'Could not open the chat.'
    }
  } catch (e) {
    showChat.value = false
    chatReady.value = false
    actionError.value = 'Could not open the chat.'
  }
}

// Opening a case link while logged out and then logging in must load the case.
watch(myid, (newVal, oldVal) => {
  if (!oldVal && newVal && allowed.value && caseId.value && !item.value) {
    loadCase()
  }
})

onMounted(() => {
  if (!allowed.value) return
  if (caseId.value) loadCase()
})
</script>

<style scoped lang="scss">
.case-row {
  border: 1px solid #dee2e6;
  border-radius: 4px;
  background: #fff;
  color: inherit;
}

.case-row__summary {
  overflow-wrap: anywhere;
}

.case-reason,
.reply__body,
.draft__text {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.draft__text {
  background: #f1f3f5;
  padding: 0.5rem;
  max-width: 100%;
  font-family: inherit;
}

.support-case {
  max-width: 100%;
  overflow-x: hidden;
}

.reply {
  max-width: 100%;
}

#case-reply {
  max-width: 100%;
  width: 100%;
}

.reply {
  border: 1px solid #dee2e6;
  border-radius: 4px;
}

.reply--referrer {
  background: #f8f9fa;
}

.reply--support {
  background: #eef6ff;
}
</style>
