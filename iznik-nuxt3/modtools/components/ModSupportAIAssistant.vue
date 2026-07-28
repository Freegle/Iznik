<template>
  <div class="log-analysis-container">
    <!-- Privacy notice. Unlike the old pseudonymising version, this helper works
         on real, de-anonymised member data, so the notice explains the legal basis
         (DPIA) and the responsibilities rather than any anonymisation measures. -->
    <b-modal
      v-model="showPrivacyModal"
      title="Privacy notice"
      ok-only
      ok-title="I understand"
      centered
    >
      <p>
        This tool uses AI to help you investigate support issues. It shows, and
        sends to the AI (Claude),
        <strong>real, non-anonymised member data</strong>
        - names, email addresses and chat content. There is no pseudonymisation:
        the AI sees the real values.
      </p>
      <ul class="mb-2">
        <li>
          Access is restricted to <strong>Support and Admin</strong> volunteers.
        </li>
        <li>
          This de-anonymised processing is covered by Freegle's
          <strong>Data Protection Impact Assessment (DPIA)</strong>.
        </li>
        <li>
          Use it only for genuine support, look at the minimum you need, and
          don't share what you see outside the support team.
        </li>
      </ul>
    </b-modal>

    <!-- Header -->
    <div class="log-analysis-header">
      <div
        class="d-flex align-items-center justify-content-between flex-wrap gap-2"
      >
        <div class="d-flex align-items-center flex-wrap">
          <strong>AI Support Helper</strong>
          <span v-if="totalCost > 0" class="session-cost ms-2">
            Session: ${{ totalCost.toFixed(4) }}
            <template
              v-if="totalTokens.inputTokens || totalTokens.outputTokens"
            >
              &middot; {{ formatTokenCounts(totalTokens) }}
            </template>
          </span>
          <div
            v-if="selectedUser"
            class="selected-user-chip d-flex align-items-center ms-3"
          >
            <span class="d-inline-flex align-items-baseline flex-wrap">
              <strong>{{ selectedUser.displayname || 'User' }}</strong>
              <small class="text-muted ms-1">(ID: {{ selectedUser.id }})</small>
              <span class="text-muted ms-1">{{ selectedUser.email }}</span>
            </span>
            <b-button
              variant="outline-secondary"
              size="sm"
              class="ms-2"
              @click="changeUser"
            >
              Change
            </b-button>
          </div>
        </div>
        <div class="d-flex align-items-center">
          <b-form-checkbox v-model="debugMode" switch size="sm" class="me-2">
            <small>Debug</small>
          </b-form-checkbox>
          <b-button
            variant="link"
            size="sm"
            class="p-0 text-info"
            @click="showPrivacyModal = true"
          >
            <span aria-hidden="true">&#9432;</span> Privacy
          </b-button>
        </div>
      </div>
    </div>

    <!-- Member selection (mandatory) -->
    <div v-if="!selectedUser" class="log-analysis-step p-3">
      <h6>Select a member to investigate</h6>
      <p class="text-muted small mb-2">
        Choose the member you want to investigate. The chat is unlocked once a
        member is selected.
      </p>
      <b-input-group class="mb-2">
        <b-form-input
          v-model="userSearch"
          placeholder="Search by email, name, or user ID"
          :disabled="searchingUser"
          autocapitalize="none"
          autocomplete="off"
          @keyup.enter.exact="searchUsers"
        />
        <b-button
          variant="primary"
          :disabled="searchingUser"
          @click="searchUsers"
        >
          <v-icon v-if="searchingUser" icon="sync" class="fa-spin" />
          <v-icon v-else icon="search" /> Search
        </b-button>
      </b-input-group>

      <div v-if="searchResults.length > 0" class="user-results mt-2">
        <div
          v-for="user in searchResults"
          :key="user.id"
          class="user-result-item p-2"
          @click="selectUser(user)"
        >
          <div class="d-flex align-items-center">
            <div class="flex-grow-1">
              <strong>{{ user.displayname || 'No name' }}</strong>
              <span class="text-muted ms-2">{{ user.email }}</span>
              <br />
              <small class="text-muted">
                ID: {{ user.id }}
                <span v-if="user.lastaccess">
                  | Last active: {{ formatDate(user.lastaccess) }}
                </span>
              </small>
            </div>
            <v-icon icon="chevron-right" />
          </div>
        </div>
      </div>

      <NoticeMessage v-if="noResults" class="mt-2">
        No users found matching "{{ userSearch }}".
      </NoticeMessage>
    </div>

    <!-- Chat area (only once a member has been selected) -->
    <template v-else>
      <!-- Debug Panel -->
      <div v-if="debugMode && debugLog.length > 0" class="debug-panel p-3">
        <h6 class="d-flex align-items-center justify-content-between">
          <span>Debug: Data Flow</span>
          <b-button variant="link" size="sm" @click="debugLog = []"
            >Clear</b-button
          >
        </h6>
        <div class="debug-entries">
          <div
            v-for="(entry, idx) in debugLog"
            :key="idx"
            class="debug-entry mb-2 p-2"
            :class="'debug-' + entry.type"
          >
            <div class="debug-header d-flex justify-content-between">
              <strong>{{ entry.label }}</strong>
              <small class="text-muted">{{ entry.timestamp }}</small>
            </div>
            <pre v-if="entry.data" class="debug-data mt-1 mb-0">{{
              formatDebugData(entry.data)
            }}</pre>
          </div>
        </div>
      </div>

      <!-- Device summary (recent devices) — kept visible during the whole
           conversation as reference context, not just before it starts. -->
      <div
        v-if="deviceSummary || loadingDevices || deviceError"
        class="device-summary log-analysis-step p-3"
      >
        <div class="d-flex align-items-center justify-content-between mb-2">
          <h6 class="mb-0">
            Recent devices
            <small class="text-muted fw-normal">(last 7 days)</small>
          </h6>
          <small v-if="deviceSummary?.lastaccess" class="text-muted">
            Last active: {{ formatLastSeen(deviceSummary.lastaccess) }}
          </small>
        </div>

        <div v-if="loadingDevices" class="text-muted small">
          <b-spinner small class="me-1" /> Loading devices&hellip;
        </div>
        <NoticeMessage
          v-else-if="deviceError"
          variant="warning"
          class="mb-0 small"
        >
          {{ deviceError }}
        </NoticeMessage>
        <div
          v-else-if="!deviceSummary?.devices?.length"
          class="text-muted small"
        >
          No device sessions found in the last 7 days.
        </div>
        <div v-else class="device-cards d-flex flex-wrap gap-2">
          <div
            v-for="(dev, i) in deviceSummary.devices"
            :key="i"
            class="device-card p-2"
          >
            <!-- Identity: browser/app + version, then OS, then up-to-date badge. -->
            <div class="d-flex align-items-center flex-wrap gap-1 mb-1">
              <v-icon
                v-if="!dev.isApp && dev.browserIcon"
                :icon="['fab', dev.browserIcon]"
                class="device-browser-icon"
                :title="dev.browser"
              />
              <v-icon
                :icon="dev.formIcon"
                class="text-secondary"
                :title="dev.isApp ? dev.formFactor + ' app' : dev.formFactor"
              />
              <!-- Keep the name and OS on a shared text baseline (they differ in
                   size), so the OS doesn't sit off-line from the browser name. -->
              <span class="d-inline-flex align-items-baseline gap-1 ms-1">
                <strong>{{ primaryLabel(dev) }}</strong>
                <span class="text-muted small">{{ dev.os }}</span>
              </span>
              <b-badge
                v-if="freshnessBadge(dev)"
                :variant="freshnessBadge(dev).variant"
                class="ms-1"
                :title="freshnessBadge(dev).title"
              >
                {{ freshnessBadge(dev).text }}
              </b-badge>
            </div>
            <!-- Window sizes used, on the (fixed) screen size. -->
            <div
              v-if="dev.windows?.length"
              class="device-pills d-flex flex-wrap align-items-center gap-1 mb-1"
            >
              <span
                v-for="(win, wi) in dev.windows"
                :key="wi"
                class="device-pill"
                :title="windowTitle(win)"
              >
                {{ win.w }}&times;{{ win.h }}
              </span>
              <span v-if="dev.screen" class="text-muted small ms-1">
                on {{ dev.screen.w }}&times;{{ dev.screen.h }}
              </span>
            </div>
            <div class="device-meta text-muted">
              {{ dev.sessions }} session{{ dev.sessions === 1 ? '' : 's' }}
              <span v-if="dev.lastSeen">
                &middot; seen {{ formatLastSeen(dev.lastSeen) }}</span
              >
            </div>
          </div>
        </div>
      </div>

      <!-- Initial query (before conversation starts) -->
      <div v-if="messages.length === 0" class="log-analysis-step p-3">
        <h6>What would you like to investigate?</h6>
        <b-form @submit.prevent="submitQuery">
          <b-form-textarea
            v-model="query"
            rows="3"
            max-rows="6"
            placeholder="e.g., 'Why are they getting errors?' or 'Show their recent login activity'"
            :disabled="isProcessing"
            @keydown.enter.exact.prevent="submitQuery"
          />
          <div class="d-flex justify-content-between align-items-center mt-2">
            <small v-if="query" class="text-muted">
              Press Enter to submit, Shift+Enter for new line
            </small>
            <b-button
              type="submit"
              variant="primary"
              :disabled="!query.trim() || isProcessing"
            >
              <b-spinner v-if="isProcessing" small />
              <span v-else>Analyze</span>
            </b-button>
          </div>
        </b-form>
      </div>

      <!-- Chat interface (after conversation starts) -->
      <template v-else>
        <!-- Chat messages -->
        <div ref="messagesContainer" class="log-analysis-messages p-3">
          <div
            v-for="(msg, idx) in messages"
            :key="idx"
            class="message-item mb-3"
            :class="{
              'message-user': msg.role === 'user',
              'message-assistant': msg.role === 'assistant',
            }"
          >
            <div class="message-header small text-muted mb-1">
              {{ msg.role === 'user' ? 'You' : 'AI Assistant' }}
            </div>
            <div class="message-content" v-html="formatMessageContent(msg)" />
            <div
              v-if="msg.role === 'assistant' && (msg.costUsd || msg.usage)"
              class="message-cost"
            >
              <span v-if="msg.costUsd">
                Cost: ${{ msg.costUsd.toFixed(4) }}
              </span>
              <span
                v-if="
                  msg.usage && (msg.usage.inputTokens || msg.usage.outputTokens)
                "
                class="ms-2"
              >
                {{ formatTokenCounts(msg.usage) }}
              </span>
            </div>
          </div>

          <!-- Processing transcript -->
          <div v-if="isProcessing" class="message-item message-assistant mb-3">
            <div
              class="message-header small text-muted mb-1 d-flex align-items-center"
            >
              <b-spinner small class="me-2" />
              <span>AI Assistant</span>
            </div>
            <div class="message-content">
              <ol v-if="transcript.length > 0" class="transcript-list mb-2">
                <li
                  v-for="(step, stepIdx) in transcript"
                  :key="stepIdx"
                  class="transcript-step"
                  :class="'transcript-' + step.type"
                >
                  {{ step.text }}
                </li>
              </ol>
              <p v-else class="text-muted mb-2">
                Starting investigation&hellip;
              </p>
              <b-button variant="outline-danger" size="sm" @click="cancelQuery">
                Cancel
              </b-button>
            </div>
          </div>
        </div>

        <!-- Follow-up input -->
        <div class="chat-input-area p-3">
          <b-form class="d-flex gap-2" @submit.prevent="submitQuery">
            <b-form-input
              v-model="query"
              placeholder="Ask a follow-up question..."
              :disabled="isProcessing"
              class="flex-grow-1"
              @keydown.enter.exact.prevent="submitQuery"
            />
            <b-button
              type="submit"
              variant="primary"
              :disabled="!query.trim() || isProcessing"
            >
              <b-spinner v-if="isProcessing" small />
              <span v-else>Send</span>
            </b-button>
          </b-form>
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue'
import { marked } from 'marked'
import DOMPurify from 'isomorphic-dompurify'
import { useUserStore } from '~/stores/user'
import { useAuthStore } from '~/stores/auth'

// AI support helper for Claude integration. Talks directly to the log
// analysis endpoint - the backend has its own moderator/admin auth and
// runs with real (non-anonymised) data since it is only reachable by
// authenticated support/mod staff.
const AI_SUPPORT_URL = 'http://ai-support-helper.localhost'

// UI state
const debugMode = ref(false)
const showPrivacyModal = ref(false)

// User search
const userSearch = ref('')
const searchingUser = ref(false)
const searchResults = ref([])
const noResults = ref(false)
const selectedUser = ref(null)

// Device summary (recent devices for the selected member)
const deviceSummary = ref(null)
const loadingDevices = ref(false)
const deviceError = ref('')

// Query input
const query = ref('')

// Processing
const isProcessing = ref(false)
const claudeSessionId = ref(null) // For Claude Code conversation continuity
const transcript = ref([]) // Running transcript of thinking/tool/status steps

// Conversation
const messages = ref([])
const debugLog = ref([])

// Template refs
const messagesContainer = ref(null)

const totalCost = computed(() => {
  return messages.value
    .filter((m) => m.role === 'assistant' && m.costUsd)
    .reduce((sum, m) => sum + m.costUsd, 0)
})

const totalTokens = computed(() => {
  return messages.value
    .filter((m) => m.role === 'assistant' && m.usage)
    .reduce(
      (acc, m) => {
        acc.inputTokens += m.usage.inputTokens || 0
        acc.outputTokens += m.usage.outputTokens || 0
        return acc
      },
      { inputTokens: 0, outputTokens: 0 }
    )
})

async function searchUsers() {
  if (!userSearch.value.trim()) return

  searchingUser.value = true
  searchResults.value = []
  noResults.value = false

  try {
    const userStore = useUserStore()
    userStore.clear()

    await userStore.searchUsers(userSearch.value.trim())

    // Get results and sort by last access
    searchResults.value = Object.values(userStore.list)
      .sort((a, b) => {
        return (
          new Date(b.lastaccess).getTime() - new Date(a.lastaccess).getTime()
        )
      })
      .slice(0, 10) // Limit to 10 results

    // Auto-select if only one result
    if (searchResults.value.length === 1) {
      selectUser(searchResults.value[0])
      return
    }

    noResults.value = searchResults.value.length === 0
  } catch (error) {
    console.error('User search error:', error)
    noResults.value = true
  } finally {
    searchingUser.value = false
  }
}

function selectUser(user) {
  selectedUser.value = user
  searchResults.value = []
  userSearch.value = ''
  // Fetch the device summary up front so support sees what the member is on.
  loadDeviceSummary(user.id)
  // Focus the query input after Vue updates the DOM
  nextTick(() => {
    const textarea = document.querySelector('.log-analysis-container textarea')
    if (textarea) {
      textarea.focus()
    }
  })
}

function changeUser() {
  // Go back to member selection - clears the selected member, the
  // conversation, and any session/debug state tied to it.
  selectedUser.value = null
  messages.value = []
  query.value = ''
  claudeSessionId.value = null
  transcript.value = []
  debugLog.value = []
  searchResults.value = []
  userSearch.value = ''
  deviceSummary.value = null
  deviceError.value = ''
}

// Fetch the recent-device summary for a member from the AI support helper.
// This is a deterministic (no-AI) endpoint, so it runs on select without cost.
async function loadDeviceSummary(userId) {
  deviceSummary.value = null
  deviceError.value = ''
  if (!userId) return
  loadingDevices.value = true
  try {
    const authStore = useAuthStore()
    const jwt = authStore.auth?.jwt
    const res = await fetch(
      `${AI_SUPPORT_URL}/api/device-summary?userId=${encodeURIComponent(
        userId
      )}`,
      { headers: { ...(jwt ? { Authorization: `Bearer ${jwt}` } : {}) } }
    )
    if (!res.ok) {
      deviceError.value =
        res.status === 403
          ? 'Not authorised to view device data.'
          : `Could not load devices (${res.status}).`
      return
    }
    deviceSummary.value = await res.json()
  } catch (e) {
    deviceError.value = 'Device summary service is not available.'
  } finally {
    loadingDevices.value = false
  }
}

// "Chrome 150" / "Safari 17" — browser name with its major version when known.
function browserLabel(dev) {
  return dev.browserVersion
    ? `${dev.browser} ${dev.browserVersion}`
    : dev.browser
}

// The bold identity: a web browser shows "Chrome 150"; the app shows
// "App 3.2.28" (or just "App" until the app reports its version), so the app
// reads the same way as a browser — the thing, then its version, then the OS.
function primaryLabel(dev) {
  if (!dev.isApp) return browserLabel(dev)
  return dev.appVersion ? `App ${dev.appVersion}` : 'App'
}

// Tooltip for a window-size pill.
function windowTitle(win) {
  const s = `${win.sessions} session${win.sessions === 1 ? '' : 's'}`
  return `Window ${win.w}×${win.h} — ${s}`
}

// "Are they up to date?" badge, or null to show nothing. We can only judge the
// APP by version (needs an old app updating), WEB by build-date age (needs a
// refresh). `dev.freshness` is computed server-side; 'unknown' shows nothing
// rather than a misleading badge (e.g. before the frontend logs version/build).
function freshnessBadge(dev) {
  if (dev.freshness === 'current') {
    return {
      variant: 'success',
      text: 'up to date',
      title: dev.isApp
        ? 'Running the current app version.'
        : 'Loaded a recent web build.',
    }
  }
  if (dev.freshness === 'stale') {
    if (dev.isApp) {
      const cur = deviceSummary.value?.currentVersion
      return {
        variant: 'warning',
        text: 'update the app',
        title: `On v${dev.appVersion || '?'}${
          cur ? `; current is v${cur}` : ''
        } — they need to update the app.`,
      }
    }
    return {
      variant: 'warning',
      text: 'out of date',
      title: 'Running an old web build — a page refresh will update them.',
    }
  }
  return null
}

// Human "x ago" for an ISO timestamp (session_start ts or DB lastaccess).
function formatLastSeen(ts) {
  if (!ts) return ''
  const d = new Date(ts)
  if (Number.isNaN(d.getTime())) return ''
  const mins = Math.round((Date.now() - d.getTime()) / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.round(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  const days = Math.round(hrs / 24)
  if (days < 7) return `${days}d ago`
  return d.toLocaleDateString()
}

function scrollToBottom() {
  nextTick(() => {
    const container = messagesContainer.value
    if (container) {
      container.scrollTop = container.scrollHeight
    }
  })
}

function addDebugEntry(type, label, data) {
  if (debugMode.value) {
    debugLog.value.push({
      type,
      label,
      data,
      timestamp: new Date().toLocaleTimeString(),
    })
  }
}

async function submitQuery() {
  if (!query.value.trim() || isProcessing.value || !selectedUser.value) return

  const queryText = query.value
  query.value = ''

  messages.value.push({
    role: 'user',
    content: queryText,
  })
  scrollToBottom()

  isProcessing.value = true
  transcript.value = []

  try {
    const result = await queryLogsForUser(queryText)

    messages.value.push({
      role: 'assistant',
      content: result.analysis,
      costUsd: result.costUsd,
      usage: result.usage,
    })
    scrollToBottom()
  } catch (error) {
    console.error('Query error:', error)
    addDebugEntry('error', 'Query Error', { message: error.message })
    messages.value.push({
      role: 'assistant',
      content: `Error: ${error.message}`,
    })
    scrollToBottom()
  } finally {
    isProcessing.value = false
    transcript.value = []
  }
}

async function queryLogsForUser(userQuery) {
  try {
    const requestBody = {
      query: userQuery,
      userId: selectedUser.value?.id || 0,
    }

    // Include Claude session ID for conversation continuity
    if (claudeSessionId.value) {
      requestBody.claudeSessionId = claudeSessionId.value
    }

    addDebugEntry('request', 'Log Analysis Request', requestBody)

    // Forward the moderator's JWT so the helper can verify they are an
    // authenticated mod/support before running any log analysis.
    const authStore = useAuthStore()
    const jwt = authStore.auth?.jwt

    // Use SSE for streaming progress updates
    const response = await fetch(`${AI_SUPPORT_URL}/api/log-analysis`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'text/event-stream',
        ...(jwt ? { Authorization: `Bearer ${jwt}` } : {}),
      },
      body: JSON.stringify(requestBody),
    })

    if (!response.ok) {
      if (response.status === 404) {
        return {
          analysis:
            '**Log Analysis Service**\n\nThe AI-powered log analysis service is not yet available.',
          costUsd: 0,
        }
      }
      const errorData = await response.json().catch(() => ({}))
      if (response.status === 410 || errorData.error === 'SESSION_EXPIRED') {
        claudeSessionId.value = null
        return {
          analysis:
            '**Session Expired**\n\nPlease ask your question again to start a new session.',
          costUsd: null,
        }
      }
      throw new Error(errorData.message || 'Failed to query logs')
    }

    // Read SSE stream for progress updates
    const reader = response.body.getReader()
    const decoder = new TextDecoder()
    let buffer = ''
    let resultData = null

    while (true) {
      const { done, value } = await reader.read()
      if (done) break

      buffer += decoder.decode(value, { stream: true })
      const lines = buffer.split('\n')
      buffer = lines.pop() // Keep incomplete line in buffer

      for (const line of lines) {
        if (line.startsWith('data: ')) {
          try {
            const event = JSON.parse(line.slice(6))
            if (event.type === 'result') {
              resultData = event
              // The transcript is only for "while we're working" - once we
              // have the final answer it's replaced by the assistant message.
              transcript.value = []
            } else if (event.type === 'error') {
              throw new Error(event.message)
            } else if (
              event.type === 'thinking' ||
              event.type === 'tool' ||
              event.type === 'status'
            ) {
              transcript.value.push({ type: event.type, text: event.message })
              scrollToBottom()
            }
          } catch (e) {
            if (e.message !== 'Unexpected end of JSON input') {
              throw e
            }
          }
        }
      }
    }

    if (!resultData) {
      return {
        analysis: 'No response received from analysis service.',
        costUsd: 0,
      }
    }

    // Save Claude session ID for conversation continuity
    if (resultData.claudeSessionId) {
      claudeSessionId.value = resultData.claudeSessionId
    }

    addDebugEntry('response', 'Log Analysis Response', {
      analysisLength: resultData.analysis?.length || 0,
      costUsd: resultData.costUsd,
      usage: resultData.usage,
      claudeSessionId: resultData.claudeSessionId,
      isNewSession: resultData.isNewSession,
    })

    return {
      analysis: resultData.analysis || 'No analysis available.',
      costUsd: resultData.costUsd,
      usage: resultData.usage,
    }
  } catch (error) {
    if (error.message.includes('fetch')) {
      const userInfo = selectedUser.value
        ? `\n\nUser ID for manual search: **${selectedUser.value.id}**`
        : ''
      return {
        analysis: `**Log Analysis Service**\n\nThe AI log analysis service is not currently running.${userInfo}`,
        costUsd: null,
      }
    }
    return {
      analysis: `Unable to query logs: ${error.message}`,
      costUsd: null,
    }
  }
}

function formatMessageContent(msg) {
  if (!msg.content) return ''
  // The answer is Claude's markdown, but it can quote member-supplied data
  // (chat text, display names, post bodies) verbatim. Sanitise the rendered
  // HTML so a member cannot plant <script>/<img onerror> to run in a support
  // volunteer's browser and steal their JWT.
  return DOMPurify.sanitize(marked(msg.content))
}

function formatTokenCounts(usage) {
  if (!usage) return ''
  const input = usage.inputTokens || 0
  const output = usage.outputTokens || 0
  if (!input && !output) return ''
  return `${input.toLocaleString('en-US')} in / ${output.toLocaleString(
    'en-US'
  )} out tokens`
}

function formatDebugData(data) {
  if (typeof data === 'string') return data
  return JSON.stringify(data, null, 2)
}

function cancelQuery() {
  isProcessing.value = false
  transcript.value = []
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  return date.toLocaleDateString() + ' ' + date.toLocaleTimeString()
}
</script>

<style scoped lang="scss">
.log-analysis-container {
  border: 1px solid #dee2e6;
  background: #f8f9fa;
}

.log-analysis-header {
  padding: 0.75rem 1rem;
  background: #ffffff;
  border-bottom: 1px solid #dee2e6;
}

.session-cost {
  font-size: 0.8rem;
  color: #757575;
  font-weight: normal;
}

.session-tokens {
  color: #9e9e9e;
}

.selected-user-chip {
  font-size: 0.85rem;
  padding: 0.15rem 0.5rem;
  background: #e9ecef;
  border-radius: 1rem;
}

.log-analysis-step {
  background: #ffffff;
  border-bottom: 1px solid #dee2e6;
}

.user-results {
  max-height: 300px;
  overflow-y: auto;
}

.user-result-item {
  border: 1px solid #dee2e6;
  cursor: pointer;
  transition: background-color 0.15s;

  &:hover {
    background-color: #e9ecef;
  }
}

.log-analysis-messages {
  background: #f5f5f5;
  min-height: 200px;
  max-height: 500px;
  overflow-y: auto;
  flex: 1;
}

.message-item {
  padding: 0.75rem 1rem;
  border-radius: 0.5rem;
  max-width: 85%;
  background: #ffffff;
}

.message-user {
  margin-left: auto;
  border: 2px solid #28a745;
  border-bottom-right-radius: 0.125rem;
}

.message-user .message-header {
  color: #28a745;
}

.message-assistant {
  margin-right: auto;
  border: 2px solid #007bff;
  border-bottom-left-radius: 0.125rem;
}

.message-assistant .message-header {
  color: #007bff;
}

.message-cost {
  margin-top: 0.5rem;
  font-size: 0.7rem;
  color: #9e9e9e;
  text-align: right;
}

.chat-input-area {
  background: #ffffff;
  border-top: 1px solid #dee2e6;
}

.message-content {
  :deep(p) {
    margin-bottom: 0.5rem;

    &:last-child {
      margin-bottom: 0;
    }
  }

  :deep(ul),
  :deep(ol) {
    margin-bottom: 0.5rem;
  }

  :deep(code) {
    background: #e9ecef;
    padding: 0.1rem 0.3rem;
    font-size: 0.85em;
  }
}

/* Running transcript ("thinking" steps while processing) */
.transcript-list {
  margin: 0;
  padding-left: 1.25rem;
  font-size: 0.85em;
  color: #495057;
}

.transcript-step {
  padding: 0.1rem 0;
}

.transcript-step.transcript-tool {
  color: #6f42c1;
  font-family: monospace;
}

.transcript-step.transcript-thinking {
  color: #495057;
  font-style: italic;
}

.transcript-step.transcript-status {
  color: #6c757d;
}

/* Debug panel styles */
.debug-panel {
  background: #263238;
  color: #eceff1;
  border-bottom: 1px solid #dee2e6;
  max-height: 300px;
  overflow-y: auto;

  h6 {
    color: #80cbc4;
    margin-bottom: 0.5rem;
  }
}

.debug-entries {
  font-family: monospace;
  font-size: 0.85em;
}

.debug-entry {
  border-radius: 4px;

  &.debug-request {
    background: #1b5e20;
    border-left: 3px solid #4caf50;
  }

  &.debug-response {
    background: #0d47a1;
    border-left: 3px solid #2196f3;
  }

  &.debug-error {
    background: #b71c1c;
    border-left: 3px solid #f44336;
  }
}

.debug-header {
  font-size: 0.9em;
}

.debug-data {
  background: rgba(0, 0, 0, 0.2);
  padding: 0.5rem;
  border-radius: 3px;
  white-space: pre-wrap;
  word-break: break-all;
  max-height: 150px;
  overflow-y: auto;
  color: #b0bec5;
}

/* Device summary panel */
.device-summary h6 {
  font-size: 0.95rem;
}

.device-card {
  border: 1px solid #dee2e6;
  border-radius: 0.5rem;
  background: #ffffff;
  min-width: 210px;
  font-size: 0.85rem;
}

.device-browser-icon {
  font-size: 1.05rem;
  color: #495057;
}

.device-screen {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.device-pill {
  display: inline-block;
  padding: 0.1rem 0.45rem;
  border-radius: 1rem;
  background: #e7f1ff;
  color: #0d6efd;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.device-meta {
  font-size: 0.75rem;
}

.device-unavailable {
  border-top: 1px dashed #dee2e6;
  padding-top: 0.4rem;
}

.gap-1 {
  gap: 0.25rem;
}

.gap-2 {
  gap: 0.5rem;
}

.gap-3 {
  gap: 1rem;
}
</style>
